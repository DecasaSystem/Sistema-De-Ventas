<?php

namespace App\Services\Facturacion;

use App\Models\Cliente;
use App\Models\FacturaElectronica;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\Pago;
use App\Models\SolicitudCambio;
use App\Services\AvisoFacturacion;
use App\Support\Esquema;
use App\Support\ReglasFacturacion;

/**
 * Arma la factura de una orden (o de un pago) tal como se va a mandar a
 * Alegra, y la revisa. Lo que ve el facturador en la pantalla y lo que se
 * manda al tocar "Facturar" salen de aquí: lo que se revisó es lo que sale.
 *
 * Cuentas (cómo lo haría un contador):
 *  - Los precios del sistema traen el IVA adentro. Alegra pide el precio SIN
 *    IVA y sin descuentos, y el impuesto aparte: base = precio ÷ (1 + IVA).
 *  - Los descuentos de la orden (el manual y el condicionado) se reparten
 *    entre los productos en proporción a lo que vale cada uno, para que la
 *    suma de la factura sea exactamente lo que el cliente pagó (valor_total).
 *  - Lo devuelto (devuelto_en) no va: ya no es de la orden.
 *  - Una restauración es un servicio: va con el ítem de servicio de Alegra y,
 *    si así está en Ajustes, con IVA.
 *  - Una FV2 marcada "sin descontar IVA" no lleva IVA adentro (misma regla que
 *    Finanzas, fv2_sin_iva_no_gravada): va sin impuesto.
 *
 * Las alertas son de dos clases: `bloqueo` (no se puede facturar hasta
 * arreglarlo) y `advertencia` (se puede, pero la persona tiene que decir que
 * lo miró).
 */
class ArmarFactura
{
    private array $alertas = [];

    public function __construct(private Orden $orden, private ?Pago $pago = null) {}

    public static function de(Orden $orden, ?Pago $pago = null): array
    {
        return (new self($orden, $pago))->armar();
    }

