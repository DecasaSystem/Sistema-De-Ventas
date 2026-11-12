<?php

namespace App\Services\Facturacion;

use App\Models\Cliente;
use App\Models\FacturaElectronica;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\Usuario;
use App\Services\Alegra\ClienteAlegra;
use App\Services\Alegra\RespuestaAlegra;
use App\Services\Alegra\SinRespuestaDeAlegra;
use App\Support\Esquema;
use App\Support\ReglasFacturacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Facturar en Alegra: la única puerta (el botón del facturador y la tarea
 * automática pasan por aquí).
 *
 * Lo más delicado es no facturar dos veces. Una factura emitida ante la DIAN
 * no se borra (solo se anula con nota crédito) y Alegra no tiene llave de
 * idempotencia, así que:
 *  1. Dentro de una transacción con lockForUpdate se marca la fila "enviando"
 *     ANTES de llamar a Alegra: un segundo clic (o dos facturadores a la vez)
 *     encuentra la fila tomada y se frena.
 *  2. En las observaciones de la factura va una marca ([DECASA-ORDEN-123]).
 *     Si Alegra no respondió, la fila queda "incierta" y el siguiente intento
 *     primero busca esa marca en Alegra: si la factura sí se creó, se adopta
 *     en vez de crear otra.
 *  3. Si Alegra creó un borrador (rechazo de la DIAN, modo borrador), el
 *     siguiente intento lo borra y lo vuelve a crear con los datos corregidos.
 *     Un borrador no es legal: borrarlo no deja huecos en la numeración DIAN.
 */
class EmitirFactura
{
    /** Minutos que una fila "enviando" se considera de otra persona. */
    private const MINUTOS_TOMADA = 3;

    public function __construct(private ClienteAlegra $alegra) {}

