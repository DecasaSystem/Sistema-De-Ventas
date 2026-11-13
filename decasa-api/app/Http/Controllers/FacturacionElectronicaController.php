<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\FacturaElectronica;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\Usuario;
use App\Services\Alegra\ClienteAlegra;
use App\Services\Alegra\SinRespuestaDeAlegra;
use App\Services\Facturacion\ArmarFactura;
use App\Services\Facturacion\EmitirFactura;
use App\Services\Facturacion\NoSePuedeFacturar;
use App\Services\Facturacion\QueSeFactura;
use App\Support\Esquema;
use App\Support\ReglasFacturacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Facturación electrónica (docs/plan-facturacion-alegra.md).
 *
 * Quién: el facturador (usuarios.facturacion) y los supervisores facturan;
 * solo un supervisor cambia los Ajustes. El flujo que pidió el dueño
 * (2026-11-09): la orden se entrega → le aparece al facturador en "Por
 * facturar" → entra, revisa toda la orden con sus pruebas → toca Facturar.
 */
class FacturacionElectronicaController extends Controller
{
    public static function puedeFacturar(?Usuario $u): bool
    {
        return $u && $u->activo !== false && ($u->rol === 'supervisor' || (bool) $u->facturacion);
    }

    private function exigir(Request $request): Usuario
    {
        $u = $request->user();
        abort_unless(self::puedeFacturar($u), 403, 'Tu cuenta no tiene la función de facturación.');

        return $u;
    }

    private function exigirSupervisor(Request $request): Usuario
    {
        $u = $request->user();
        abort_unless($u && $u->rol === 'supervisor', 403, 'Solo un supervisor cambia cómo se factura.');

        return $u;
    }

    /** GET /facturacion-electronica/estado */
    public function estado(Request $request)
    {
        $u = $this->exigir($request);
        $r = ReglasFacturacion::todo();

        return response()->json([
            'activo'          => $r['activo'],
            'modo'            => $r['modo'],
            'momento'         => $r['momento'],
            'desde'           => $r['desde'],
            'conectado'       => ClienteAlegra::configurado(),
            'puede_ajustes'   => $u->rol === 'supervisor',
            'requiere_revision' => $r['requiere_revision'],
        ]);
    }

    /** GET /facturacion-electronica/pendientes */
    public function pendientes(Request $request)
    {
        $this->exigir($request);

        $lista = QueSeFactura::pendientes($request->query('buscar'), $request->integer('tienda_id') ?: null);

        return response()->json([
            'data' => $lista->map(fn ($p) => $this->fila($p['orden'], $p['pago'], $p['factura']))->values(),
        ]);
    }

    /** GET /facturacion-electronica/facturas?grupo=hechas|problemas|diferencias */
    public function facturas(Request $request)
    {
        $this->exigir($request);
        $grupo = $request->query('grupo', 'hechas');

        $q = FacturaElectronica::query()
            ->with(['orden.cliente:id,nombre,cedula', 'orden.tienda:id,nombre', 'usuario:id,nombre', 'pago:id,monto,tipo,metodo'])
            ->when($request->query('buscar'), fn ($q, $b) => $q->where(fn ($w) => $w
                ->where('numero', 'like', '%' . $b . '%')
                ->orWhereHas('orden', fn ($o) => QueSeFactura::filtrarBusqueda($o, $b))));

        if ($grupo === 'problemas') {
            // También las emitidas a las que les quedó un pago sin registrar en Alegra.
            $q->where(fn ($w) => $w
                ->whereIn('estado', [...FacturaElectronica::ESTADOS_CON_PROBLEMA, 'enviando'])
                ->orWhere(fn ($e) => $e->whereIn('estado', FacturaElectronica::ESTADOS_HECHOS)
                    ->where(fn ($p) => $p->where('pagos_alegra', 'like', '%"estado":"error"%')
                        ->orWhere('pagos_alegra', 'like', '%"estado":"incierto"%')
                        ->orWhere('pagos_alegra', 'like', '%"estado":"pendiente"%'))));
        } elseif ($grupo === 'diferencias') {
            $q->whereIn('estado', FacturaElectronica::ESTADOS_HECHOS);
        } else {
            // "Hechas" muestra también los borradores del modo de prueba (con su chip).
            $q->whereIn('estado', [...FacturaElectronica::ESTADOS_HECHOS, 'borrador_alegra']);
        }

        $filas = $q->orderByDesc('updated_at')->limit(200)->get()
            ->map(fn ($f) => $this->filaFactura($f));

        if ($grupo === 'diferencias') {
            $filas = $filas->filter(fn ($f) => $f['diferencia'] !== null)->values();
        }

        return response()->json(['data' => $filas->values()]);
    }

