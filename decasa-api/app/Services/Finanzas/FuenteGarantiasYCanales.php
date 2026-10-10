<?php

namespace App\Services\Finanzas;

use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos lecturas para decidir:
 *
 * - GARANTÍAS del mes: cuántas se reportaron, cómo se resolvieron, cuánto se
 *   devolvió en plata y qué se daña más (madera, tela/espuma). El costo del
 *   arreglo en el taller queda para cuando exista el costo real de producción;
 *   por ahora se cuenta lo que sí se sabe en plata: los reembolsos.
 * - CANALES: qué se vende por cada canal (tienda, WhatsApp, Instagram…) contra
 *   lo que se gastó en publicidad de ese canal (los gastos marcados con canal y
 *   la categoría de publicidad), y cuánto vuelve por cada peso de publicidad.
 */
class FuenteGarantiasYCanales
{
    public static function garantias(string $mes): ?array
    {
        if (! \App\Support\Esquema::tabla('garantias')) return null;
        [$ini, $fin] = Periodo::limites($mes);

        $del = DB::table('garantias')
            ->whereDate('fecha_reporte', '>=', $ini->toDateString())->whereDate('fecha_reporte', '<=', $fin->toDateString());

        $porTipo = (clone $del)->selectRaw('tipo_dano, COUNT(*) AS n')->groupBy('tipo_dano')->pluck('n', 'tipo_dano')->all();
        $porDecision = (clone $del)->whereNotNull('decision')->selectRaw('decision, COUNT(*) AS n')->groupBy('decision')->pluck('n', 'decision')->all();

        $reembolsos = \App\Support\Esquema::columna('pagos', 'tipo')
            ? (float) DB::table('pagos')->where('tipo', 'reembolso')->whereBetween('created_at', Periodo::rangoUtc($mes, $mes))->sum('monto')
            : 0.0;

        $abiertas = DB::table('garantias')->whereNotIn('estado', ['resuelta', 'no_procede'])->count();
        $ventasMes = (int) DB::table('ordenes')->whereBetween('created_at', Periodo::rangoUtc($mes, $mes))
            ->whereNotIn('estado', Orden::ESTADOS_FUERA_DE_REPORTES)->count();
        $reportadas = (int) array_sum($porTipo);

        return [
            'reportadas'   => $reportadas,
            'abiertas'     => $abiertas,
            'por_tipo'     => $porTipo,
            'por_decision' => $porDecision,
            'reembolsos'   => round(abs($reembolsos)),
            // Por cada 100 órdenes del mes (no es la misma cohorte, pero da la idea).
            'por_cien_ordenes' => $ventasMes > 0 ? round($reportadas / $ventasMes * 100, 1) : null,
        ];
    }

    public static function canales(string $mes): array
    {
        $rango = Periodo::rangoUtc($mes, $mes);
        $divisor = ConfigFinanzas::divisorIva();

        // Por la expresión, no por el alias: en MySQL `GROUP BY canal` es la
        // columna `o.canal`, y las de canal NULL y las de 'otro' salían en dos
        // filas con la misma llave (keyBy se quedaba con una sola).
        $ventas = DB::table('ordenes as o')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->selectRaw("COALESCE(o.canal, 'otro') AS canal, COUNT(*) AS ordenes, SUM(o.valor_total) AS vendido")
            ->groupByRaw("COALESCE(o.canal, 'otro')")->get()->keyBy('canal');

        // Publicidad: los gastos marcados con canal, más los de la categoría de
        // publicidad sin canal (van a "sin canal").
        [$ini, $fin] = Periodo::limites($mes);
        $publicidad = [];
        if (\App\Support\Esquema::columna('gastos', 'canal')) {
            $gastos = \App\Models\Gasto::with('categoria:id,grupo,nombre')->where('estado', 'pagado')
                ->whereNull('cuenta_por_pagar_id')
                ->whereDate('cubre_desde', '<=', $fin->toDateString())->whereDate('cubre_hasta', '>=', $ini->toDateString())
                ->where(fn ($q) => $q->whereNotNull('canal')->orWhereHas('categoria', fn ($c) => $c->where('grupo', 'ventas')->where('nombre', 'like', '%ublicidad%')))
                ->get();
            foreach ($gastos as $g) {
                $fr = Periodo::repartirGasto($g->cubre_desde, $g->cubre_hasta)[$mes] ?? 0;
                $clave = $g->canal ?: 'sin_canal';
                $publicidad[$clave] = ($publicidad[$clave] ?? 0) + (float) $g->monto * $fr;
            }
        }

        $NOMBRES = ['fisica' => 'Tienda física', 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook',
                    'pagina' => 'Página web', 'red_social' => 'Otras redes', 'otro' => 'Otro', 'sin_canal' => 'Publicidad sin canal'];

        $claves = array_unique(array_merge($ventas->keys()->all(), array_keys($publicidad)));
        $filas = [];
        foreach ($claves as $c) {
            $v = (float) ($ventas[$c]->vendido ?? 0);
            $p = round($publicidad[$c] ?? 0);
            $filas[] = [
                'canal'      => $c,
                'nombre'     => $NOMBRES[$c] ?? ucfirst($c),
                'ordenes'    => (int) ($ventas[$c]->ordenes ?? 0),
                'vendido'    => round($v),
                'netas'      => round($v / $divisor),
                'publicidad' => $p,
                // Por cada peso de publicidad, cuántos pesos de venta.
                'retorno'    => $p > 0 ? round($v / $p, 1) : null,
            ];
        }
        usort($filas, fn ($a, $b) => $b['vendido'] <=> $a['vendido']);

        return [
            'mes'        => $mes,
            'canales'    => $filas,
            'vendido'    => round(array_sum(array_column($filas, 'vendido'))),
            'publicidad' => round(array_sum(array_column($filas, 'publicidad'))),
        ];
    }
}
