<?php

namespace App\Services;

use App\Events\OrdenActualizada;
use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\DespachoItem;
use App\Models\FacturaElectronica;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\ProductoVarianteConfig;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Support\Esquema;
use App\Support\RangoDeUsuarios;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Agregar a una orden YA ENTREGADA un producto que se le olvidó al vendedor
 * (usuario, 2026-11-24). Solo un super admin.
 *
 * El caso: el cliente se llevó todo, pero en la orden falta algo que sí se le
 * agregó (un cojín, una mesa, la medida más grande). La orden vale menos de lo
 * que se vendió y no cuadran las bonificaciones del sistema con las cuentas de
 * la tienda. Editar orden no deja agregar productos a una orden que ya salió
 * (`OrdenController::update`, ESTADOS_SOLO_PAPELES): eso sigue igual para
 * todos; esta es una puerta aparte, solo para el super admin, para que "no se
 * enloquezca todo".
 *
 * Lo que hace, todo por los caminos de siempre:
 *  - El producto entra a la orden YA ENTREGADO: queda como una entrega directa
 *    más (`EntregaService::entregarEnMostrador`), con el motivo en el acta y la
 *    fecha de la última entrega de la orden, para no mover el día que cuenta
 *    para facturar. La orden sigue en "entregado".
 *  - Si salió de una tienda, baja el inventario de esa tienda (se aparta y se
 *    entrega, como cualquier venta). Si no salió del inventario (ya estaba por
 *    fuera, se hizo aparte), no toca stock: queda como mueble ya hecho
 *    (`producto_unico`), que ni aparta ni va al taller.
 *  - El total se rehace (`Orden::recalcularTotal`) y la bonificación lo sigue
 *    (`ComisionController::sincronizarValorOrden`): sin pagar, sube con la
 *    orden; ya pagada, la diferencia se paga aparte en el próximo pago (regla
 *    del 2026-11-19). La regla del 50 % no cambia: si el cliente no ha pagado
 *    lo nuevo, puede quedar por debajo.
 *  - Facturación: si la orden no se ha facturado, la factura sale con el
 *    producto nuevo (se arma con los productos de la orden al facturar). Si ya
 *    se facturó, la factura no cambia sola: aparece en "Con diferencias" y le
 *    llega el aviso de cambio de dinero (AvisoFacturacion), como cualquier
 *    corrección de precio.
 *  - Queda en el historial de la orden.
 */