    /** GET /facturacion-electronica/ordenes/{id}?pago_id= */
    public function detalle(Request $request, int $id)
    {
        $this->exigir($request);
        [$orden, $pago] = $this->ordenYPago($request, $id);

        // ?retencion= : la que anotó el facturador, para ver cómo queda (contado o crédito).
        $armado = ArmarFactura::de($orden, $pago, max(0, (float) $request->query('retencion', 0)));
        $factura = FacturaElectronica::with('usuario:id,nombre')
            ->where('clave', FacturaElectronica::claveDe($orden, $pago))->first();

        unset($armado['factura'], $armado['contacto'], $armado['cliente']['alegra']);

        return response()->json([
            'armado'  => $armado,
            'factura' => $factura ? $this->filaFactura($factura) + ['error' => $factura->error] : null,
            'orden'   => $this->pruebasDeLaOrden($orden),
            'otras'   => FacturaElectronica::where('orden_id', $orden->id)
                ->where('clave', '!=', FacturaElectronica::claveDe($orden, $pago))
                ->get()->map(fn ($f) => $this->filaFactura($f))->values(),
        ]);
    }

    /** PUT /facturacion-electronica/clientes/{id} — los datos fiscales que faltan. */
    public function guardarCliente(Request $request, int $id)
    {
        $this->exigir($request);
        $cliente = Cliente::findOrFail($id);

        $datos = $request->validate([
            'nombre'         => 'sometimes|required|string|max:120',
            'cedula'         => 'sometimes|nullable|string|max:20',
            'tipo_documento' => 'sometimes|nullable|in:CC,NIT,CE,PP,TI,PEP,DIE,NUIP,TE,RC,FOREIGN_NIT',
            'dv'             => 'sometimes|nullable|string|max:2',
            'tipo_persona'   => 'sometimes|nullable|in:natural,juridica',
            'regimen'        => 'sometimes|nullable|in:no_responsable,responsable',
            'email'          => 'sometimes|nullable|email|max:120',
            'telefono'       => 'sometimes|nullable|string|max:20',
            'direccion'      => 'sometimes|nullable|string|max:200',
            'ciudad'         => 'sometimes|nullable|string|max:80',
            'departamento'   => 'sometimes|nullable|string|max:80',
        ]);

        if (array_key_exists('cedula', $datos)) {
            $datos['cedula'] = $datos['cedula'] !== null ? preg_replace('/[^0-9A-Za-z]/', '', $datos['cedula']) : null;
            if ($datos['cedula'] && Cliente::where('cedula', $datos['cedula'])->where('id', '!=', $cliente->id)->exists()) {
                return response()->json(['message' => 'Ya hay otro cliente con ese documento.'], 422);
            }
        }
        if (($datos['tipo_documento'] ?? $cliente->tipo_documento) === 'NIT') {
            $doc = $datos['cedula'] ?? $cliente->cedula;
            if ($doc && ctype_digit($doc) && empty($datos['dv'])) {
                $datos['dv'] = (string) ArmarFactura::digitoVerificacion($doc);
            }
        }
        // Si cambió la identidad del cliente, el contacto de Alegra guardado ya no es él.
        if ((array_key_exists('cedula', $datos) && $datos['cedula'] !== $cliente->cedula)
            || (array_key_exists('tipo_documento', $datos) && $datos['tipo_documento'] !== $cliente->tipo_documento)) {
            $datos['alegra_contacto_id'] = null;
        }

        $cliente->update(array_filter($datos, fn ($k) => Esquema::columna('clientes', $k), ARRAY_FILTER_USE_KEY));

        return response()->json(['cliente' => $cliente->fresh()]);
    }

