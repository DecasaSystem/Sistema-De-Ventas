<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel de precisión del cotizador (AGENT.md, Fase 5).
 *
 * Resume qué tan acertados fueron los estimados de la IA frente a las correcciones reales de los
 * ebanistas (`estimados_ia` con `precio_humano` no nulo). Es la métrica que dice si el cotizador
 * está mejorando con el uso.
 */
class PrecisionCotizadorController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();
        if (! in_array($usuario->rol, ['supervisor', 'ebanista'])) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $corregidos = DB::table('estimados_ia')
            ->whereNotNull('precio_humano')
            ->where('precio_humano', '>', 0)
            ->get(['categoria', 'precio_ia', 'precio_humano', 'error_pct', 'input_texto', 'corregido_at']);

        $total = $corregidos->count();

        // Estado inicial: aún no hay correcciones acumuladas
        if ($total === 0) {
            return response()->json([
                'hay_datos'      => false,
                'total_casos'    => 0,
                'mensaje'        => 'Todavía no hay correcciones de ebanistas registradas. El panel se '
                                  . 'irá llenando a medida que se respondan consultas de costo sobre '
                                  . 'muebles personalizados.',
                'global'         => null,
                'por_categoria'  => [],
                'recientes'      => [],
            ]);
        }

        // Métricas de un conjunto de estimados
        $metricas = function ($items) {
            $n         = $items->count();
            $errAbs    = $items->avg(fn($e) => abs((float) $e->error_pct));
            $sesgo     = $items->avg(fn($e) => (float) $e->error_pct);
            $dentro10  = $items->filter(fn($e) => abs((float) $e->error_pct) <= 10)->count();
            return [
                'n'                 => $n,
                'error_medio_abs'   => round($errAbs, 1),   // qué tan lejos, sin importar dirección
                'sesgo_medio'       => round($sesgo, 1),    // negativo = la IA subestima (riesgo de pérdida)
                'dentro_10pct'      => $dentro10,
                'dentro_10pct_ratio'=> round($dentro10 / max($n, 1) * 100, 0),
            ];
        };

        $porCategoria = $corregidos
            ->groupBy(fn($e) => $e->categoria ?: 'sin categoría')
            ->map(fn($items, $cat) => array_merge(['categoria' => $cat], $metricas($items)))
            ->sortByDesc('n')
            ->values();

        $recientes = $corregidos
            ->sortByDesc('corregido_at')
            ->take(15)
            ->map(fn($e) => [
                'mueble'        => mb_strimwidth($e->input_texto, 0, 60, '…'),
                'categoria'     => $e->categoria,
                'precio_ia'     => (int) $e->precio_ia,
                'precio_real'   => (int) $e->precio_humano,
                'error_pct'     => round((float) $e->error_pct, 1),
                'corregido_at'  => $e->corregido_at,
            ])
            ->values();

        return response()->json([
            'hay_datos'     => true,
            'total_casos'   => $total,
            'global'        => $metricas($corregidos),
            'por_categoria' => $porCategoria,
            'recientes'     => $recientes,
        ]);
    }
}