class AgregarProductoEntregado
{
    /**
     * @param array{tipo:string, producto_id?:?int, variante_id?:?int, combo_config_id?:?int,
     *              sale_de?:string, tienda_id?:?int, nombre?:?string, cantidad:int,
     *              precio_unitario:float|int|string, motivo:string} $data
     * @return array{orden: Orden, item: OrdenItem, avisos: string[]}
     */
    public static function aplicar(Orden $orden, array $data, Usuario $quien): array
    {
        if (! RangoDeUsuarios::esSuperAdmin($quien)) {
            throw new HttpResponseException(response()->json(['message' => 'Solo un super admin puede agregar productos a una orden ya entregada.'], 403));
        }
        if ($orden->estado !== 'entregado') {
            self::fallar('Solo se agregan productos así a una orden entregada. Si todavía no se ha entregado, usa Editar orden.');
        }
        if ($orden->tieneDespachoActivo()) {
            self::fallar('Esta orden tiene una entrega abierta: termínala o deshazla primero.');
        }

        $tipo     = $data['tipo'];
        $cantidad = (int) $data['cantidad'];
        $precio   = round((float) $data['precio_unitario'], 2);
        $motivo   = trim((string) $data['motivo']);
        $saleDe   = $tipo === 'catalogo' ? ($data['sale_de'] ?? 'tienda') : 'ninguna';

        if ($cantidad < 1) self::fallar('La cantidad tiene que ser 1 o más.');
        if ($precio <= 0) self::fallar('Ponle precio al producto: es lo que le falta a la orden.');

        // Una restauración (serie R o todo restauración) es otra cosa para
        // bonificaciones y facturación: ventas y restauraciones van en órdenes
        // separadas (usuario, 2026-11-12). Ahí solo entra un trabajo más.
        $esRestauracion = $orden->esSoloRestauracion();
        if ($esRestauracion && $tipo === 'catalogo') {
            self::fallar('Esta orden es de restauración: un producto del catálogo va en una orden de venta aparte. Aquí solo se agrega otro trabajo (opción "Otro").');
        }

        $producto = null;
        $varianteId = null;
        $comboId = null;
        $tiendaSale = null;

        if ($tipo === 'catalogo') {
            $producto = Producto::find($data['producto_id'] ?? 0);
            if (! $producto) self::fallar('Escoge el producto.');

            $varianteId = ! empty($data['variante_id']) ? (int) $data['variante_id'] : null;
            $comboId    = ! empty($data['combo_config_id']) ? (int) $data['combo_config_id'] : null;
            if ($varianteId && ! ProductoVariante::whereKey($varianteId)->where('producto_id', $producto->id)->exists()) {
                self::fallar('Esa tela o variante no es de ese producto.');
            }
            if ($comboId && ! ProductoVarianteConfig::whereKey($comboId)->where('producto_id', $producto->id)->exists()) {
                self::fallar('Esa opción no es de ese producto.');
            }

            if ($saleDe === 'tienda') {
                $tiendaSale = Tienda::find($data['tienda_id'] ?? 0);
                if (! $tiendaSale) self::fallar('Escoge de qué tienda salió, o marca que no salió del inventario.');
            }
        } else {
            if (mb_strlen(trim((string) ($data['nombre'] ?? ''))) < 2) self::fallar('Escribe qué es lo que se agrega.');
        }

        $avisos = [];

        $resultado = DB::transaction(function () use ($orden, $tipo, $producto, $varianteId, $comboId, $tiendaSale, $saleDe, $cantidad, $precio, $motivo, $data, $quien, $esRestauracion) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $valorAntes = (float) $orden->valor_total;

            // Lo que se le entregó: de inventario, se cuenta lo libre de verdad
            // (lo apartado para otras órdenes no es de esta).
            if ($tiendaSale) {
                $libres = GarantiaService::stockLibre($producto->id, $varianteId, $comboId, (int) $tiendaSale->id);
                if ($libres < $cantidad) {
                    self::fallar("En {$tiendaSale->nombre} hay {$libres} libre" . ($libres === 1 ? '' : 's')
                        . " de {$producto->nombre}. Si no salió del inventario (ya se había descontado o se hizo aparte), escoge \"No salió del inventario\".");
                }
            }

            $deInventario = (bool) $tiendaSale;
            $atributos = [
                'orden_id'         => $orden->id,
                'producto_id'      => $producto?->id,
                'variante_id'      => $varianteId,
                'combo_config_id'  => $comboId,
                'variante_detalle' => $producto ? OrdenItem::detalleDeVariante(null, $comboId, $varianteId) : null,
                'nombre_custom'    => $producto ? null : trim((string) $data['nombre']),
                'cantidad'         => $cantidad,
                'precio_unitario'  => $precio,
                // De inventario: un ítem de catálogo normal. Si no salió del
                // inventario: "mueble ya hecho", que ni aparta stock ni va al
                // taller (OrdenItem::vaAlTaller) y no pide declaración.
                'es_personalizado' => ! $deInventario,
                'fabricar_pedido'  => false,
                'producto_unico'   => ! $deInventario,
                'es_restauracion'  => $esRestauracion,
                'tienda_origen_id' => $deInventario && (int) $tiendaSale->id !== (int) $orden->tienda_id ? (int) $tiendaSale->id : null,
            ];
            if (Esquema::columna('orden_items', 'fecha_entrega_prom')) {
                $atributos['fecha_entrega_prom'] = $orden->fecha_sugerida_vendedor?->toDateString();
            }
            $item = OrdenItem::create($atributos);

            if ($deInventario) {
                GarantiaService::apartar($item, (int) $tiendaSale->id, $quien, "Orden {$orden->referencia}: producto agregado después de entregada");
            }

            // La entrega: el cliente ya lo tiene. Por el camino de siempre, así
            // baja el stock (si es de inventario) y suma a lo entregado.
            $entrega = EntregaService::entregarEnMostrador(
                $orden, [$item->id => $cantidad], $quien,
                "Agregado a la orden ya entregada por {$quien->nombre}: {$motivo}"
            );

            // Con la fecha de la última entrega de verdad: la orden se entregó
            // ese día, y es la fecha que mira Facturación (QueSeFactura).
            $ultima = DespachoItem::where('orden_id', $orden->id)
                ->where('id', '!=', $entrega->id)
                ->whereNotNull('entregado_at')
                ->max('entregado_at');
            if ($ultima) {
                $entrega->update(['entregado_at' => $ultima]);
            }

            $orden->refresh();
            $orden->recalcularTotal();
            $orden->refresh();
            // Por si algo la movió: ya se entregó todo.
            if ($orden->estado !== 'entregado' && $orden->todoEntregado()) {
                $orden->update(['estado' => 'entregado']);
            }

            $nombre = $producto?->nombre ?? $atributos['nombre_custom'];
            $detalle = $atributos['variante_detalle'] ? " ({$atributos['variante_detalle']})" : '';
            $origen  = $deInventario ? " · salió de {$tiendaSale->nombre}" : ' · no salió del inventario';
            $cambios = [
                [
                    'campo'   => "item_nuevo_{$item->id}",
                    'label'   => 'Ítem agregado a la orden entregada',
                    'antes'   => null,
                    'despues' => "{$nombre}{$detalle} × {$cantidad} @ $" . number_format($precio, 0, ',', '.') . $origen . " — {$motivo}",
                ],
            ];
            if ((float) $orden->valor_total !== $valorAntes) {
                $cambios[] = ['campo' => 'valor_total', 'label' => 'Total de la orden', 'antes' => $valorAntes, 'despues' => (float) $orden->valor_total];
            }
            OrdenEdicion::create(['orden_id' => $orden->id, 'usuario_id' => $quien->id, 'cambios' => $cambios]);

            return compact('orden', 'item', 'cambios', 'deInventario');
        });