    /** POST /facturacion-electronica/ordenes/{id}/facturar */
    public function facturar(Request $request, int $id, EmitirFactura $emitir)
    {
        $u = $this->exigir($request);
        $request->validate([
            'pago_id'     => 'nullable|integer',
            'revisado'    => 'accepted',
            'aceptadas'   => 'array',
            'aceptadas.*' => 'string|max:40',
            'retencion'   => 'nullable|numeric|min:0',
        ], ['revisado.accepted' => 'Marca que revisaste la orden antes de facturar.']);

        if (! ReglasFacturacion::activo()) {
            return response()->json(['message' => 'La facturación electrónica está apagada (Ajustes).'], 422);
        }
        [$orden, $pago] = $this->ordenYPago($request, $id);

        try {
            $f = $emitir->facturar($orden, $pago, $u, (array) $request->input('aceptadas', []), false,
                max(0, (float) $request->input('retencion', 0)));
        } catch (NoSePuedeFacturar $e) {
            return response()->json([
                'message'     => $e->getMessage(),
                'alertas'     => $e->alertas,
                'sin_aceptar' => $e->sinAceptar,
            ], $e->status);
        }

        $status = in_array($f->estado, ['emitida', 'pendiente_dian', 'borrador_alegra'], true) ? 200 : 422;

        return response()->json([
            'message' => $this->mensajeDe($f),
            'factura' => $this->filaFactura($f) + ['error' => $f->error],
        ], $status);
    }

    /** POST /facturacion-electronica/facturas/{id}/actualizar — preguntarle a Alegra cómo va. */
    public function actualizar(Request $request, int $id, EmitirFactura $emitir)
    {
        $this->exigir($request);
        $f = FacturaElectronica::findOrFail($id);

        try {
            $f = $emitir->actualizar($f);
        } catch (NoSePuedeFacturar $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (SinRespuestaDeAlegra $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['message' => $this->mensajeDe($f), 'factura' => $this->filaFactura($f) + ['error' => $f->error]]);
    }

    /**
     * POST /facturacion-electronica/facturas/{id}/registrar-pagos — los pagos
     * que no quedaron en Alegra. Uno "incierto" (Alegra no respondió) solo se
     * reintenta si el facturador confirma que lo revisó allá y no está.
     */
    public function registrarPagos(Request $request, int $id, EmitirFactura $emitir)
    {
        $this->exigir($request);
        $f = FacturaElectronica::findOrFail($id);
        $f = $emitir->registrarPagos($f, $request->boolean('confirmo_no_estan'));
        $faltan = count($f->pagosSinRegistrar());

        return response()->json([
            'message' => $faltan ? "Quedan {$faltan} pago(s) sin registrar: mira el error de cada uno." : 'Pagos registrados en Alegra.',
            'factura' => $this->filaFactura($f) + ['error' => $f->error],
        ], $faltan ? 422 : 200);
    }

    /**
     * POST /facturacion-electronica/ordenes/{id}/externa — ya se facturó por
     * fuera (a mano en Alegra): se anota el número y sale de la lista.
     */
    public function registrarExterna(Request $request, int $id)
    {
        $u = $this->exigir($request);
        $datos = $request->validate([
            'pago_id' => 'nullable|integer',
            'numero'  => 'required|string|max:40',
            'nota'    => 'nullable|string|max:500',
        ]);
        [$orden, $pago] = $this->ordenYPago($request, $id);
        $clave = FacturaElectronica::claveDe($orden, $pago);

        $f = DB::transaction(function () use ($clave, $orden, $pago, $datos, $u) {
            $f = FacturaElectronica::where('clave', $clave)->lockForUpdate()->first();
            if ($f && ($f->estaHecha() || $f->estado === 'enviando')) {
                abort(409, 'Esta ya tiene factura' . ($f->numero ? " ({$f->numero})" : '') . '.');
            }
            $valores = [
                'orden_id' => $orden->id, 'pago_id' => $pago?->id, 'estado' => 'externa', 'modo' => 'externa',
                'numero' => trim($datos['numero']), 'nota' => $datos['nota'] ?? null, 'usuario_id' => $u->id,
                'valor_sistema' => $pago ? (float) $pago->monto : (float) $orden->valor_total,
                'total' => $pago ? (float) $pago->monto : (float) $orden->valor_total,
                'emitida_at' => now(), 'error' => null,
            ];

            return $f ? tap($f)->update($valores) : FacturaElectronica::create(['clave' => $clave] + $valores);
        });

        return response()->json(['message' => 'Quedó anotada como facturada por fuera.', 'factura' => $this->filaFactura($f)]);
    }

    /** POST /facturacion-electronica/facturas/{id}/diferencia-resuelta */
    public function diferenciaResuelta(Request $request, int $id)
    {
        $u = $this->exigir($request);
        $datos = $request->validate(['nota' => 'required|string|max:500']);
        $f = FacturaElectronica::findOrFail($id);
        $f->update([
            'diferencia_resuelta_at'  => now(),
            'diferencia_resuelta_por' => $u->id,
            'diferencia_nota'         => $datos['nota'],
            // Lo resuelto es contra lo que vale hoy: si vuelve a cambiar, vuelve a salir.
            // Cancelada = $0 (se anuló con nota crédito).
            'valor_sistema'           => $f->orden?->estado === 'cancelado' ? 0
                : ($f->pago_id ? (float) $f->pago?->monto : (float) $f->orden?->valor_total),
        ]);

        return response()->json(['message' => 'Diferencia marcada como resuelta.', 'factura' => $this->filaFactura($f->fresh())]);
    }

    // ── Ajustes (solo supervisor) ───────────────────────────────────────────

    public function reglas(Request $request)
    {
        $this->exigir($request);

        return response()->json([
            'reglas'      => ReglasFacturacion::todo(),
            'conectado'   => ClienteAlegra::configurado(),
            'metodos'     => ReglasFacturacion::METODOS,
            'momentos'    => ReglasFacturacion::MOMENTOS,
            'medios_dian' => ReglasFacturacion::MEDIOS_DIAN,
            'medios_pago' => ReglasFacturacion::MEDIOS_PAGO_ALEGRA,
            'tiendas'     => self::tiendasQueFacturan(),
        ]);
    }

    /**
     * Las tiendas a las que se les puede escoger numeración y cajas: solo las
     * que siguen abiertas. Salían también las desactivadas y las cerradas
     * (Circunvalar, 27 ago 2026), que ya no venden (usuario, 2026-11-11).
     * Lo que ya tuvieran guardado se queda y no estorba.
     */
    public static function tiendasQueFacturan()
    {
        return DB::table('tiendas')
            ->select('id', 'nombre')
            ->when(Esquema::columna('tiendas', 'activa'), fn ($q) => $q->where('activa', true))
            ->when(Esquema::columna('tiendas', 'cerrada_en'), fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('cerrada_en')
                ->orWhere('cerrada_en', '>', now('America/Bogota')->toDateString())))
            ->orderBy('nombre')
            ->get();
    }

