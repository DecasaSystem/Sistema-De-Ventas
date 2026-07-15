<?php

namespace App\Services\Costos;

use App\Models\EstimadoIa;
use Illuminate\Support\Collection;

/**
 * Bucle de aprendizaje del cotizador (AGENT.md, Fase 5).
 *
 * - registrar(): guarda cada estimado de la IA para poder compararlo luego contra la corrección
 *   del ebanista.
 * - ejemplos(): recupera los casos ya corregidos más parecidos al mueble que se está cotizando,
 *   para inyectarlos como ejemplos few-shot en el prompt. Así el cotizador aprende de las
 *   correcciones reales del taller en vez de repetir el mismo error.
 */
class FewShotProvider
{
    public function __construct(private FichaRetriever $retriever) {}

    /**
     * Guarda el estimado recién calculado. Devuelve el id (se manda al front por si luego
     * quiere vincular la corrección de forma exacta).
     */
    public function registrar(
        string $inputTexto,
        ?string $categoria,
        array $bom,
        int $precioIa,
        bool $requirioRevision,
        array $medidas = [],
    ): ?int {
        try {
            $embedding = $this->retriever->embed(trim($inputTexto . ' ' . ($categoria ?? '')));

            $estimado = EstimadoIa::create([
                'input_texto'       => mb_substr($inputTexto, 0, 500),
                'categoria'         => $categoria,
                'input_hash'        => EstimadoIa::hashInput($inputTexto, $categoria),
                'medidas'           => array_filter($medidas),
                'bom_json'          => $bom,
                'precio_ia'         => $precioIa,
                'requirio_revision' => $requirioRevision,
                'embedding'         => $embedding,
            ]);

            return $estimado->id;
        } catch (\Throwable $e) {
            // El aprendizaje es best-effort: si falla, la cotización igual se entrega.
            \Log::warning('FewShotProvider::registrar', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Registra la corrección de un ebanista sobre el estimado de la IA.
     *
     * Vincula por hash grueso (categoría + nombre del mueble) con el estimado más reciente aún
     * sin corregir. No es un vínculo exacto — el estimado se generó antes de existir el orden_item
     * — pero para few-shot basta: se generó minutos antes de la consulta y comparte nombre y
     * categoría. IMPORTANTE: se compara COSTO contra COSTO (precio_ia es costo de fabricación, así
     * que el precio humano debe ser el costo del ebanista, no el precio de venta con margen).
     */
    public function registrarCorreccion(
        ?string $nombre,
        ?string $categoria,
        int $costoHumano,
        ?int $ordenItemId = null,
        ?int $usuarioId = null,
    ): bool {
        if ($costoHumano <= 0) return false;

        $hash = EstimadoIa::hashInput($nombre, $categoria);

        $estimado = EstimadoIa::where('input_hash', $hash)
            ->whereNull('precio_humano')
            ->orderByDesc('created_at')
            ->first();

        if (! $estimado) return false;

        $estimado->update([
            'precio_humano'    => $costoHumano,
            'error_pct'        => round((($estimado->precio_ia - $costoHumano) / $costoHumano) * 100, 2),
            'orden_item_id'    => $ordenItemId,
            'corregido_por_id' => $usuarioId,
            'corregido_at'     => now(),
        ]);

        return true;
    }

    /**
     * Ejemplos corregidos más parecidos al mueble que se cotiza.
     *
     * @return array<int, array{input:string, categoria:?string, precio_correcto:int, error_ia_pct:float}>
     */
    public function ejemplos(string $texto, ?string $categoria, int $max = 3): array
    {
        // Solo casos con corrección humana y embedding disponible
        $corregidos = EstimadoIa::whereNotNull('precio_humano')
            ->whereNotNull('embedding')
            ->where('precio_humano', '>', 0)
            ->orderByDesc('corregido_at')
            ->limit(300) // acota memoria; los más recientes son los más relevantes
            ->get(['input_texto', 'categoria', 'precio_ia', 'precio_humano', 'error_pct', 'embedding']);

        if ($corregidos->isEmpty()) return [];

        $consulta = $this->retriever->embed(trim($texto . ' ' . ($categoria ?? '')));
        if (! $consulta) {
            // Sin embedding de la consulta, al menos devolver los de la misma categoría
            return $this->formatear(
                $corregidos->filter(fn($e) => $categoria && mb_strtolower($e->categoria ?? '') === mb_strtolower($categoria))
                    ->take($max)
            );
        }

        // La categoría es el ancla fuerte: dos muebles de la misma categoría son comparables
        // aunque el texto difiera; entre categorías distintas se exige un parecido mucho mayor.
        // text-embedding-3-small da cosenos altos para todo el dominio "mueble", así que un
        // umbral bajo mezclaría una cama en la cotización de una silla.
        $catConsulta = mb_strtolower(trim((string) $categoria));

        $puntuados = $corregidos
            ->map(function ($e) use ($consulta) {
                $vec = is_array($e->embedding) ? $e->embedding : (json_decode($e->embedding, true) ?: []);
                $e->_score = empty($vec) ? -1 : $this->coseno($consulta, $vec);
                return $e;
            })
            ->filter(function ($e) use ($catConsulta) {
                if ($e->_score <= 0) return false;
                $mismaCat = $catConsulta && mb_strtolower(trim((string) $e->categoria)) === $catConsulta;
                return $mismaCat ? $e->_score > 0.35 : $e->_score > 0.62;
            })
            ->sortByDesc('_score')
            ->take($max);

        return $this->formatear($puntuados);
    }

    private function formatear(Collection $estimados): array
    {
        return $estimados->map(fn($e) => [
            'input'           => $e->input_texto,
            'categoria'       => $e->categoria,
            'precio_correcto' => (int) $e->precio_humano,
            'error_ia_pct'    => $e->error_pct !== null ? (float) $e->error_pct : null,
        ])->values()->all();
    }

    private function coseno(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;

        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }

        $den = sqrt($na) * sqrt($nb);

        return $den > 0 ? $dot / $den : 0.0;
    }
}