    public function armar(): array
    {
        $r = ReglasFacturacion::todo();
        $o = $this->orden;
        $o->loadMissing(['cliente', 'tienda', 'vendedor', 'items.producto', 'pagos']);

        if ($motivo = QueSeFactura::motivoParaNo($o, $this->pago)) {
            $this->bloqueo('no_se_factura', $motivo);
        }

        $cliente = $this->datosDelCliente($o->cliente);
        $lineas  = $this->pago ? $this->lineasDelPago() : $this->lineasDeLaOrden();
        $pagos   = $this->pagosQueVan();

        $base = round(array_sum(array_column($lineas, 'base_total')), 2);
        $iva  = round(array_sum(array_column($lineas, 'iva_total')), 2);
        $total = round($base + $iva, 2);
        $valorSistema = $this->pago ? (float) $this->pago->monto : (float) $o->valor_total;
        $diferencia   = round($total - $valorSistema, 2);

        if (! $lineas) {
            $this->bloqueo('sin_lineas', 'No hay nada que facturar: la orden no tiene productos con valor.');
        }
        if ($valorSistema <= 0) {
            $this->bloqueo('valor_cero', 'El valor a facturar es $0.');
        }
        if (abs($diferencia) >= 1) {
            $this->advertencia('redondeo', 'La factura da ' . self::pesos($total) . ' y la orden ' . self::pesos($valorSistema)
                . ' (diferencia ' . self::pesos($diferencia) . ' por los decimales). Si importa, sube los decimales en Ajustes.');
        }

        $pagado = $this->pago ? (float) $this->pago->monto : (float) $o->pagos->sum('monto');
        $saldo  = round($valorSistema - $pagado, 2);
        $formaPago = $saldo > 0.01 ? 'CREDIT' : 'CASH';
        if ($formaPago === 'CREDIT') {
            $this->advertencia('saldo', 'El cliente todavía debe ' . self::pesos($saldo) . ': la factura sale a crédito ('
                . $r['dias_credito'] . ' días).');
        }

        $this->revisarConfiguracion($lineas, $pagos);
        $this->revisarCambiosDePlata();
        $this->revisarFacturadoAMano();

        $hoy = now('America/Bogota')->toDateString();
        $metodoPrincipal = $this->metodoPrincipal($pagos);
        $factura = [
            'date'          => $hoy,
            'dueDate'       => $formaPago === 'CREDIT' ? now('America/Bogota')->addDays((int) $r['dias_credito'])->toDateString() : $hoy,
            'paymentForm'   => $formaPago,
            'paymentMethod' => $r['medios_dian'][$metodoPrincipal] ?? 'INSTRUMENT_NOT_DEFINED',
            'items'         => array_map(fn ($l) => $l['alegra'], $lineas),
            'observations'  => $this->marca() . ' · ' . $o->referencia,
            'anotation'     => str_replace('{orden}', $o->referencia, (string) $r['nota_factura']),
        ];
        if ($numeracion = ReglasFacturacion::numeracionDe($o->tienda_id)) {
            $factura['numberTemplate'] = ['id' => $numeracion];
        }
        if ($r['registrar_pagos'] && $pagos) {
            $factura['payments'] = array_map(fn ($p) => $p['alegra'], $pagos);
        }
        if (ReglasFacturacion::emite()) {
            $factura['stamp'] = ['generateStamp' => true];
        } else {
            $factura['status'] = 'draft';
            unset($factura['payments']); // un borrador con pagos queda abierto en Alegra
        }

        $bloqueos = array_values(array_filter($this->alertas, fn ($a) => $a['tipo'] === 'bloqueo'));

        return [
            'clave'         => FacturaElectronica::claveDe($o, $this->pago),
            'orden_id'      => $o->id,
            'pago_id'       => $this->pago?->id,
            'referencia'    => $o->referencia,
            'modo'          => $r['modo'],
            'cliente'       => $cliente,
            'lineas'        => array_map(fn ($l) => array_diff_key($l, ['alegra' => 1]), $lineas),
            'pagos'         => array_map(fn ($p) => array_diff_key($p, ['alegra' => 1]), $pagos),
            'totales'       => compact('base', 'iva', 'total') + [
                'valor_sistema' => $valorSistema, 'diferencia' => $diferencia,
                'pagado' => $pagado, 'saldo' => $saldo,
            ],
            'forma_pago'    => $formaPago,
            'medio_pago'    => $factura['paymentMethod'],
            'alertas'       => $this->alertas,
            'se_puede'      => ! $bloqueos,
            'advertencias'  => array_values(array_map(fn ($a) => $a['codigo'],
                                   array_filter($this->alertas, fn ($a) => $a['tipo'] === 'advertencia'))),
            'factura'       => $factura,
            'contacto'      => $cliente['alegra'],
        ];
    }

    /** Lo que se escribe en las observaciones de la factura para encontrarla después. */
    public function marca(): string
    {
        return $this->pago ? "[DECASA-PAGO-{$this->pago->id}]" : "[DECASA-ORDEN-{$this->orden->id}]";
    }

    // ── Productos ───────────────────────────────────────────────────────────

    private function lineasDeLaOrden(): array
    {
        $r = ReglasFacturacion::todo();
        $o = $this->orden;
        $vivos = $o->items->filter(fn ($i) => $i->estaVivo());

        $subtotal = (float) $vivos->sum(fn ($i) => (float) $i->cantidad * (float) $i->precio_unitario);
        if ($subtotal <= 0) return [];

        // Lo que de verdad paga el cliente por cada peso de lista.
        $factor = (float) $o->valor_total / $subtotal;
        $esperado = max(0, $subtotal - (float) $o->descuento_total - (float) $o->descuento_condicionado);
        if (abs($esperado - (float) $o->valor_total) >= 1) {
            $this->advertencia('total_no_cuadra', 'El total de la orden (' . self::pesos((float) $o->valor_total)
                . ') no es productos menos descuentos (' . self::pesos($esperado) . '). Se factura el total de la orden.');
        }

        $lineas  = [];
        $regalos = [];
        foreach ($vivos as $item) {
            $cantidad = (float) $item->cantidad;
            $bruto    = $cantidad * (float) $item->precio_unitario;
            $nombre   = $this->nombreDe($item);
            if ($bruto <= 0 || $cantidad <= 0) {
                $regalos[] = $nombre;
                continue;
            }

            $this->revisarPrecio($item, $nombre);

            $neto     = $bruto * $factor;
            $servicio = (bool) $item->es_restauracion;
            $ivaPct   = $this->ivaDe($servicio);
            $baseUnit = round(($neto / $cantidad) / (1 + $ivaPct / 100), (int) $r['decimales']);

            $lineas[] = $this->linea($nombre, $cantidad, $baseUnit, $ivaPct, $servicio, $neto, (float) $item->precio_unitario, $item->id);
        }

        if ($regalos) {
            $this->advertencia('regalos', 'No van en la factura por no tener valor: ' . implode(', ', $regalos) . '.');
        }
        if ((float) $o->descuento_total + (float) $o->descuento_condicionado > 0.01) {
            $this->advertencia('descuento', 'La orden tiene ' . self::pesos((float) $o->descuento_total + (float) $o->descuento_condicionado)
                . ' de descuento: va repartido en el precio de cada producto.', false);
        }

        return $lineas;
    }