    /**
     * @param string[] $aceptadas códigos de las advertencias que la persona revisó
     * @throws NoSePuedeFacturar
     */
    public function facturar(Orden $orden, ?Pago $pago, ?Usuario $usuario, array $aceptadas = [], bool $automatico = false, float $retencion = 0): FacturaElectronica
    {
        // Lo ya facturado se dice primero: después de emitir, los pagos quedan
        // marcados y la revisión hablaría de "facturado a mano" en vez de eso.
        $existente = FacturaElectronica::where('clave', FacturaElectronica::claveDe($orden, $pago))->first();
        if ($existente && $existente->estaHecha()) {
            throw new NoSePuedeFacturar('Esta ya está facturada' . ($existente->numero ? " ({$existente->numero})" : '') . '.', 409);
        }

        $armado = ArmarFactura::de($orden, $pago, $retencion);

        if (! $armado['se_puede']) {
            $bloqueos = array_values(array_filter($armado['alertas'], fn ($a) => $a['tipo'] === 'bloqueo'));
            throw new NoSePuedeFacturar($bloqueos[0]['mensaje'] ?? 'No se puede facturar todavía.', 422, $armado['alertas']);
        }
        $sinAceptar = array_values(array_diff($armado['advertencias'], $aceptadas));
        if ($sinAceptar) {
            throw new NoSePuedeFacturar(
                $automatico ? 'Tiene advertencias: la revisa el facturador.' : 'Hay advertencias sin revisar: márcalas como revisadas.',
                422, $armado['alertas'], $sinAceptar,
            );
        }

        $factura = $this->tomar($armado, $usuario);

        // Los muebles que se encontraron en Alegra por el nombre quedan
        // emparejados: la próxima factura no tiene que volver a buscarlos.
        foreach ($armado['lineas'] as $l) {
            $m = $l['alegra_item'] ?? null;
            if ($m && $m['origen'] === 'automatico' && $m['id']) {
                \App\Services\Alegra\ProductosEnAlegra::guardar($m['producto_id'], $m['id'], $m['nombre'], 'automatico', $usuario?->id);
            }
        }
        // Los pagos que se registrarán en Alegra cuando la factura exista (los
        // que ya quedaron registrados en un intento anterior se conservan).
        $yaRegistrados = collect((array) $factura->pagos_alegra)->where('estado', 'registrado')->keyBy('pago_id');
        $factura->update(['pagos_alegra' => array_values(array_map(
            fn ($p) => $yaRegistrados[$p['pago_id']] ?? ['pago_id' => $p['pago_id'], 'monto' => $p['amount'], 'estado' => 'pendiente', 'envio' => $p],
            $armado['pagos_alegra'],
        ))]);

        try {
            if ($adoptada = $this->resolverIntentoAnterior($factura)) {
                return $adoptada;
            }

            $contactoId = $this->contacto($orden->cliente, $armado);
            $envio = $armado['factura'] + ['client' => ['id' => $contactoId]];
            $factura->update(['envio' => $envio, 'alegra_contacto_id' => $contactoId]);

            $res = $this->alegra->crearFactura($envio);

            return $this->guardarRespuesta($factura, $res, $armado);
        } catch (SinRespuestaDeAlegra $e) {
            $factura->update(['estado' => 'incierta', 'error' => $e->getMessage()
                . ' No se sabe si Alegra la creó: al volver a intentar, el sistema primero la busca allá.']);

            return $factura->fresh();
        } catch (NoSePuedeFacturar $e) {
            $factura->update(['estado' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[facturacion] error inesperado', ['clave' => $factura->clave, 'error' => $e->getMessage()]);
            $factura->update(['estado' => 'error', 'error' => 'Error inesperado: ' . $e->getMessage()]);

            return $factura->fresh();
        }
    }

    /** Toma la fila (o la crea) para que nadie más facture lo mismo a la vez. */
    private function tomar(array $armado, ?Usuario $usuario): FacturaElectronica
    {
        return DB::transaction(function () use ($armado, $usuario) {
            $f = FacturaElectronica::where('clave', $armado['clave'])->lockForUpdate()->first();

            if ($f && $f->estaHecha()) {
                throw new NoSePuedeFacturar('Esta ya está facturada' . ($f->numero ? " ({$f->numero})" : '') . '.', 409);
            }
            if ($f && $f->estado === 'enviando' && $f->updated_at?->gt(now()->subMinutes(self::MINUTOS_TOMADA))) {
                throw new NoSePuedeFacturar('Otra persona la está facturando en este momento. Espera un momento y actualiza.', 409);
            }

            $datos = [
                'orden_id'      => $armado['orden_id'],
                'pago_id'       => $armado['pago_id'],
                'tipo'          => 'factura',
                'estado'        => 'enviando',
                'modo'          => $armado['modo'],
                'base'          => $armado['totales']['base'],
                'iva'           => $armado['totales']['iva'],
                'total'         => $armado['totales']['total'],
                // Lo que valía la orden entera: "Diferencias" compara contra eso
                // (la factura puede ser menos si la restauración no se factura).
                'valor_sistema' => $armado['totales']['valor_orden'] ?? $armado['totales']['valor_sistema'],
                'usuario_id'    => $usuario?->id,
                'advertencias_aceptadas' => $armado['advertencias'],
                'retencion_cliente' => $armado['totales']['retencion'] > 0 ? $armado['totales']['retencion'] : null,
                'error'         => null,
            ];

            if ($f) {
                // Fuerza updated_at aunque nada más cambie: "enviando" se mide con eso.
                $f->fill($datos);
                $f->updated_at = now();
                $f->save();

                return $f;
            }

            return FacturaElectronica::create(['clave' => $armado['clave']] + $datos);
        });
    }

    /**
     * Lo que quedó de un intento anterior. Si la factura en realidad sí salió,
     * se adopta y se devuelve; si quedó en borrador, se borra para hacerla bien.
     */
    private function resolverIntentoAnterior(FacturaElectronica $f): ?FacturaElectronica
    {
        $id = $f->alegra_id;

        // Un intento anterior llegó a mandar la factura (el contacto solo se
        // guarda justo antes) pero no quedó su id: buscarla por la marca antes
        // de crear otra.
        if (! $id && $f->alegra_contacto_id) {
            $id = $this->buscarPorMarca($f);
        }
        if (! $id) return null;

        $res = $this->alegra->verFactura($id);
        if ($res->status === 404) {
            $f->update(['alegra_id' => null]);

            return null;
        }
        if (! $res->ok) {
            throw new NoSePuedeFacturar('No se pudo revisar en Alegra la factura anterior (' . $res->error . '). Intenta de nuevo.', 502);
        }

        $estado = strtolower((string) ($res->json['status'] ?? ''));
        if ($estado === 'draft') {
            $borrar = $this->alegra->borrarFactura($id);
            if (! $borrar->ok && $borrar->status !== 404) {
                throw new NoSePuedeFacturar('Alegra no dejó borrar el borrador anterior (' . $borrar->error . ').', 502);
            }
            $f->update(['alegra_id' => null]);

            return null;
        }

        // Ya no es borrador: sí quedó hecha. Se adopta.
        return $this->guardarRespuesta($f, new RespuestaAlegra(true, 200, $res->json, null), null);
    }

    /** Una factura del cliente con la marca de esta orden/pago, creada en Alegra. */
    private function buscarPorMarca(FacturaElectronica $f): ?string
    {
        $marca = $f->pago_id ? "[DECASA-PAGO-{$f->pago_id}]" : "[DECASA-ORDEN-{$f->orden_id}]";
        $fechas = array_unique(array_filter([
            $f->envio['date'] ?? null,
            now('America/Bogota')->toDateString(),
        ]));
        foreach ($fechas as $fecha) {
            foreach ($this->alegra->facturasDelCliente($f->alegra_contacto_id, $fecha) as $inv) {
                if (str_contains((string) ($inv['observations'] ?? ''), $marca) && ! empty($inv['id'])) {
                    return (string) $inv['id'];
                }
            }
        }

        return null;
    }

    /** El id del contacto en Alegra: el guardado, uno con la misma identificación o uno nuevo. */
    private function contacto(?Cliente $cliente, array $armado): string
    {
        $datos = $armado['cliente'];
        // "Consumidor final": el contacto de Alegra escogido en Ajustes, tal cual.
        if ($datos['consumidor_final']) return (string) $datos['alegra_contacto_id'];

        $payload = array_filter($datos['alegra'], fn ($v) => $v !== null);

        $id = $datos['alegra_contacto_id'] ? (string) $datos['alegra_contacto_id'] : null;
        if (! $id) {
            $documento = $datos['documento'];
            foreach ($this->alegra->buscarContactos($documento) as $c) {
                $ident = $c['identificationObject']['number'] ?? $c['identification'] ?? null;
                if ($ident !== null && preg_replace('/\D/', '', (string) $ident) === preg_replace('/\D/', '', $documento) && ! empty($c['id'])) {
                    $id = (string) $c['id'];
                    $this->recordarContacto($cliente, $id);
                    break;
                }
            }
        }

        // Ya existe en Alegra: se le ponen los datos de hoy. Si el facturador
        // corrigió el correo o la dirección en la revisión, la factura no puede
        // salir con lo viejo de allá (la DIAN se la manda a ese correo).
        if ($id) {
            $res = $this->alegra->put("contacts/{$id}", $payload);
            if ($res->ok) return $id;
            if ($res->status !== 404) {
                throw new NoSePuedeFacturar('Alegra no aceptó los datos del cliente: ' . ($res->error ?? 'sin detalle') . '. Revisa los datos del cliente.', 422);
            }
            // Lo borraron en Alegra: se crea de nuevo.
            $this->recordarContacto($cliente, null);
        }

        $res = $this->alegra->crearContacto($payload);
        if (! $res->ok || empty($res->json['id'])) {
            throw new NoSePuedeFacturar('Alegra no aceptó el cliente: ' . ($res->error ?? 'sin detalle') . '. Revisa los datos del cliente.', 422);
        }
        $this->recordarContacto($cliente, (string) $res->json['id']);

        return (string) $res->json['id'];
    }

    private function recordarContacto(?Cliente $cliente, ?string $id): void
    {
        if ($cliente && Esquema::columna('clientes', 'alegra_contacto_id')) {
            $cliente->forceFill(['alegra_contacto_id' => $id])->save();
        }
    }

    private function guardarRespuesta(FacturaElectronica $f, RespuestaAlegra $res, ?array $armado): FacturaElectronica
    {
        $json = is_array($res->json) ? $res->json : [];
        $yaEstabaEmitida = in_array($f->estado, ['emitida', 'pendiente_dian'], true);

        if (! $res->ok) {
            // Con generateStamp y un rechazo, Alegra crea la factura en borrador y
            // devuelve el error junto con ella.
            $creada = self::facturaDentro($json);
            $f->update([
                'estado'    => $creada ? 'rechazada' : 'error',
                'alegra_id' => $creada['id'] ?? $f->alegra_id,
                'respuesta' => $json,
                'error'     => $res->error ?? 'Alegra no la aceptó.',
            ]);
            $this->avisarSiFalla($f, $armado);

            return $f->fresh();
        }

        $estadoDian = self::estadoDian($json);
        $esBorrador = strtolower((string) ($json['status'] ?? '')) === 'draft';
        $estado = match (true) {
            $esBorrador                                => ReglasFacturacion::emite() && $f->modo === 'emitir' ? 'rechazada' : 'borrador_alegra',
            $estadoDian && str_contains($estadoDian, 'REJECTED') => 'rechazada',
            $estadoDian && (str_contains($estadoDian, 'WAITING') || $estadoDian === 'PENDING') => 'pendiente_dian',
            default                                    => 'emitida',
        };

        $f->update([
            'estado'      => $estado,
            'alegra_id'   => (string) ($json['id'] ?? $f->alegra_id),
            'numero'      => self::numero($json) ?? $f->numero,
            'cufe'        => $json['stamp']['cufe'] ?? $json['cufe'] ?? $f->cufe,
            'estado_dian' => $estadoDian ?? $f->estado_dian,
            'total'       => isset($json['total']) && is_numeric($json['total']) ? (float) $json['total'] : $f->total,
            'respuesta'   => $json,
            'error'       => $estado === 'rechazada' ? ('La DIAN la rechazó: ' . ($json['stamp']['errors'][0] ?? $json['stamp']['message'] ?? 'revisa en Alegra')) : null,
            'emitida_at'  => in_array($estado, ['emitida', 'pendiente_dian'], true) ? ($f->emitida_at ?? now()) : $f->emitida_at,
        ]);

        if (in_array($estado, ['emitida', 'pendiente_dian'], true)) {
            $this->despuesDeEmitir($f, ! $yaEstabaEmitida);
        }

        return $f->fresh();
    }

    /** PDF, correo al cliente y la marca vieja de "facturado" en los pagos. */
    private function despuesDeEmitir(FacturaElectronica $f, bool $primeraVez = true): void
    {
        try {
            $ver = $this->alegra->verFactura($f->alegra_id, ['pdf']);
            if ($ver->ok && ($pdf = self::pdf($ver->json))) {
                $f->update(['pdf_url' => mb_substr($pdf, 0, 500)]);
            }
        } catch (\Throwable $e) {
            Log::info('[facturacion] sin PDF por ahora', ['id' => $f->alegra_id, 'error' => $e->getMessage()]);
        }

        $this->registrarPagos($f);

        // El correo, una sola vez (al pasar de "DIAN validando" a "emitida" no se repite).
        if ($primeraVez && ReglasFacturacion::get('enviar_correo')) {
            $correo = $f->orden?->cliente?->email;
            if ($correo) {
                try {
                    $this->alegra->enviarPorCorreo($f->alegra_id, [$correo]);
                } catch (\Throwable $e) {
                    Log::info('[facturacion] no se mandó el correo', ['id' => $f->alegra_id, 'error' => $e->getMessage()]);
                }
            }
        }

        // OJO: ya NO se marcan los pagos como "facturados". Esa marca (Tomar →
        // Marcar facturado, en el detalle de la orden) quiere decir que el
        // facturador ya digitó el pago en Alegra como recibo (anticipo de
        // clientes). Marcarlos al emitir escondía de su lista un recibo que
        // todavía no había digitado (contador, 2026-11-11).
    }

    /**
     * Registra en Alegra los pagos del cliente contra la factura ya creada.
     *
     * Van aparte (no dentro de la factura) y nunca suman más que el total que
     * dice Alegra: si por centavos de redondeo la factura quedó en
     * $3.450.999,99 y los pagos suman $3.451.000, el último se recorta. Cada
     * uno lleva [DECASA-PAGO-ID] en las observaciones.
     *
     * Un pago sin respuesta queda "incierto" y no se reintenta solo (podría
     * quedar dos veces): se revisa en Alegra y se reintenta con el botón.
     *
     * @param bool $incluirInciertos el facturador confirmó que no quedó en Alegra
     */
    public function registrarPagos(FacturaElectronica $f, bool $incluirInciertos = false): FacturaElectronica
    {
        $plan = (array) $f->pagos_alegra;
        if (! $plan || ! $f->alegra_id || ! in_array($f->estado, ['emitida', 'pendiente_dian'], true)) return $f;

        $total = (float) $f->total;
        $yaVa  = collect($plan)->where('estado', 'registrado')->sum('monto_registrado');

        foreach ($plan as $i => $p) {
            if (($p['estado'] ?? '') === 'registrado') continue;
            if (($p['estado'] ?? '') === 'incierto' && ! $incluirInciertos) continue;

            $monto = round(min((float) $p['monto'], max(0, $total - $yaVa)), 2);
            if ($monto < 0.01) {
                $plan[$i] = ['estado' => 'registrado', 'monto_registrado' => 0, 'nota' => 'No hizo falta: la factura ya estaba cubierta.'] + $p;
                continue;
            }

            $envio = $p['envio'] + [
                'client'   => ['id' => $f->alegra_contacto_id],
                'invoices' => [['id' => $f->alegra_id, 'amount' => $monto]],
            ];
            $envio['amount'] = $monto;
            unset($envio['pago_id']);

            try {
                $res = $this->alegra->post('payments', $envio);
            } catch (SinRespuestaDeAlegra $e) {
                $plan[$i] = ['estado' => 'incierto', 'error' => 'Alegra no respondió: revisa en Alegra si quedó el pago antes de reintentar.'] + $p;
                continue;
            }

            if ($res->ok) {
                $plan[$i] = ['estado' => 'registrado', 'monto_registrado' => $monto,
                    'alegra_pago_id' => isset($res->json['id']) ? (string) $res->json['id'] : null, 'error' => null] + $p;
                $yaVa += $monto;
            } else {
                $plan[$i] = ['estado' => 'error', 'error' => $res->error] + $p;
            }
        }

        $f->update(['pagos_alegra' => array_values($plan)]);

        return $f->fresh();
    }

    /** Vuelve a preguntarle a Alegra cómo va (pendiente de la DIAN, incierta). */
    public function actualizar(FacturaElectronica $f): FacturaElectronica
    {
        if (! $f->alegra_id && $f->estado === 'incierta' && $f->alegra_contacto_id) {
            $id = $this->buscarPorMarca($f);
            if (! $id) {
                // No quedó en Alegra: pasa a "No salió" y se puede volver a facturar
                // (el reintento igual la busca otra vez por la marca antes de crear).
                $f->update(['estado' => 'error', 'error' => 'Alegra no respondió y la factura no quedó allá: se puede volver a facturar.']);

                return $f->fresh();
            }
            $f->update(['alegra_id' => $id]);
        }
        if (! $f->alegra_id) return $f;

        $res = $this->alegra->verFactura($f->alegra_id, ['pdf']);
        if ($res->status === 404) {
            $f->update(['estado' => 'error', 'alegra_id' => null, 'error' => 'Ya no está en Alegra (la borraron allá).']);

            return $f->fresh();
        }
        if (! $res->ok) {
            throw new NoSePuedeFacturar('Alegra no respondió bien: ' . $res->error, 502);
        }
        $f = $this->guardarRespuesta($f, $res, null);
        if (($pdf = self::pdf($res->json)) && ! $f->pdf_url) $f->update(['pdf_url' => mb_substr($pdf, 0, 500)]);

        return $f->fresh();
    }

    private function avisarSiFalla(FacturaElectronica $f, ?array $armado): void
    {
        // Solo si la hizo la tarea automática: quien tocó el botón ya lo está viendo.
        if ($f->usuario_id) return;
        try {
            foreach (\App\Services\AvisoFacturacion::destinatarios() as $d) {
                \App\Services\NotificacionService::crear(
                    tipo: 'factura_electronica',
                    titulo: 'No salió una factura electrónica',
                    mensaje: 'Orden ' . ($armado['referencia'] ?? $f->orden_id) . ': ' . $f->error,
                    datos: ['orden_id' => $f->orden_id, 'factura_id' => $f->id, 'pago_id' => $f->pago_id],
                    usuarioId: $d->id,
                );
            }
        } catch (\Throwable) {
        }
    }

    // ── Leer la respuesta de Alegra (con cuidado: no todo está documentado) ──

    public static function facturaDentro(array $json): ?array
    {
        foreach (['invoice', 'data', 'object'] as $k) {
            if (isset($json[$k]['id'])) return $json[$k];
        }

        return isset($json['id'], $json['status']) ? $json : null;
    }

    public static function estadoDian(array $json): ?string
    {
        $s = $json['stamp'] ?? null;
        $v = is_array($s) ? ($s['legalStatus'] ?? $s['status'] ?? null) : null;

        return $v ? strtoupper((string) $v) : null;
    }

    public static function numero(array $json): ?string
    {
        $nt = $json['numberTemplate'] ?? null;
        if (is_array($nt) && array_is_list($nt)) $nt = $nt[0] ?? null;
        if (is_array($nt)) {
            if (! empty($nt['fullNumber'])) return (string) $nt['fullNumber'];
            if (isset($nt['number'])) return ($nt['prefix'] ?? '') . $nt['number'];
        }

        return isset($json['number']) ? (string) $json['number'] : null;
    }

    public static function pdf($json): ?string
    {
        if (! is_array($json)) return null;
        foreach (['pdf', 'pdfUrl', 'urlPdf'] as $k) {
            if (! empty($json[$k]) && is_string($json[$k])) return $json[$k];
        }

        return null;
    }
}