    public function guardarReglas(Request $request)
    {
        $this->exigirSupervisor($request);
        [$reglas, $errores] = ReglasFacturacion::guardar((array) $request->input('reglas', []));

        if ($errores) {
            return response()->json(['message' => $errores[0], 'errores' => $errores], 422);
        }

        return response()->json(['message' => 'Ajustes guardados.', 'reglas' => $reglas]);
    }

    /** GET /facturacion-electronica/alegra/catalogos — lo que hay en Alegra para escoger en Ajustes. */
    public function catalogos(Request $request, ClienteAlegra $alegra)
    {
        $this->exigirSupervisor($request);
        if (! ClienteAlegra::configurado()) {
            return response()->json(['message' => 'Alegra no está conectado: falta ALEGRA_TOKEN en el servidor (y ALEGRA_EMAIL si es el token clásico).'], 422);
        }

        try {
            $num = $alegra->get('number-templates', ['documentType' => 'invoice']);
            if (! $num->ok) {
                return response()->json(['message' => $num->error], $num->status === 401 ? 422 : 502);
            }
            $cuentas = $alegra->get('bank-accounts');
            $impuestos = $alegra->get('taxes');
            $bodegas   = $alegra->get('warehouses', ['status' => 'active']);
            $centros   = $alegra->get('cost-centers', ['status' => 'active']);
        } catch (SinRespuestaDeAlegra $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $lista = fn ($r) => $r->ok && is_array($r->json) ? array_values(array_filter($r->json, 'is_array')) : [];

        return response()->json([
            // Cada una con su estado (vigente, por vencer, pocos números, vencida,
            // agotada): una resolución vencida no se deja escoger (EstadoNumeracion).
            'numeraciones' => array_map(fn ($n) => [
                'electronica' => (bool) ($n['isElectronic'] ?? false), 'tipo' => $n['documentType'] ?? null,
                'activa' => ($n['status'] ?? 'active') === 'active',
                'preferida' => (bool) ($n['isDefault'] ?? $n['preferred'] ?? false),
            ] + \App\Services\Alegra\EstadoNumeracion::de($n), $lista($num)),
            // Solo lo activo en Alegra (como las tiendas: lo desactivado no se ofrece).
            'cuentas' => array_values(array_map(fn ($c) => [
                'id' => (string) ($c['id'] ?? ''), 'nombre' => $c['name'] ?? '', 'tipo' => $c['type'] ?? null,
            ], array_filter($lista($cuentas), [\App\Services\Alegra\CatalogoAlegra::class, 'activo']))),
            // Bodega y centro de costo de cada tienda (FEN1917: "3 - EDEN" y "EDEN").
            'bodegas' => array_values(array_map(fn ($b) => [
                'id' => (string) ($b['id'] ?? ''), 'nombre' => $b['name'] ?? '',
            ], array_filter($lista($bodegas), [\App\Services\Alegra\CatalogoAlegra::class, 'activo']))),
            'centros_costo' => array_values(array_map(fn ($c) => [
                'id' => (string) ($c['id'] ?? ''), 'nombre' => trim(($c['code'] ?? '') . ' ' . ($c['name'] ?? '')),
            ], array_filter(self::listaDe($centros), [\App\Services\Alegra\CatalogoAlegra::class, 'activo']))),
            'impuestos' => array_values(array_map(fn ($t) => [
                'id' => (string) ($t['id'] ?? ''), 'nombre' => $t['name'] ?? '', 'porcentaje' => $t['percentage'] ?? null,
                'tipo' => $t['type'] ?? null,
            ], array_filter($lista($impuestos), [\App\Services\Alegra\CatalogoAlegra::class, 'activo']))),
        ]);
    }

    /** GET /facturacion-electronica/alegra/items?buscar= */
    /**
     * La API nueva de Alegra (centros de costo) puede devolver la lista dentro
     * de "data"; la vieja la devuelve sola.
     */
    private static function listaDe($r): array
    {
        if (! $r->ok || ! is_array($r->json)) return [];
        $j = isset($r->json['data']) && is_array($r->json['data']) ? $r->json['data'] : $r->json;

        return array_values(array_filter($j, 'is_array'));
    }

    /**
     * PUT /facturacion-electronica/productos/{id}/alegra — qué producto de
     * Alegra es este mueble. Lo escoge el facturador en la revisión cuando no
     * se encontró por el nombre; queda para las siguientes facturas.
     */
    public function emparejarProducto(Request $request, int $id)
    {
        $u = $this->exigir($request);
        $datos = $request->validate([
            'alegra_item_id' => 'required|string|max:40',
            'alegra_nombre'  => 'nullable|string|max:200',
        ]);
        abort_unless(DB::table('productos')->where('id', $id)->exists(), 404, 'Ese producto no existe.');

        \App\Services\Alegra\ProductosEnAlegra::guardar($id, $datos['alegra_item_id'], $datos['alegra_nombre'] ?? null, 'manual', $u->id);

        return response()->json(['message' => 'Producto de Alegra guardado para ese mueble.']);
    }

    public function itemsAlegra(Request $request, ClienteAlegra $alegra)
    {
        // El facturador también busca: escoge el producto de Alegra de un mueble.
        $this->exigir($request);
        try {
            $r = $alegra->get('items', array_filter(['query' => $request->query('buscar'), 'limit' => 30]));
        } catch (SinRespuestaDeAlegra $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        if (! $r->ok) return response()->json(['message' => $r->error], 502);

        // Solo los activos, y se dice cuál lleva inventario en Alegra (el genérico debe ser sin inventario).
        return response()->json(['data' => array_values(array_map(fn ($i) => [
            'id' => (string) ($i['id'] ?? ''), 'nombre' => $i['name'] ?? '', 'tipo' => $i['type'] ?? null,
            'inventario' => \App\Services\Alegra\CatalogoAlegra::llevaInventario($i),
        ], array_filter((array) $r->json, fn ($i) => is_array($i) && \App\Services\Alegra\CatalogoAlegra::activo($i))))]);
    }

    /** GET /facturacion-electronica/alegra/contactos?buscar= (para escoger "Consumidor final"). */
    public function contactosAlegra(Request $request, ClienteAlegra $alegra)
    {
        $this->exigirSupervisor($request);
        try {
            $r = $alegra->get('contacts', array_filter(['query' => $request->query('buscar'), 'limit' => 30]));
        } catch (SinRespuestaDeAlegra $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        if (! $r->ok) return response()->json(['message' => $r->error], 502);

        return response()->json(['data' => array_map(fn ($c) => [
            'id' => (string) ($c['id'] ?? ''), 'nombre' => $c['name'] ?? '',
            'identificacion' => $c['identificationObject']['number'] ?? ($c['identification'] ?? null),
        ], array_values(array_filter((array) $r->json, 'is_array')))]);
    }

    // ── Por dentro ──────────────────────────────────────────────────────────

    /** @return array{0: Orden, 1: ?Pago} */
    private function ordenYPago(Request $request, int $id): array
    {
        $orden = Orden::with(['cliente', 'tienda', 'vendedor', 'items.producto', 'pagos'])->findOrFail($id);
        $pagoId = $request->input('pago_id', $request->query('pago_id'));
        $pago = $pagoId ? $orden->pagos->firstWhere('id', (int) $pagoId) : null;
        abort_if($pagoId && ! $pago, 404, 'Ese pago no es de esta orden.');

        return [$orden, $pago];
    }

    private function fila(Orden $orden, ?Pago $pago, ?FacturaElectronica $f): array
    {
        $c = $orden->cliente;
        $faltan = [];
        if (! $c?->cedula) $faltan[] = 'documento';
        if (! ($c?->direccion ?: $orden->direccion_envio)) $faltan[] = 'dirección';
        if (! ($c?->ciudad ?: $orden->ciudad_envio)) $faltan[] = 'ciudad';

        return [
            'orden_id'    => $orden->id,
            'pago_id'     => $pago?->id,
            'referencia'  => $orden->referencia,
            'serie'       => $orden->serie,
            'cliente'     => $c?->nombre,
            'documento'   => $c?->cedula,
            'tienda'      => $orden->tienda?->nombre,
            'vendedor'    => $orden->vendedor?->nombre,
            'estado'      => $orden->estado,
            'valor'       => $pago ? (float) $pago->monto : (float) $orden->valor_total,
            'pago'        => $pago ? ['tipo' => $pago->tipo, 'metodo' => $pago->metodo, 'fecha' => $pago->created_at] : null,
            'fecha'       => QueSeFactura::fechaQueCuenta($orden),
            'dias_sin_facturar' => QueSeFactura::diasSinFacturar($orden, $pago),
            'faltan'      => $faltan,
            'intento'     => $f ? ['estado' => $f->estado, 'error' => $f->error, 'id' => $f->id] : null,
        ];
    }

    private function filaFactura(FacturaElectronica $f): array
    {
        $actual = $f->pago_id ? (float) ($f->pago?->monto ?? $f->valor_sistema) : (float) ($f->orden?->valor_total ?? $f->valor_sistema);
        // Una orden cancelada después de facturar: al cancelar el valor no cambia,
        // así que se compara contra $0 (hay que anular la factura con nota crédito).
        $cancelada = $f->orden?->estado === 'cancelado';
        if ($cancelada) $actual = 0.0;
        $dif = round($actual - (float) $f->valor_sistema, 2);
        $hayDiferencia = in_array($f->estado, FacturaElectronica::ESTADOS_HECHOS, true) && abs($dif) >= 1;

        return [
            'id'          => $f->id,
            'orden_id'    => $f->orden_id,
            'pago_id'     => $f->pago_id,
            'referencia'  => $f->orden?->referencia,
            'cliente'     => $f->orden?->cliente?->nombre,
            'tienda'      => $f->orden?->tienda?->nombre,
            'estado'      => $f->estado,
            'modo'        => $f->modo,
            'numero'      => $f->numero,
            'cufe'        => $f->cufe,
            'estado_dian' => $f->estado_dian,
            'pdf_url'     => $f->pdf_url,
            'alegra_id'   => $f->alegra_id,
            'total'       => (float) $f->total,
            'valor_sistema' => (float) $f->valor_sistema,
            'diferencia'  => $hayDiferencia ? $dif : null,
            'orden_cancelada' => $cancelada,
            'diferencia_nota' => $f->diferencia_nota,
            // Pagos que debían quedar en Alegra y no quedaron (botón "Registrar pagos").
            'retencion_cliente' => $f->retencion_cliente !== null ? (float) $f->retencion_cliente : null,
            'pagos_sin_registrar' => array_map(fn ($p) => [
                'pago_id' => $p['pago_id'] ?? null, 'monto' => (float) ($p['monto'] ?? 0),
                'estado' => $p['estado'] ?? 'pendiente', 'error' => $p['error'] ?? null,
            ], $f->pagosSinRegistrar()),
            'nota'        => $f->nota,
            'usuario'     => $f->usuario?->nombre,
            'emitida_at'  => $f->emitida_at,
            'updated_at'  => $f->updated_at,
            'error'       => in_array($f->estado, FacturaElectronica::ESTADOS_CON_PROBLEMA, true) ? $f->error : null,
        ];
    }

    private function mensajeDe(FacturaElectronica $f): string
    {
        return match ($f->estado) {
            'emitida'         => 'Factura ' . ($f->numero ?: '') . ' emitida y aceptada por la DIAN.',
            'pendiente_dian'  => 'Factura ' . ($f->numero ?: '') . ' enviada: la DIAN la está validando.',
            'borrador_alegra' => 'Quedó como borrador en Alegra (modo de prueba: no se mandó a la DIAN).',
            'rechazada'       => 'Alegra o la DIAN la rechazó: ' . $f->error,
            'incierta'        => 'Alegra no respondió. No se sabe si se creó: toca "Revisar en Alegra" antes de intentar otra vez.',
            'externa'         => 'Anotada como facturada por fuera.',
            default           => $f->error ?: 'No se pudo facturar.',
        };
    }

    /** Lo que el facturador necesita ver para revisar: la orden y sus pruebas. */
    private function pruebasDeLaOrden(Orden $o): array
    {
        $fotos = array_values(array_unique(array_filter(array_merge(
            (array) ($o->factura_fotos ?? []),
            $o->factura_foto_url ? [$o->factura_foto_url] : [],
        ))));

        $entregas = [];
        if (Esquema::tabla('despacho_items')) {
            $cols = array_values(array_filter(['id', 'entregado_at', 'foto_producto', 'fotos_producto', 'foto_pago', 'fotos_pago', 'estado'],
                fn ($c) => $c === 'id' || Esquema::columna('despacho_items', $c)));
            foreach (DB::table('despacho_items')->where('orden_id', $o->id)->select($cols)->orderBy('id')->get() as $d) {
                $lista = fn ($una, $varias) => array_values(array_filter(array_merge(
                    json_decode((string) ($d->{$varias} ?? '[]'), true) ?: [],
                    ! empty($d->{$una}) ? [$d->{$una}] : [],
                )));
                $entregas[] = [
                    'id'            => $d->id,
                    'entregado_at'  => $d->entregado_at ?? null,
                    'estado'        => $d->estado ?? null,
                    'fotos_producto' => array_values(array_unique($lista('foto_producto', 'fotos_producto'))),
                    'fotos_pago'    => array_values(array_unique($lista('foto_pago', 'fotos_pago'))),
                ];
            }
        }

        $cambios = [];
        if (Esquema::tabla('orden_ediciones')) {
            foreach (\App\Models\OrdenEdicion::with('usuario:id,nombre')->where('orden_id', $o->id)->latest('created_at')->limit(30)->get() as $ed) {
                foreach ((array) $ed->cambios as $c) {
                    if (is_array($c) && \App\Services\AvisoFacturacion::esCambioDeDinero($c)) {
                        $cambios[] = ['fecha' => $ed->created_at, 'quien' => $ed->usuario?->nombre,
                            'que' => $c['label'] ?? $c['campo'] ?? '', 'antes' => $c['antes'] ?? null, 'despues' => $c['despues'] ?? null];
                    }
                }
            }
        }

        return [
            'id'          => $o->id,
            'referencia'  => $o->referencia,
            'estado'      => $o->estado,
            'serie'       => $o->serie,
            'creada'      => $o->created_at,
            'tienda'      => $o->tienda?->nombre,
            'vendedor'    => $o->vendedor?->nombre,
            'canal'       => $o->canal ?? null,
            'notas'       => $o->notas ?? null,
            'valor_total' => (float) $o->valor_total,
            'descuento_total' => (float) $o->descuento_total,
            'descuento_condicionado' => (float) $o->descuento_condicionado,
            'fotos_factura' => $fotos,
            'anexo_foto_url' => $o->anexo_foto_url ?? null,
            'entregas'    => $entregas,
            'cambios_de_plata' => array_slice($cambios, 0, 15),
        ];
    }
}