    /** Con "una factura por cada pago": una sola línea por lo que pagó. */
    private function lineasDelPago(): array
    {
        $r = ReglasFacturacion::todo();
        $p = $this->pago;
        $servicio = $this->orden->esSoloRestauracion();
        $ivaPct = $this->ivaDe($servicio);
        $monto  = (float) $p->monto;
        $base   = round($monto / (1 + $ivaPct / 100), (int) $r['decimales']);
        $que    = ['anticipo' => 'Anticipo', 'abono' => 'Abono', 'saldo_final' => 'Saldo'][$p->tipo] ?? 'Pago';

        return [$this->linea("{$que} a la orden {$this->orden->referencia}", 1, $base, $ivaPct, $servicio, $monto, $monto, null)];
    }

    private function linea(string $nombre, float $cantidad, float $baseUnit, float $ivaPct, bool $servicio, float $neto, float $precioConIva, ?int $itemId): array
    {
        $r = ReglasFacturacion::todo();
        $baseTotal = round($baseUnit * $cantidad, 2);
        $ivaTotal  = round($baseTotal * $ivaPct / 100, 2);
        $itemAlegra = $servicio ? ($r['item_servicio_id'] ?: $r['item_producto_id']) : $r['item_producto_id'];

        $alegra = [
            'id'          => $itemAlegra,
            'description' => mb_substr($nombre, 0, 250),
            'price'       => $baseUnit,
            'quantity'    => $cantidad,
        ];
        if ($ivaPct > 0 && $r['impuesto_iva_id']) {
            $alegra['tax'] = [['id' => $r['impuesto_iva_id']]];
        }

        return [
            'orden_item_id'     => $itemId,
            'nombre'            => $nombre,
            'cantidad'          => $cantidad,
            'precio_con_iva'    => round($precioConIva, 2),
            'neto_con_iva'      => round($neto, 2),
            'base_unitaria'     => $baseUnit,
            'base_total'        => $baseTotal,
            'iva_pct'           => $ivaPct,
            'iva_total'         => $ivaTotal,
            'total'             => round($baseTotal + $ivaTotal, 2),
            'es_servicio'       => $servicio,
            'alegra'            => $alegra,
        ];
    }

    private function ivaDe(bool $servicio): float
    {
        $r = ReglasFacturacion::todo();
        if ($this->orden->serie === Orden::SERIE_FV2 && $this->orden->sin_descontar_iva) return 0.0;
        if ($servicio && ! $r['iva_en_restauraciones']) return 0.0;

        return (float) $r['iva_pct'];
    }

    private function nombreDe($item): string
    {
        $nombre = $item->producto?->nombre ?: ($item->nombre_custom ?: 'Producto');
        $extra  = trim((string) ($item->variante_texto ?? ''));

        return trim($nombre . ($extra !== '' ? " ({$extra})" : ''));
    }

    /**
     * "Se les fue un cero": el precio de un producto del catálogo muy lejos del
     * de lista. Personalizados, restauraciones y diseños propios no tienen
     * precio de lista con qué comparar.
     */
    private function revisarPrecio($item, string $nombre): void
    {
        $pct = (float) ReglasFacturacion::get('alerta_precio_pct');
        if ($pct <= 0 || ! $item->producto || $item->es_personalizado || $item->es_restauracion) return;

        $lista = 0.0;
        if ($item->combo_config_id && ($cfg = \App\Models\ProductoVarianteConfig::find($item->combo_config_id))) {
            $lista = (float) $cfg->precio_adicional;
        }
        if ($lista <= 0) {
            $lista = $item->es_pieza_suelta && (float) $item->producto->precio_pieza > 0
                ? (float) $item->producto->precio_pieza
                : (float) $item->producto->precio_base;
        }
        if ($lista <= 0) return;

        $precio = (float) $item->precio_unitario;
        $min = $lista * (1 - $pct / 100);
        $max = $lista * (1 + $pct / 100);
        if ($precio < $min || $precio > $max) {
            $this->advertencia('precio_raro', "{$nombre}: se vendió a " . self::pesos($precio) . ' y el precio de lista es '
                . self::pesos($lista) . '. Revisa que no sea un error de digitación.');
        }
    }

