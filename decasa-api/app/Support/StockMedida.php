<?php

namespace App\Support;

use App\Models\InventarioVarianteConfig;
use App\Models\Orden;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto hay libre de una medida (u otra opción: con baúl, eléctrica...) en
 * una tienda.
 *
 * El stock de un producto se reparte por dos lados que no dependen uno del
 * otro: por tela (`inventario_variantes`) y por medida
 * (`inventario_variante_configs`). Se sabe que hay dos camas de 1.40 aunque no
 * se haya anotado de qué tela es cada una. Las ventas nunca apartaban la
 * medida: solo la tela y el total. Por eso dos vendedores podían venderle la
 * última 1.40 a dos clientes, y al entregar `StockVariantes::cuadrar`
 * adivinaba de qué medida descontar (la del montón más grande, no la vendida).
 *
 * Lo apartado de una medida NO se lleva en un contador: se cuenta de las
 * órdenes abiertas, con la misma regla que la auditoría de descuadres
 * (`InventarioController::calcularReservadosFantasma`). Un contador nuevo
 * habría arrancado en cero con todas las órdenes de antes sin contar, y al
 * entregarlas soltaría una reserva que nunca hicieron (la de otra orden).
 * Contado así, las órdenes viejas que ya decían la medida cuentan solas.
 *
 * Lo que sí cambia al entregar es lo DISPONIBLE de la medida: sale el mueble,
 * baja (EntregaService).
 */
class StockMedida
{
    /**
     * Unidades de esa medida que las órdenes abiertas todavía esperan en esa
     * tienda. `$sinOrden` deja fuera una orden (la que se está confirmando o
     * editando: lo suyo no le compite a ella misma).
     */
    public static function apartado(int $configId, int $tiendaId, ?int $sinOrden = null): int
    {
        return (int) DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->where('oi.combo_config_id', $configId)
            ->where('oi.es_personalizado', false)
            ->where('oi.producto_unico', false)
            ->whereNull('oi.devuelto_en')
            ->whereNotIn('o.estado', Orden::ESTADOS_SIN_RESERVA)
            ->whereRaw('COALESCE(oi.tienda_origen_id, o.tienda_id) = ?', [$tiendaId])
            ->when($sinOrden, fn ($q) => $q->where('o.id', '!=', $sinOrden))
            ->sum(DB::raw('CASE WHEN oi.cantidad > COALESCE(oi.cantidad_entregada, 0) THEN oi.cantidad - COALESCE(oi.cantidad_entregada, 0) ELSE 0 END'));
    }

    /**
     * Lo que se puede vender de esa medida. `null` cuando en esa tienda el
     * producto no lleva el stock por medida: la medida no tiene fila, o
     * ninguna opción de ese tipo tiene nada anotado (las filas se crean en 0
     * al configurar las medidas, y hay muebles cuyo stock se anotó solo al
     * producto). Ahí no se frena por la medida: manda el total, como siempre.
     *
     * Con `$bloquear` se toma la fila con lockForUpdate: dos ventas de la
     * última 1.40 a la vez se ponen en fila, y la segunda ya cuenta a la
     * primera.
     */
    public static function libre(int $configId, int $tiendaId, ?int $sinOrden = null, bool $bloquear = false): ?int
    {
        $q = InventarioVarianteConfig::where('config_id', $configId)->where('tienda_id', $tiendaId);
        $fila = $bloquear ? $q->lockForUpdate()->first() : $q->first();
        if (! $fila) return null;

        if ((int) $fila->cantidad_disponible <= 0 && ! self::seLlevaPorMedida($configId, $tiendaId)) {
            return null;
        }

        return max(0, (int) $fila->cantidad_disponible - self::apartado($configId, $tiendaId, $sinOrden));
    }

    /** ¿Alguna opción del mismo tipo (las medidas de esa cama) tiene stock anotado ahí? */
    private static function seLlevaPorMedida(int $configId, int $tiendaId): bool
    {
        $cfg = DB::table('producto_variante_configs')->where('id', $configId)->first(['producto_id', 'tipo_variante_id']);
        if (! $cfg) return false;

        return DB::table('inventario_variante_configs as ivc')
            ->join('producto_variante_configs as pvc', 'pvc.id', '=', 'ivc.config_id')
            ->where('pvc.producto_id', $cfg->producto_id)
            ->where('pvc.tipo_variante_id', $cfg->tipo_variante_id)
            ->where('ivc.tienda_id', $tiendaId)
            ->where('ivc.cantidad_disponible', '>', 0)
            ->exists();
    }

    /**
     * El mueble salió (o volvió): mueve lo disponible de su medida. Nunca por
     * debajo de cero.
     */
    public static function mover(?int $configId, int $tiendaId, int $delta): void
    {
        if (! $configId || $delta === 0) return;

        $fila = InventarioVarianteConfig::where('config_id', $configId)->where('tienda_id', $tiendaId)->first();
        if (! $fila) return;

        $fila->update(['cantidad_disponible' => max(0, (int) $fila->cantidad_disponible + $delta)]);
    }
}
