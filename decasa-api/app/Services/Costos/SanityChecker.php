<?php

namespace App\Services\Costos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Valida que un estimado sea plausible antes de enseñárselo al vendedor.
 *
 * Con 306 fichas reales se puede saber cuánto cuesta *de verdad* un mueble de cada categoría.
 * Si el estimado se sale de esa realidad, es mejor marcarlo como "requiere revisión del ebanista"
 * que entregar un número falso con cara de precio en firme.
 *
 * Caso real que motivó esto: CAMA ESPECIAL 140 TERRA estimada en $957.498 cuando su costo real es
 * $1.419.748 (-32,6%) — el modelo subestimó el material y nadie lo detectaba.
 */
class SanityChecker
{
    /** Desviación tolerada respecto a las fichas más parecidas antes de pedir revisión. */
    private const TOLERANCIA_REFERENCIA = 0.40;

    /**
     * @param int        $precioFabricacion Estimado calculado.
     * @param array      $bom               Receta (para saber si es híbrido).
     * @param Collection $fichasRef         Fichas similares que se usaron de referencia.
     */
    public function revisar(int $precioFabricacion, array $bom, Collection $fichasRef, ?string $categoria = null): array
    {
        $motivos = [];

        if ($precioFabricacion <= 0) {
            return [
                'requiere_revision' => true,
                'motivos'           => ['No se pudo construir un desglose con materiales del catálogo.'],
            ];
        }

        $componentes = count($bom['componentes'] ?? []);
        $esHibrido   = $componentes > 1;

        // ── 1. Contra las fichas más parecidas ───────────────────────────────
        // El retriever devuelve las fichas ya ordenadas por similitud, así que la PRIMERA es
        // la más parecida. Para un mueble simple el esperado es esa ficha top-1 (no la mediana
        // de las 5 — mezclaría muebles distintos e inflaría la banda, marcando estimados
        // correctos). Para un híbrido el esperado es la SUMA de sus componentes (una
        // cama-escritorio cuesta aproximadamente cama + escritorio).
        $costosRef = $fichasRef
            ->map(fn($f) => (float) ($f->costo_total ?? 0))
            ->filter(fn($c) => $c > 0)
            ->values();

        if ($costosRef->isNotEmpty()) {
            $esperado = $esHibrido
                ? $costosRef->take($componentes)->sum()
                : (float) $costosRef->first();

            if ($esperado > 0) {
                $desvio = ($precioFabricacion - $esperado) / $esperado;

                if (abs($desvio) > self::TOLERANCIA_REFERENCIA) {
                    $motivos[] = sprintf(
                        'El estimado ($%s) se desvía %+.0f%% de lo que cuestan los muebles más parecidos del catálogo ($%s).',
                        number_format($precioFabricacion, 0, ',', '.'),
                        $desvio * 100,
                        number_format($esperado, 0, ',', '.'),
                    );
                }
            }
        }

        // ── 2. Chequeo de absurdo contra la categoría ────────────────────────
        // OJO: aquí NO se usa un rango p10–p90. Por definición, el 20% de las fichas reales
        // cae fuera de su propio p10–p90, así que ese check marcaría como sospechosos a
        // estimados perfectos. Caso real: MODULO 1 PUESTO CON BRAZO cuesta $463.272 y el p10
        // de SOFAS es $584.655 — un estimado exacto quedaba marcado.
        //
        // Solo se marca lo verdaderamente absurdo: por debajo de la mitad del mueble más
        // barato de la categoría, o por encima del doble del más caro.
        if (! $esHibrido && $categoria) {
            $limites = $this->limitesCategoria($categoria);

            if ($limites && $precioFabricacion < $limites['min'] * 0.5) {
                $motivos[] = sprintf(
                    'El estimado ($%s) es menos de la mitad del mueble más barato de %s ($%s).',
                    number_format($precioFabricacion, 0, ',', '.'),
                    $categoria,
                    number_format($limites['min'], 0, ',', '.'),
                );
            }

            if ($limites && $precioFabricacion > $limites['max'] * 2) {
                $motivos[] = sprintf(
                    'El estimado ($%s) supera el doble del mueble más caro de %s ($%s).',
                    number_format($precioFabricacion, 0, ',', '.'),
                    $categoria,
                    number_format($limites['max'], 0, ',', '.'),
                );
            }
        }

        return [
            'requiere_revision' => ! empty($motivos),
            'motivos'           => $motivos,
        ];
    }

    /** Costo mínimo y máximo reales de una categoría. */
    private function limitesCategoria(string $categoria): ?array
    {
        $r = DB::table('fichas_tecnicas')
            ->whereRaw('LOWER(categoria) = ?', [mb_strtolower(trim($categoria))])
            ->where('costo_total', '>', 0)
            ->selectRaw('MIN(costo_total) AS min, MAX(costo_total) AS max, COUNT(*) AS n')
            ->first();

        if (! $r || $r->n < 4) return null; // muestra insuficiente

        return ['min' => (float) $r->min, 'max' => (float) $r->max];
    }
}