        /** @var Orden $orden */
        $orden = $resultado['orden']->fresh(['cliente', 'vendedor:id,nombre', 'pagos']);

        // El valor subió: la bonificación lo sigue (o, ya pagada, la
        // diferencia se paga aparte).
        ComisionController::sincronizarValorOrden($orden);

        $pagadas = Comision::where('orden_id', $orden->id)->where('estado', 'pagada')->with('vendedor:id,nombre')->get();
        if ($pagadas->isNotEmpty()) {
            $quienes = $pagadas->pluck('vendedor.nombre')->filter()->unique()->join(', ', ' y ');
            $avisos[] = "La bonificación de {$quienes} ya estaba pagada: la diferencia de lo que subió se paga aparte, en el próximo pago.";
        }

        if (Esquema::tabla('facturas_electronicas')) {
            $factura = FacturaElectronica::where('orden_id', $orden->id)->whereNull('pago_id')
                ->whereIn('estado', FacturaElectronica::ESTADOS_HECHOS)->latest('id')->first();
            if ($factura) {
                $avisos[] = 'Ya tiene la factura electrónica ' . ($factura->numero ?: '(sin número)')
                    . ': no cambia sola. Queda en Facturación → "Con diferencias" para facturar la diferencia en Alegra.';
            }
        }

        $saldo = $orden->saldoPendiente();
        if ($saldo > 0.5) {
            $avisos[] = 'La orden queda debiendo $' . number_format($saldo, 0, ',', '.')
                . '. Si el cliente ya lo pagó, regístralo con "Registrar pago".';
        }

        try {
            AvisoFacturacion::cambioDeDinero($orden, $quien, $resultado['cambios'], 'producto agregado a una orden entregada');
        } catch (\Throwable $e) {
            report($e);
        }

        // A quien la vendió (y a quien la compartió): su venta cambió.
        $cliente = $orden->cliente?->nombre ?? '';
        foreach (array_unique(array_filter([(int) $orden->vendedor_id, (int) $orden->covendedor_id])) as $uid) {
            if ($uid === (int) $quien->id) continue;
            NotificacionService::crear(
                'orden_editada',
                'Se agregó un producto a tu venta',
                "Orden {$orden->referencia} ({$cliente}): {$quien->nombre} agregó " . ($resultado['item']->producto?->nombre ?? $resultado['item']->nombre_custom)
                    . '. Total: $' . number_format((float) $orden->valor_total, 0, ',', '.') . '.',
                ['orden_id' => $orden->id],
                $uid,
            );
        }

        try {
            event(new OrdenActualizada($orden->id, (int) $orden->tienda_id, $orden->estado, $cliente));
        } catch (\Throwable $e) {
            report($e);
        }

        return ['orden' => $orden, 'item' => $resultado['item'], 'avisos' => $avisos];
    }

    private static function fallar(string $mensaje): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensaje], 422));
    }
}