    // ── Pagos ───────────────────────────────────────────────────────────────

    private function pagosQueVan(): array
    {
        $r = ReglasFacturacion::todo();
        $pagos = $this->pago
            ? collect([$this->pago])
            : $this->orden->pagos->sortBy('created_at')->values();

        $hayReembolso = $pagos->contains(fn ($p) => (float) $p->monto < 0 || $p->tipo === 'reembolso');
        $positivos = $pagos->filter(fn ($p) => (float) $p->monto > 0)->values();
        $totalFactura = $this->pago ? (float) $this->pago->monto : (float) $this->orden->valor_total;

        $registrar = $r['registrar_pagos'];
        if ($registrar && $hayReembolso) {
            $this->advertencia('reembolso', 'La orden tiene un reembolso: los pagos no se registran en Alegra (hazlo a mano allá).');
            $registrar = false;
        }
        if ($registrar && $positivos->sum(fn ($p) => (float) $p->monto) > $totalFactura + 0.01) {
            $this->advertencia('pagado_de_mas', 'Los pagos suman más que la factura: no se registran en Alegra (revisa el saldo a favor).');
            $registrar = false;
        }

        $sinCuenta = [];
        $salida = [];
        foreach ($positivos as $p) {
            $tiendaId = $p->tienda_id ?: $this->orden->tienda_id;
            $cuenta = ReglasFacturacion::cuentaDe($tiendaId, $p->metodo);
            if ($registrar && ! $cuenta) $sinCuenta[] = $p->metodo ?: 'sin medio';

            $fotos = array_values(array_filter(array_merge(
                (array) ($p->comprobante_fotos ?? []),
                $p->comprobante_url ? [$p->comprobante_url] : [],
            )));

            $salida[] = [
                'id'           => $p->id,
                'tipo'         => $p->tipo,
                'metodo'       => $p->metodo,
                'monto'        => (float) $p->monto,
                'fecha'        => $p->created_at?->timezone('America/Bogota')->toDateString(),
                'referencia'   => $p->referencia,
                'cuenta'       => $cuenta,
                'comprobantes' => array_values(array_unique($fotos)),
                'facturado_a_mano' => (bool) ($p->facturacion_hecha_at ?? false),
                'va_a_alegra'  => $registrar,
                'alegra'       => [
                    'date'          => $p->created_at?->timezone('America/Bogota')->toDateString(),
                    'account'       => ['id' => $cuenta],
                    'amount'        => round((float) $p->monto, 2),
                    'paymentMethod' => $r['medios_pago'][$p->metodo ?: 'otro'] ?? 'cash',
                    'observations'  => 'Pago #' . $p->id . ($p->referencia ? ' · ' . $p->referencia : ''),
                ],
            ];
        }

        if ($sinCuenta) {
            $this->bloqueo('pago_sin_cuenta', 'Falta escoger en Ajustes a qué caja o banco de Alegra entran los pagos con: '
                . implode(', ', array_unique($sinCuenta)) . '.');
        }

        return $registrar ? $salida : array_map(fn ($p) => ['va_a_alegra' => false] + $p, $salida);
    }

