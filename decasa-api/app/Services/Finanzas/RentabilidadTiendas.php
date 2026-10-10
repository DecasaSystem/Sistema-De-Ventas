<?php

namespace App\Services\Finanzas;

use App\Models\Gasto;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿Cuánto deja cada tienda? Dos cifras, porque el reparto siempre se discute:
 *
 * - CONTRIBUCIÓN (antes del reparto) — la más honesta para decidir si una
 *   tienda se sostiene:
 *     ventas netas − materiales de lo que vendió − nómina de su gente de ventas
 *     − sus comisiones − sus gastos (los que se registraron con esa tienda)
 * - UTILIDAD (después del reparto): menos su parte de lo general —taller,
 *   administración, gastos sin tienda, franquicia—, repartido según Finanzas →
 *   Ajustes (por ventas, en partes iguales o sin repartir).
 *
 * La venta es de la tienda de la orden (`ordenes.tienda_id`), como en Reportes.
 */
class RentabilidadTiendas
{
    public static function mes(string $mes): array
    {
        $rango = Periodo::rangoUtc($mes, $mes);
        $divisor = ConfigFinanzas::divisorIva();
        $sinIva = ConfigFinanzas::get('fv2_sin_iva_no_gravada') && \App\Support\Esquema::columna('ordenes', 'sin_descontar_iva')
            ? 'COALESCE(o.sin_descontar_iva, 0) = 1' : '1 = 0';

        $ventas = DB::table('ordenes as o')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->selectRaw("o.tienda_id, SUM(o.valor_total) AS vendido,
                SUM(CASE WHEN {$sinIva} THEN o.valor_total ELSE 0 END) AS no_gravado, COUNT(*) AS ordenes")
            ->groupBy('o.tienda_id')->get()->keyBy('tienda_id');

        $materiales = self::materialesPorTienda($rango);
        $comisiones = FuenteComisiones::causadaPorMes($mes, $mes)[$mes]['por_tienda'] ?? [];
        $nomina     = FuenteNomina::porMes($mes, $mes)[$mes] ?? ['por_usuario' => [], 'costo' => 0];
        $gastos     = self::gastosPorTienda($mes);
        $er         = EstadoResultados::mes($mes);

        // La nómina de ventas va a la tienda de cada persona; el resto es general.
        $usuarios = Usuario::whereIn('id', array_keys($nomina['por_usuario']))->get(['id', 'tienda_default_id', 'rol', 'rol_id', 'no_usa_programa']);
        $nominaTienda = [];
        $nominaDirecta = 0.0;
        foreach ($usuarios as $u) {
            if (FuenteNomina::areaDe($u) !== 'ventas' || ! $u->tienda_default_id) continue;
            $v = (float) $nomina['por_usuario'][$u->id];
            $nominaTienda[$u->tienda_default_id] = ($nominaTienda[$u->tienda_default_id] ?? 0) + $v;
            $nominaDirecta += $v;
        }

        $tiendas = DB::table('tiendas')
            ->where(fn ($q) => $q->where('activa', true)->orWhereIn('id', $ventas->keys()->filter()->all() ?: [0]))
            ->get(['id', 'nombre']);

        $filas = [];
        foreach ($tiendas as $t) {
            $v = $ventas[$t->id] ?? null;
            $vendido = (float) ($v->vendido ?? 0);
            $gravado = $vendido - (float) ($v->no_gravado ?? 0);
            $netas = $vendido - ($gravado - $gravado / $divisor);
            $directos = (float) ($materiales[$t->id] ?? 0) + (float) ($nominaTienda[$t->id] ?? 0)
                      + (float) ($comisiones[$t->id] ?? 0) + (float) ($gastos[$t->id] ?? 0);

            $filas[] = [
                'tienda_id'    => $t->id,
                'nombre'       => $t->nombre,
                'ventas'       => round($vendido),
                'ordenes'      => (int) ($v->ordenes ?? 0),
                'netas'        => round($netas),
                'materiales'   => round((float) ($materiales[$t->id] ?? 0)),
                'nomina'       => round((float) ($nominaTienda[$t->id] ?? 0)),
                'comisiones'   => round((float) ($comisiones[$t->id] ?? 0)),
                'gastos'       => round((float) ($gastos[$t->id] ?? 0)),
                'contribucion' => round($netas - $directos),
            ];
        }

        // Lo general: todo lo del estado de resultados que no quedó en una tienda.
        $totalCostos = $er['costo_produccion']['monto'] + $er['nomina']['total'] + $er['comisiones']['total']
                     + $er['gastos']['total'] + $er['financieros']['total'];
        $asignado = array_sum(array_column($filas, 'materiales')) + $nominaDirecta
                  + array_sum(array_column($filas, 'comisiones')) + array_sum(array_column($filas, 'gastos'));
        $general = max(0, round($totalCostos - $asignado));

        $reparto = ConfigFinanzas::get('reparto_generales');
        $conVentas = array_filter($filas, fn ($f) => $f['ventas'] > 0);
        $totalVentas = array_sum(array_column($filas, 'ventas'));
        foreach ($filas as &$f) {
            $parte = match ($reparto) {
                'ventas'         => $totalVentas > 0 ? $general * $f['ventas'] / $totalVentas : 0,
                'partes_iguales' => $f['ventas'] > 0 && count($conVentas) ? $general / count($conVentas) : 0,
                default          => 0,
            };
            $f['generales'] = round($parte);
            $f['utilidad'] = round($f['contribucion'] - $parte);
            $f['margen_contribucion'] = $f['netas'] > 0 ? round($f['contribucion'] / $f['netas'], 4) : null;
            $f['margen'] = $f['netas'] > 0 ? round($f['utilidad'] / $f['netas'], 4) : null;
        }
        unset($f);

        usort($filas, fn ($a, $b) => $b['contribucion'] <=> $a['contribucion']);

        return [
            'mes'      => $mes,
            'nombre'   => Periodo::nombre($mes),
            'tiendas'  => array_values(array_filter($filas, fn ($f) => $f['ventas'] > 0 || $f['gastos'] > 0 || $f['nomina'] > 0)),
            'general'  => $general,
            'reparto'  => $reparto,
        ];
    }

    private static function materialesPorTienda(array $rango): array
    {
        if (! ConfigFinanzas::get('usar_costo_fichas') || ! \App\Support\Esquema::tabla('fichas_tecnicas')
            || ! \App\Support\Esquema::columna('fichas_tecnicas', 'producto_id')) {
            return [];
        }

        $fichas = DB::table('fichas_tecnicas')->whereNotNull('producto_id')->where('costo_materiales', '>', 0)
            ->groupBy('producto_id')->selectRaw('producto_id, AVG(costo_materiales) AS costo');

        $filas = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->leftJoinSub($fichas, 'f', 'f.producto_id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->where(fn ($q) => $q->whereNull('oi.es_restauracion')->orWhere('oi.es_restauracion', false))
            ->selectRaw('o.tienda_id,
                SUM(oi.cantidad * oi.precio_unitario) AS ventas,
                SUM(CASE WHEN f.costo IS NOT NULL THEN oi.cantidad * oi.precio_unitario ELSE 0 END) AS con_ficha,
                SUM(CASE WHEN f.costo IS NOT NULL THEN oi.cantidad * f.costo ELSE 0 END) AS costo')
            ->groupBy('o.tienda_id')->get();

        $out = [];
        foreach ($filas as $f) {
            $prop = (float) $f->con_ficha > 0 ? (float) $f->costo / (float) $f->con_ficha : 0;
            $out[(int) $f->tienda_id] = (float) $f->costo + ((float) $f->ventas - (float) $f->con_ficha) * $prop;
        }

        return $out;
    }

    /** Lo devengado en el mes por los gastos registrados con tienda. */
    private static function gastosPorTienda(string $mes): array
    {
        [$ini, $fin] = Periodo::limites($mes);
        $out = [];
        $gastos = Gasto::where('estado', Gasto::PAGADO)->whereNotNull('tienda_id')
            ->whereDate('cubre_desde', '<=', $fin->toDateString())->whereDate('cubre_hasta', '>=', $ini->toDateString())
            ->with('categoria:id,area')->get();
        foreach ($gastos as $g) {
            // Los bancarios van a "financieros", que es general. Y el abono a
            // una factura no es gasto: la factura cuenta en su mes (abajo).
            if ($g->categoria?->area === 'financiero' || $g->cuenta_por_pagar_id) continue;
            $fr = Periodo::repartirGasto($g->cubre_desde, $g->cubre_hasta)[$mes] ?? 0;
            $out[$g->tienda_id] = ($out[$g->tienda_id] ?? 0) + (float) $g->monto * $fr;
        }

        if (\App\Support\Esquema::tabla('cuentas_por_pagar')) {
            $facturas = \App\Models\CuentaPorPagar::where('estado', '!=', 'anulada')->whereNotNull('tienda_id')
                ->whereDate('fecha_factura', '>=', $ini->toDateString())->whereDate('fecha_factura', '<=', $fin->toDateString())
                ->get(['tienda_id', 'monto']);
            foreach ($facturas as $f) {
                $out[$f->tienda_id] = ($out[$f->tienda_id] ?? 0) + (float) $f->monto;
            }
        }

        return $out;
    }
}
