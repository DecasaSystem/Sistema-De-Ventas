<?php

namespace App\Services\Finanzas;

use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto costaron en MATERIALES los muebles vendidos en el mes, ESTIMADO con
 * las fichas técnicas (`fichas_tecnicas.costo_materiales` del producto).
 *
 * Solo materiales: la mano de obra del taller ya sale en la nómina real, y
 * sumar también la de la ficha la restaría dos veces. Tampoco se suma con las
 * Compras: las compras son caja (cuándo salió la plata) y esto es devengado
 * (qué costó lo que se vendió). Cada uno va en su lente.
 *
 * Es una estimación y así se muestra: con su COBERTURA (qué parte de lo
 * vendido tiene ficha). A lo que no tiene ficha se le aplica la misma
 * proporción costo/precio de lo que sí tiene. Las restauraciones no tienen
 * ficha (Fase 1b del cotizador) y van aparte, sin costo estimado.
 */
class CostoProduccion
{
    /**
     * @return array<string, array> [mes => costo, cobertura, ventas_items, ventas_con_ficha]
     */
    public static function porMes(string $desde, string $hasta, ?int $tiendaId = null): array
    {
        if (! ConfigFinanzas::get('usar_costo_fichas') || ! self::hayFichas()) {
            return [];
        }

        // Si un producto tiene varias fichas, el promedio de sus materiales.
        $fichas = DB::table('fichas_tecnicas')
            ->whereNotNull('producto_id')
            ->where('costo_materiales', '>', 0)
            ->groupBy('producto_id')
            ->selectRaw('producto_id, AVG(costo_materiales) AS costo');

        $filas = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->leftJoinSub($fichas, 'f', 'f.producto_id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', Periodo::rangoUtc($desde, $hasta))
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->where(fn ($q) => $q->whereNull('oi.es_restauracion')->orWhere('oi.es_restauracion', false))
            ->when($tiendaId, fn ($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw(Periodo::sqlMes('o.created_at') . ' AS mes')
            ->selectRaw('SUM(oi.cantidad * oi.precio_unitario) AS ventas_items')
            ->selectRaw('SUM(CASE WHEN f.costo IS NOT NULL THEN oi.cantidad * oi.precio_unitario ELSE 0 END) AS ventas_con_ficha')
            ->selectRaw('SUM(CASE WHEN f.costo IS NOT NULL THEN oi.cantidad * f.costo ELSE 0 END) AS costo_con_ficha')
            ->groupBy('mes')
            ->get();

        $out = [];
        foreach ($filas as $f) {
            $ventas    = (float) $f->ventas_items;
            $conFicha  = (float) $f->ventas_con_ficha;
            $costoFich = (float) $f->costo_con_ficha;
            $proporcion = $conFicha > 0 ? $costoFich / $conFicha : 0.0;

            $out[$f->mes] = [
                'costo'            => round($costoFich + ($ventas - $conFicha) * $proporcion),
                'costo_con_ficha'  => round($costoFich),
                'cobertura'        => $ventas > 0 ? round($conFicha / $ventas, 3) : 0.0,
                'ventas_items'     => $ventas,
                'ventas_con_ficha' => $conFicha,
            ];
        }

        return $out;
    }

    private static ?bool $hay = null;

    private static function hayFichas(): bool
    {
        return self::$hay ??= Schema::hasTable('fichas_tecnicas') && Schema::hasColumn('fichas_tecnicas', 'producto_id');
    }

    public static function olvidarCache(): void
    {
        self::$hay = null;
    }
}