    private function metodoPrincipal(array $pagos): string
    {
        if (! $pagos) return 'efectivo';
        usort($pagos, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        return $pagos[0]['metodo'] ?: 'otro';
    }

    // ── Cliente ─────────────────────────────────────────────────────────────

    /**
     * Los datos fiscales del cliente, con lo que falta. Lo que no está en el
     * cliente se toma de la orden (dirección de envío) y se dice de dónde salió.
     */
    private function datosDelCliente(?Cliente $c): array
    {
        $r = ReglasFacturacion::todo();
        $o = $this->orden;

        $documento = preg_replace('/[^0-9A-Za-z]/', '', (string) ($c?->cedula ?? ''));
        $tipoDoc   = $c?->tipo_documento ?: ($documento !== '' ? 'CC' : null);
        $persona   = $c?->tipo_persona ?: ($tipoDoc === 'NIT' ? 'juridica' : 'natural');
        $regimen   = $c?->regimen ?: ($persona === 'juridica' ? 'responsable' : 'no_responsable');
        $dv        = $c?->dv ?: ($tipoDoc === 'NIT' && ctype_digit($documento) ? (string) self::digitoVerificacion($documento) : null);
        $direccion = trim((string) ($c?->direccion ?: $o->direccion_envio));
        $ciudad    = trim((string) ($c?->ciudad ?: ($o->ciudad_envio ?: $o->tienda?->ciudad)));
        $depto     = trim((string) ($c?->departamento ?: $o->departamento_envio));

        $faltan = [];
        $consumidorFinal = false;
        if ($documento === '') {
            if ($r['sin_documento'] === 'consumidor_final' && $r['consumidor_final_id']) {
                $consumidorFinal = true;
                $this->advertencia('consumidor_final', 'El cliente no tiene documento: se factura a "Consumidor final".');
            } else {
                $faltan[] = 'documento';
            }
        }
        if (! $consumidorFinal) {
            if (trim((string) $c?->nombre) === '') $faltan[] = 'nombre';
            if ($direccion === '') $faltan[] = 'direccion';
            if ($ciudad === '')    $faltan[] = 'ciudad';
            if ($depto === '')     $faltan[] = 'departamento';
        }
        if ($faltan) {
            $this->bloqueo('datos_cliente', 'Faltan datos del cliente para la DIAN: ' . implode(', ', $faltan) . '. Complétalos arriba.');
        }
        if (! $consumidorFinal && ! $c?->email) {
            $this->advertencia('sin_correo', 'El cliente no tiene correo: la DIAN no le podrá mandar la factura.', false);
        }

        [$primero, $apellidos] = self::partirNombre((string) $c?->nombre);
        $contacto = [
            'identificationObject' => array_filter([
                'type' => $tipoDoc, 'number' => $documento, 'dv' => $tipoDoc === 'NIT' ? $dv : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'kindOfPerson' => $persona === 'juridica' ? 'LEGAL_ENTITY' : 'PERSON_ENTITY',
            'regime'       => $regimen === 'responsable' ? 'COMMON_REGIME' : 'SIMPLIFIED_REGIME',
            'address'      => ['address' => $direccion, 'city' => $ciudad, 'department' => $depto, 'country' => 'Colombia'],
            'email'        => $c?->email ?: null,
            'mobile'       => $c?->telefono ?: null,
            'type'         => ['client'],
        ];
        if ($persona === 'juridica') {
            $contacto['name'] = trim((string) $c?->nombre);
        } else {
            $contacto['nameObject'] = ['firstName' => $primero, 'lastName' => $apellidos];
        }

        return [
            'id'               => $c?->id,
            'nombre'           => $c?->nombre,
            'documento'        => $documento,
            'tipo_documento'   => $tipoDoc,
            'dv'               => $dv,
            'tipo_persona'     => $persona,
            'regimen'          => $regimen,
            'email'            => $c?->email,
            'telefono'         => $c?->telefono,
            'direccion'        => $direccion,
            'ciudad'           => $ciudad,
            'departamento'     => $depto,
            'nombres'          => $primero,
            'apellidos'        => $apellidos,
            'faltan'           => $faltan,
            'consumidor_final' => $consumidorFinal,
            'alegra_contacto_id' => $consumidorFinal ? $r['consumidor_final_id'] : ($c?->alegra_contacto_id ?: null),
            'alegra'           => $consumidorFinal ? null : $contacto,
        ];
    }

    /**
     * "Ana María Gómez Ruiz" → ["Ana María", "Gómez Ruiz"]. Con dos palabras,
     * nombre y apellido; con tres, nombre y dos apellidos (lo más común en
     * Colombia); con cuatro o más, las dos primeras son el nombre.
     */
    public static function partirNombre(string $nombre): array
    {
        $p = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($p);
        if ($n === 0) return ['', ''];
        if ($n === 1) return [$p[0], $p[0]];
        if ($n <= 3)  return [$p[0], implode(' ', array_slice($p, 1))];

        return [implode(' ', array_slice($p, 0, 2)), implode(' ', array_slice($p, 2))];
    }

    /** Dígito de verificación del NIT (algoritmo de la DIAN, módulo 11). */
    public static function digitoVerificacion(string $nit): int
    {
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $digitos = array_reverse(str_split(preg_replace('/\D/', '', $nit)));
        $suma = 0;
        foreach ($digitos as $i => $d) {
            $suma += (int) $d * ($pesos[$i] ?? 0);
        }
        $residuo = $suma % 11;

        return $residuo > 1 ? 11 - $residuo : $residuo;
    }

    // ── Revisiones ──────────────────────────────────────────────────────────

    private function revisarConfiguracion(array $lineas, array $pagos): void
    {
        $r = ReglasFacturacion::todo();
        if (! \App\Services\Alegra\ClienteAlegra::configurado()) {
            $this->bloqueo('sin_conexion', 'Alegra no está conectado (faltan ALEGRA_EMAIL y ALEGRA_TOKEN en el servidor).');
        }
        if (! $r['item_producto_id']) {
            $this->bloqueo('sin_item', 'Falta escoger en Ajustes el producto de Alegra con que se facturan las ventas.');
        }
        $llevaIva = array_filter($lineas, fn ($l) => $l['iva_pct'] > 0);
        if ($llevaIva && ! $r['impuesto_iva_id']) {
            $this->bloqueo('sin_iva', 'Falta escoger en Ajustes el impuesto de IVA de Alegra.');
        }
        if (! ReglasFacturacion::numeracionDe($this->orden->tienda_id)) {
            $this->advertencia('sin_numeracion', 'No hay numeración escogida para esta tienda: Alegra usará la preferida de la empresa.', false);
        }
    }

    /** Una solicitud de cambio sin decidir o un cambio de plata reciente. */
    private function revisarCambiosDePlata(): void
    {
        if (Esquema::tabla('solicitudes_cambio')
            && SolicitudCambio::where('orden_id', $this->orden->id)->where('estado', SolicitudCambio::PENDIENTE)->exists()) {
            $this->bloqueo('solicitud_pendiente', 'La orden tiene una solicitud de cambio sin decidir: primero que un supervisor la apruebe o rechace.');
        }

        $horas = (int) ReglasFacturacion::get('horas_cambio_reciente');
        if ($horas <= 0 || ! Esquema::tabla('orden_ediciones')) return;

        $recientes = OrdenEdicion::where('orden_id', $this->orden->id)
            ->where('created_at', '>=', now()->subHours($horas))
            ->get();
        foreach ($recientes as $ed) {
            $deDinero = array_filter((array) $ed->cambios, fn ($c) => is_array($c) && AvisoFacturacion::esCambioDeDinero($c));
            if ($deDinero) {
                $this->advertencia('cambio_reciente', 'La plata de la orden cambió hace poco (' . $ed->created_at?->timezone('America/Bogota')->format('d/m h:i a')
                    . '). Confirma que el valor final es el correcto.');
                return;
            }
        }
    }

    private function revisarFacturadoAMano(): void
    {
        if ($this->pago || ! Esquema::columna('pagos', 'facturacion_hecha_at')) return;
        $aMano = $this->orden->pagos->filter(fn ($p) => $p->facturacion_hecha_at)->count();
        if ($aMano > 0) {
            $this->advertencia('facturado_a_mano', "{$aMano} pago(s) de esta orden ya se marcaron como facturados a mano. "
                . 'Revisa en Alegra que no exista ya la factura antes de hacer otra.');
        }
    }

    private function bloqueo(string $codigo, string $mensaje): void
    {
        $this->alertas[] = ['tipo' => 'bloqueo', 'codigo' => $codigo, 'mensaje' => $mensaje];
    }

    /** $confirmar = false: solo informativa, no hace falta aceptarla. */
    private function advertencia(string $codigo, string $mensaje, bool $confirmar = true): void
    {
        $this->alertas[] = ['tipo' => $confirmar ? 'advertencia' : 'info', 'codigo' => $codigo, 'mensaje' => $mensaje];
    }

    public static function pesos(float $v): string
    {
        return ($v < 0 ? '-$' : '$') . number_format(abs($v), 0, ',', '.');
    }
}
