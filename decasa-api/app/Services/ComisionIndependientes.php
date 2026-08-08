<?php

namespace App\Services;

use App\Http\Controllers\StatsController;
use App\Models\Orden;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La comisión de los vendedores independientes.
 *
 * No tiene nada que ver con la de las tiendas. Ahí hay una meta, un pool y un
 * reparto entre asesores; aquí es un porcentaje fijo por venta, así que al
 * final del mes no hay bolsa que repartir: se suma y ya.
 *
 * La regla:
 *
 *   Cada venta o restauración de un independiente paga
 *     5%  a cada independiente   (es mutuo: cobran sobre lo de los dos)
 *     5%  al almacén             (solo si la venta se compartió con uno)
 *
 *   Todo sobre el valor completo de la venta, no sobre mitades.
 *
 * Lo de las mitades es otra cosa: a la META del almacén le suma la mitad de
 * la venta, y solo si es venta —una restauración compartida le paga su 5% al
 * almacén pero no le cuenta para la meta.
 */
class ComisionIndependientes
{
    /** Lo que cobra cada independiente, y cada almacén que ayudó. */
    public const PORCENTAJE = 0.05;

    /**
     * @return array{
     *   mes:string, base:float, porcentaje:float,
     *   independientes:array, almacenes:array, ordenes:array
     * }
     */
    public static function delMes(string $mes): array
    {
        [$desde, $hasta] = self::rangoUtc($mes);

        $independientes = Usuario::where('independiente', true)->get(['id', 'nombre']);
        if ($independientes->isEmpty()) {
            return ['mes' => $mes, 'base' => 0.0, 'porcentaje' => self::PORCENTAJE,
                    'independientes' => [], 'almacenes' => [], 'ordenes' => []];
        }

        $ordenes = DB::table('ordenes as o')
            ->join('usuarios as u', 'u.id', '=', 'o.vendedor_id')
            ->leftJoin('tiendas as t', 't.id', '=', 'o.tienda_abonada_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->whereIn('o.vendedor_id', $independientes->pluck('id'))
            ->whereBetween('o.created_at', [$desde, $hasta])
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            ->select(
                'o.id', 'o.numero_orden', 'o.serie', 'o.serie_numero',
                // Si la comparte con otro asesor, solo la mitad es suya: es el
                // mismo criterio que usan sus estadisticas y su comision.
                DB::raw('CASE WHEN o.es_compartida = 1 THEN o.valor_total / 2 ELSE o.valor_total END as valor_total'),
                'o.estado', 'o.created_at', 'o.tienda_abonada_id',
                'u.nombre as vendedor', 'o.vendedor_id',
                't.nombre as almacen', 'c.nombre as cliente'
            )
            ->orderBy('o.created_at')
            ->get();

        // Una restauración no le suma a la meta del almacén aunque se comparta.
        $idsRestauracion = self::idsDeRestauracion($ordenes->pluck('id')->all());

        $base = (float) $ordenes->sum('valor_total');

        // Los dos cobran sobre lo mismo: todo lo que vendieron entre ambos.
        $porIndependiente = $independientes->map(fn ($u) => [
            'vendedor_id' => $u->id,
            'nombre'      => $u->nombre,
            'vendio'      => (float) $ordenes->where('vendedor_id', $u->id)->sum('valor_total'),
            'comision'    => round($base * self::PORCENTAJE),
        ])->values()->all();

        // Cada almacén cobra sobre lo que se compartió con él.
        $almacenes = $ordenes->whereNotNull('tienda_abonada_id')
            ->groupBy('tienda_abonada_id')
            ->map(function ($grupo) use ($idsRestauracion) {
                $compartido = (float) $grupo->sum('valor_total');
                // A la meta solo le suma la mitad de lo que NO es restauración.
                $paraMeta = (float) $grupo
                    ->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                    ->sum('valor_total') / 2;

                return [
                    'tienda_id'   => (int) $grupo->first()->tienda_abonada_id,
                    'nombre'      => $grupo->first()->almacen,
                    'compartido'  => $compartido,
                    'comision'    => round($compartido * self::PORCENTAJE),
                    'suma_a_meta' => $paraMeta,
                    'ordenes'     => $grupo->count(),
                ];
            })->values()->all();

        return [
            'mes'            => $mes,
            'base'           => $base,
            'porcentaje'     => self::PORCENTAJE,
            'independientes' => $porIndependiente,
            'almacenes'      => $almacenes,
            'ordenes'        => $ordenes->map(fn ($o) => [
                'id'             => $o->id,
                'referencia'     => $o->serie ? "{$o->serie}-{$o->serie_numero}" : ('#' . ($o->numero_orden ?? $o->id)),
                'cliente'        => $o->cliente,
                'vendedor'       => $o->vendedor,
                'valor'          => (float) $o->valor_total,
                'estado'         => $o->estado,
                'fecha'          => $o->created_at,
                'almacen'        => $o->almacen,
                'es_restauracion'=> in_array($o->id, $idsRestauracion, true),
                'suma_a_meta'    => ($o->tienda_abonada_id && ! in_array($o->id, $idsRestauracion, true))
                                    ? (float) $o->valor_total / 2 : 0.0,
            ])->values()->all(),
        ];
    }

    /**
     * Lo que los independientes le abonaron a cada tienda para su META.
     *
     * Es la mitad de cada venta compartida, PERO solo de las ventas: una
     * restauración compartida le paga su 5% al almacén y no le cuenta para la
     * meta. Vive aquí y no en el controlador para que la meta y lo que se
     * muestra en pantalla salgan del mismo sitio: estaban duplicados y decían
     * cosas distintas.
     *
     * @return array<string,float>  ['tienda_mes' => monto]
     */
    public static function abonadoParaMeta(): array
    {
        return DB::table('ordenes')
            ->whereNotNull('tienda_abonada_id')
            ->whereNotIn('estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            // Fuera las que son íntegramente restauración
            ->whereNotIn('id', function ($q) {
                $q->from('orden_items')->select('orden_id')
                  ->groupBy('orden_id')
                  ->havingRaw('COUNT(*) = SUM(es_restauracion)');
            })
            ->selectRaw(
                "tienda_abonada_id, DATE_FORMAT(CONVERT_TZ(created_at,'+00:00','-05:00'),'%Y-%m') as mes, " .
                'SUM(valor_total) / 2 as total'
            )
            ->groupBy('tienda_abonada_id', 'mes')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->tienda_abonada_id . '_' . $r->mes => (float) $r->total])
            ->all();
    }

    /** Órdenes cuyos ítems son todos restauración. */
    private static function idsDeRestauracion(array $ordenIds): array
    {
        if (! $ordenIds) return [];

        return DB::table('orden_items')
            ->whereIn('orden_id', $ordenIds)
            ->groupBy('orden_id')
            ->selectRaw('orden_id, COUNT(*) as total, SUM(es_restauracion) as restauraciones')
            ->get()
            ->filter(fn ($r) => (int) $r->total > 0 && (int) $r->total === (int) $r->restauraciones)
            ->pluck('orden_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** El mes en hora de Colombia, no en la del servidor. */
    private static function rangoUtc(string $mes): array
    {
        $tz = StatsController::TZ_NEGOCIO;
        return [
            Carbon::parse($mes . '-01', $tz)->startOfMonth()->setTimezone('UTC')->toDateTimeString(),
            Carbon::parse($mes . '-01', $tz)->endOfMonth()->setTimezone('UTC')->toDateTimeString(),
        ];
    }
}
