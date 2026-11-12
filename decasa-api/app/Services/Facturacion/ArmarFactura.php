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

    /** Lo de la orden que no va en la factura (restauraciones, si no se facturan). */
    private float $valorSinFacturar = 0;

    /** Muebles a los que no se les encontró su producto en Alegra. */
    private array $productosSinAlegra = [];

    /**
     * @param float $retencion la que le hizo el cliente empresa (se cruza en Alegra al pagar)
     */
    public function __construct(private Orden $orden, private ?Pago $pago = null, private float $retencion = 0) {}

    public static function de(Orden $orden, ?Pago $pago = null, float $retencion = 0): array
    {
        return (new self($orden, $pago, $retencion))->armar();
    }

    public function armar(): array
    {
        $r = ReglasFacturacion::todo();
        $o = $this->orden;
        $o->loadMissing(['cliente', 'tienda', 'vendedor', 'items.producto', 'pagos']);
        // Las variantes (tela, color, medida, opción) como las muestra la orden,
        // cargadas de una vez: cada línea de la factura las lleva en su texto.
        \App\Models\OrdenItem::precargarVariantes($o->items);

        if ($motivo = QueSeFactura::motivoParaNo($o, $this->pago)) {
            $this->bloqueo('no_se_factura', $motivo);
        }

        $cliente = $this->datosDelCliente($o->cliente);
        $lineas  = $this->pago ? $this->lineasDelPago() : $this->lineasDeLaOrden();
        $pagos   = $this->pagosQueVan();

        $base = round(array_sum(array_column($lineas, 'base_total')), 2);
        $iva  = round(array_sum(array_column($lineas, 'iva_total')), 2);
        $total = round($base + $iva, 2);
        // Lo que vale la orden (o el pago) y la parte que se factura (sin la
        // restauración, si no se facturan). "Diferencias" compara contra lo primero.
        $valorOrden   = $this->pago ? (float) $this->pago->monto : (float) $o->valor_total;
        $valorSistema = round($valorOrden - $this->valorSinFacturar, 2);
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
        // El saldo es el de la orden completa: si pagó todo, es de contado.
        $saldo  = round($valorOrden - $pagado, 2);

        // La retención del cliente empresa (contador, 2026-11-11): compró $1.000.000,
        // le retuvo $25.000 y pagó $975.000. Esos $25.000 no son deuda: se cruzan
        // en Alegra al pagar la factura. Con eso, la factura es de contado.
        $retencion = round(max(0, $this->retencion), 2);
        if ($retencion > 0 && $retencion > max(0, $saldo) + 0.01) {
            $this->bloqueo('retencion_mayor', 'La retención (' . self::pesos($retencion) . ') es más de lo que falta por pagar ('
                . self::pesos(max(0, $saldo)) . '). Revísala.');
        }
        $saldoReal = round($saldo - $retencion, 2);
        $formaPago = $saldoReal > 0.01 ? 'CREDIT' : 'CASH';
        if ($formaPago === 'CREDIT') {
            $this->advertencia('saldo', 'El cliente todavía debe ' . self::pesos($saldoReal) . ': la factura sale a crédito ('
                . $r['dias_credito'] . ' días). Si es la retención que le hizo una empresa, anótala abajo.');
        }

        $this->revisarConfiguracion($lineas, $pagos);
        $this->revisarCambiosDePlata();
        $this->revisarRecibos($pagos);

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
        if (trim((string) $r['terminos_factura']) !== '') {
            $factura['termsConditions'] = (string) $r['terminos_factura'];
        }
        // Bodega y centro de costo de la tienda (FEN1917: bodega "3 - EDEN",
        // centro de costo "EDEN"): de ahí descuenta Alegra el inventario y ahí
        // carga la venta en su contabilidad.
        if ($bodega = ReglasFacturacion::bodegaDe($o->tienda_id)) {
            $factura['warehouse'] = ['id' => $bodega];
        } elseif ($r['items_alegra'] === 'por_producto') {
            $this->advertencia('sin_bodega', 'No hay bodega de Alegra escogida para ' . ($o->tienda?->nombre ?? 'esta tienda')
                . ': Alegra descontará el inventario de su bodega principal. Escógela en Ajustes.');
        }
        if ($centro = ReglasFacturacion::centroCostoDe($o->tienda_id)) {
            $factura['costCenter'] = ['id' => $centro];
        } else {
            $this->advertencia('sin_centro_costo', 'No hay centro de costo de Alegra escogido para '
                . ($o->tienda?->nombre ?? 'esta tienda') . ' (Ajustes).', false);
        }
        // Los pagos NO van dentro de la factura: se registran después, uno por uno
        // y con el total exacto que diga Alegra (EmitirFactura::registrarPagos).
        // Dentro de la factura, un centavo de redondeo de más la hacía rechazar.
        if (ReglasFacturacion::emite()) {
            $factura['stamp'] = ['generateStamp' => true];
        } else {
            $factura['status'] = 'draft';
        }
        $pagosAlegra = (ReglasFacturacion::emite() && $r['registrar_pagos'])
            ? array_values(array_map(fn ($p) => $p['alegra'] + ['pago_id' => $p['id']], array_filter($pagos, fn ($p) => $p['va_a_alegra'])))
            : [];

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
                'valor_sistema' => $valorSistema, 'valor_orden' => $valorOrden,
                'sin_facturar' => round($this->valorSinFacturar, 2), 'diferencia' => $diferencia,
                'pagado' => $pagado, 'saldo' => $saldo,
                'retencion' => $retencion, 'saldo_sin_retencion' => $saldoReal,
            ],
            // Lo que el facturador cruza en Alegra después de facturar (los anticipos
            // ya digitados como recibos y la retención): hoy se hace a mano allá.
            'por_cruzar'    => [
                'anticipos' => array_values(array_map(fn ($p) => array_intersect_key($p,
                    array_flip(['id', 'tipo', 'metodo', 'monto', 'fecha', 'referencia', 'recibo_en_alegra'])), $pagos)),
                'retencion' => $retencion,
            ],
            'forma_pago'    => $formaPago,
            'medio_pago'    => $factura['paymentMethod'],
            'numeracion'    => $this->numeracion,
            'alertas'       => $this->alertas,
            'se_puede'      => ! $bloqueos,
            'advertencias'  => array_values(array_map(fn ($a) => $a['codigo'],
                                   array_filter($this->alertas, fn ($a) => $a['tipo'] === 'advertencia'))),
            'factura'       => $factura,
            'pagos_alegra'  => $pagosAlegra,
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
        $sinFacturar = [];
        foreach ($vivos as $item) {
            $cantidad = (float) $item->cantidad;
            $bruto    = $cantidad * (float) $item->precio_unitario;
            $nombre   = $this->nombreDe($item);
            if ($bruto <= 0 || $cantidad <= 0) {
                $regalos[] = $nombre;
                continue;
            }
            // "Las restauraciones no se facturan" (contador, 2026-11-12). En una
            // orden que mezcla muebles y restauración, la factura lleva solo los
            // muebles; lo que pagó por la restauración no entra.
            if ($item->es_restauracion && ! $r['facturar_restauraciones']) {
                $sinFacturar[] = $nombre . ' (' . self::pesos($bruto * $factor) . ')';
                $this->valorSinFacturar += $bruto * $factor;
                continue;
            }

            $this->revisarPrecio($item, $nombre);

            $neto     = $bruto * $factor;
            $servicio = (bool) $item->es_restauracion;
            $ivaPct   = $this->ivaDe($servicio);
            $baseUnit = round(($neto / $cantidad) / (1 + $ivaPct / 100), (int) $r['decimales']);

            $linea = $this->linea($nombre, $cantidad, $baseUnit, $ivaPct, $servicio, $neto, (float) $item->precio_unitario, $item->id);

            // Como factura Decasa (FEN1917): el ítem es el mueble ("SOFA TELAVID")
            // y la descripción, la variante ("TAUPE") con medidas y tela.
            if ($r['items_alegra'] === 'por_producto' && $item->producto_id && ! $servicio) {
                $match = app(\App\Services\Alegra\ProductosEnAlegra::class)
                    ->para((int) $item->producto_id, (string) $item->producto?->nombre);
                $linea['alegra_item'] = $match + ['producto_id' => (int) $item->producto_id, 'producto' => $item->producto?->nombre];
                $linea['alegra']['id'] = $match['id'];
                $linea['alegra']['description'] = mb_substr($this->detalleDe($item), 0, 300);
                if (! $match['id']) $this->productosSinAlegra[] = $item->producto?->nombre;
            }

            $lineas[] = $linea;
        }

        if ($regalos) {
            $this->advertencia('regalos', 'No van en la factura por no tener valor: ' . implode(', ', $regalos) . '.');
        }
        if ($this->productosSinAlegra) {
            $this->bloqueo('producto_sin_alegra', 'No se encontró en Alegra el producto de: '
                . implode(', ', array_unique(array_filter($this->productosSinAlegra)))
                . '. Escógelo abajo en cada línea (queda guardado para las siguientes facturas).');
        }
        if ($sinFacturar) {
            $this->advertencia('restauracion_fuera', 'No van en la factura (las restauraciones no se facturan, Ajustes): '
                . implode(', ', $sinFacturar) . '.', false);
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
            'description' => mb_substr($nombre, 0, 300),
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

    /**
     * Lo que dice cada línea de la factura. El contador (2026-11-12): "debe ir
     * detallado todo: unidades, nombre y si se puede medidas". La cantidad va
     * aparte (Alegra la muestra como unidades); aquí van el nombre, la versión
     * (medida/tela escogida), las medidas del producto y la tela pedida.
     */
    private function nombreDe($item): string
    {
        $nombre  = $item->producto?->nombre ?: ($item->nombre_custom ?: 'Producto');
        $detalle = $this->detalleDe($item);

        return trim($nombre . ($detalle !== '' ? ' · ' . $detalle : ''));
    }

    /** Lo que va en la descripción cuando el ítem de Alegra ya es el mueble: variante, medidas, tela. */
    private function detalleDe($item): string
    {
        $partes = [];

        $variante = trim((string) ($item->variante_texto ?? ''));
        if ($variante !== '') $partes[] = $variante;

        $medidas = $this->medidasDe($item);
        if ($medidas !== '' && ! str_contains(mb_strtolower($variante), mb_strtolower($medidas))) {
            $partes[] = 'Medidas: ' . $medidas;
        }

        $tela = is_array($item->specs_personalizacion ?? null) ? trim((string) ($item->specs_personalizacion['tela'] ?? '')) : '';
        if ($tela !== '' && ! str_contains(mb_strtolower($variante), mb_strtolower($tela))) {
            $partes[] = 'Tela: ' . $tela;
        }

        return implode(' · ', $partes);
    }

    /** Las medidas: las de la ficha del diseño especial o, si no, las del producto del catálogo. */
    private function medidasDe($item): string
    {
        $ficha = is_array($item->ficha_especial ?? null) ? $item->ficha_especial : [];
        $dims = array_values(array_filter(array_map(
            fn ($k) => trim((string) ($ficha[$k] ?? '')),
            ['ancho', 'largo', 'fondo', 'profundidad', 'alto'],
        ), fn ($v) => $v !== ''));
        if ($dims) return implode(' x ', $dims);

        return trim((string) ($item->producto?->medidas ?? ''));
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
                // "Marcar facturado" del detalle de la orden = el facturador ya lo
                // digitó en Alegra como recibo (anticipo de clientes).
                'recibo_en_alegra' => (bool) ($p->facturacion_hecha_at ?? false),
                'va_a_alegra'  => $registrar,
                // Lo que se manda a POST /payments (la factura y el cliente se
                // ponen al registrarlo, cuando ya existen en Alegra).
                'alegra'       => [
                    'date'          => $r['fecha_pagos'] === 'recibido'
                        ? $p->created_at?->timezone('America/Bogota')->toDateString()
                        : now('America/Bogota')->toDateString(),
                    'bankAccount'   => ['id' => $cuenta],
                    'amount'        => round((float) $p->monto, 2),
                    'paymentMethod' => $r['medios_pago'][$p->metodo ?: 'otro'] ?? 'cash',
                    'type'          => 'in',
                    'observations'  => "[DECASA-PAGO-{$p->id}] Recibido el "
                        . $p->created_at?->timezone('America/Bogota')->format('d/m/Y') . ($p->referencia ? ' · ' . $p->referencia : ''),
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
        // El régimen de IVA no se supone (contador, 2026-11-12: "hay personas
        // naturales que a su vez también son responsables de IVA"): el
        // facturador lo escoge la primera vez y queda guardado en el cliente.
        $regimen   = $c?->regimen ?: null;
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
            // Lo mínimo para la DIAN (contador, 2026-11-12): nombre completo,
            // cédula y correo. Una persona natural con una sola palabra no es
            // un nombre completo.
            $nombre = trim((string) $c?->nombre);
            if ($nombre === '') $faltan[] = 'nombre';
            elseif ($persona === 'natural' && count(preg_split('/\s+/', $nombre)) < 2) $faltan[] = 'nombre completo (nombres y apellidos)';
            if (! $regimen)        $faltan[] = 'régimen de IVA';
            if ($r['exigir_correo'] && ! trim((string) $c?->email)) $faltan[] = 'correo';
            if ($direccion === '') $faltan[] = 'direccion';
            if ($ciudad === '')    $faltan[] = 'ciudad';
            if ($depto === '')     $faltan[] = 'departamento';
        }
        // Alegra solo acepta el departamento y el municipio con el nombre de su
        // catálogo: se traducen, y si no se encuentran se pide escogerlos de la lista.
        if (! $consumidorFinal && $depto !== '' && $ciudad !== '') {
            $depOficial = \App\Support\MunicipiosAlegra::departamento($depto);
            $munOficial = \App\Support\MunicipiosAlegra::municipio($depto, $ciudad);
            if (! $depOficial) {
                $faltan[] = 'departamento';
                $this->bloqueo('municipio_no_oficial', "El departamento \"{$depto}\" no está en la lista de la DIAN: escógelo de la lista.");
            } elseif (! $munOficial) {
                $faltan[] = 'ciudad';
                $this->bloqueo('municipio_no_oficial', "El municipio \"{$ciudad}\" no está en {$depOficial} según la DIAN: escógelo de la lista.");
            } else {
                [$depto, $ciudad] = [$depOficial, $munOficial];
            }
        }
        if ($faltan) {
            $this->bloqueo('datos_cliente', 'Faltan datos del cliente para la DIAN: ' . implode(', ', $faltan) . '. Complétalos arriba.');
        }
        if (! $consumidorFinal && ! $r['exigir_correo'] && ! $c?->email) {
            $this->advertencia('sin_correo', 'El cliente no tiene correo: la DIAN no le podrá mandar la factura.', false);
        }

        [$primero, $apellidos] = self::partirNombre((string) $c?->nombre);
        $contacto = [
            'identificationObject' => array_filter([
                'type' => $tipoDoc, 'number' => $documento, 'dv' => $tipoDoc === 'NIT' ? $dv : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'kindOfPerson' => $persona === 'juridica' ? 'LEGAL_ENTITY' : 'PERSON_ENTITY',
            // Sin régimen no se llega aquí a facturar (es un bloqueo); el valor es solo para la vista previa.
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
            $this->bloqueo('sin_conexion', 'Alegra no está conectado (falta ALEGRA_TOKEN en el servidor, y ALEGRA_EMAIL si es el token clásico).');
        }
        // El producto "comodín" hace falta si alguna línea lo usa: todo en modo
        // genérico, o un diseño especial sin producto del catálogo.
        $usanComodin = array_filter($lineas, fn ($l) => ! isset($l['alegra_item']));
        if ($usanComodin && ! $r['item_producto_id']) {
            $this->bloqueo('sin_item', $r['items_alegra'] === 'por_producto'
                ? 'Esta orden tiene muebles que no son del catálogo: falta escoger en Ajustes el producto de Alegra para diseños especiales.'
                : 'Falta escoger en Ajustes el producto de Alegra con que se facturan las ventas.');
        }
        $llevaIva = array_filter($lineas, fn ($l) => $l['iva_pct'] > 0);
        if ($llevaIva && ! $r['impuesto_iva_id']) {
            $this->bloqueo('sin_iva', 'Falta escoger en Ajustes el impuesto de IVA de Alegra.');
        }
        $this->revisarCatalogo($lineas, $pagos);

        $numeracion = ReglasFacturacion::numeracionDe($this->orden->tienda_id);
        if (! $numeracion) {
            $this->advertencia('sin_numeracion', 'No hay numeración escogida para esta tienda: Alegra usará la preferida de la empresa.', false);
        } else {
            $this->revisarNumeracion($numeracion);
        }
    }

    /**
     * Lo escogido en Ajustes contra lo que hay HOY en Alegra. Si Alegra no
     * responde, no se frena: igual valida al emitir.
     */
    private function revisarCatalogo(array $lineas, array $pagos): void
    {
        if (! \App\Services\Alegra\ClienteAlegra::configurado()) return;
        $r = ReglasFacturacion::todo();
        $cat = app(\App\Services\Alegra\CatalogoAlegra::class);

        // El impuesto: activo y del mismo porcentaje con que se calcula la base.
        $llevaIva = array_filter($lineas, fn ($l) => $l['iva_pct'] > 0);
        if ($llevaIva && $r['impuesto_iva_id'] && ($impuestos = $cat->impuestos()) !== null) {
            $t = $impuestos[(string) $r['impuesto_iva_id']] ?? null;
            if (! $t || ! \App\Services\Alegra\CatalogoAlegra::activo($t)) {
                $this->bloqueo('iva_no_sirve', 'El impuesto de IVA escogido en Ajustes ya no está activo en Alegra. Escoge otro.');
            } elseif (isset($t['percentage']) && abs((float) $t['percentage'] - (float) $r['iva_pct']) > 0.001) {
                $this->bloqueo('iva_distinto', "El impuesto escogido en Ajustes (\"" . ($t['name'] ?? '') . "\", {$t['percentage']} %) no es del "
                    . "{$r['iva_pct']} % con que se calculan los precios: la factura no cuadraría. Escoge el IVA de {$r['iva_pct']} %.");
            }
        }

        // Los productos de Alegra de cada línea: que existan y estén activos. El
        // aviso de inventario es solo para el "comodín" en modo genérico: en
        // Decasa cada mueble lleva inventario en Alegra a propósito (por bodega).
        $usados = array_unique(array_filter(array_map(fn ($l) => $l['alegra']['id'] ?? null, $lineas)));
        foreach ($usados as $id) {
            $item = $cat->item((string) $id);
            if ($item === null) continue;
            if (! empty($item['_no_existe']) || ! \App\Services\Alegra\CatalogoAlegra::activo($item)) {
                $this->bloqueo('item_no_sirve', 'El producto de Alegra "' . ($item['name'] ?? "id {$id}") . '" ya no existe o está inactivo. Escoge otro.');
            } elseif ($r['items_alegra'] === 'generico' && \App\Services\Alegra\CatalogoAlegra::llevaInventario($item)) {
                $this->advertencia('item_con_inventario', 'El producto de Alegra "' . ($item['name'] ?? $id) . '" lleva inventario: '
                    . 'cada factura le descuenta existencias allá y, si no deja inventario negativo, la factura falla. Conviene uno sin inventario.', false);
            }
        }

        // Las cajas/bancos de los pagos que se van a registrar.
        $cuentasUsadas = array_unique(array_filter(array_map(fn ($p) => ($p['va_a_alegra'] ?? false) ? $p['cuenta'] : null, $pagos)));
        if ($cuentasUsadas && ($cuentas = $cat->cuentas()) !== null) {
            foreach ($cuentasUsadas as $id) {
                $c = $cuentas[(string) $id] ?? null;
                if (! $c || ! \App\Services\Alegra\CatalogoAlegra::activo($c)) {
                    $this->bloqueo('cuenta_no_sirve', "Una caja o banco escogido en Ajustes (id {$id}) ya no está activo en Alegra. Escoge otro.");
                }
            }
        }
    }

    /**
     * La resolución DIAN que se va a usar: vencida, agotada o inactiva no deja
     * emitir (la DIAN la rechazaría). En modo borrador no se gasta número, así
     * que solo se avisa. Por vencer o con pocos números: aviso, sin frenar.
     */
    private function revisarNumeracion(string $id): void
    {
        $estado = \App\Services\Alegra\EstadoNumeracion::consultar(app(\App\Services\Alegra\ClienteAlegra::class), $id);
        $this->numeracion = $estado;
        if (! $estado) return; // no se pudo preguntar: Alegra igual la revisa al emitir

        if (! $estado['sirve']) {
            ReglasFacturacion::emite()
                ? $this->bloqueo('numeracion_' . $estado['estado'], $estado['mensaje'] . ' Escoge otra en Ajustes.')
                : $this->advertencia('numeracion_' . $estado['estado'], $estado['mensaje'] . ' (En borrador no se usa, pero al emitir la DIAN la rechazaría.)', false);
        } elseif (in_array($estado['estado'], ['por_vencer', 'pocos'], true)) {
            $this->advertencia('numeracion_' . $estado['estado'], $estado['mensaje'], false);
        }
    }

    /** Lo que se supo de la numeración al armar (para mostrarlo en la revisión). */
    private ?array $numeracion = null;

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

    /**
     * Cómo lleva Decasa los pagos (contador, 2026-11-11): cada pago que entra lo
     * digita el facturador en Alegra como recibo, como **anticipo de clientes**
     * (en el detalle de la orden: Tomar → Marcar facturado), y al hacer la
     * factura se cruzan esos anticipos. Si un pago todavía no está digitado,
     * no hay anticipo que cruzar: se avisa antes de facturar.
     *
     * Antes este aviso decía "revisa que no exista ya la factura": leía la marca
     * como si fuera una factura, y casi toda orden la tiene.
     */
    private function revisarRecibos(array $pagos): void
    {
        if (! Esquema::columna('pagos', 'facturacion_hecha_at') || ReglasFacturacion::get('registrar_pagos')) return;

        $sinRecibo = array_values(array_filter($pagos, fn ($p) => ! $p['recibo_en_alegra']));
        if ($sinRecibo) {
            $detalle = implode(', ', array_map(fn ($p) => self::pesos($p['monto']) . ' del ' . ($p['fecha'] ?? '?'), $sinRecibo));
            $this->advertencia('recibo_sin_registrar', count($sinRecibo) . " pago(s) todavía no están digitados en Alegra como recibo ({$detalle}). "
                . 'Digítalos como anticipo y márcalos en el detalle de la orden, o el cruce no va a cuadrar.');
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
