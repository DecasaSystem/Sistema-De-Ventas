<?php

namespace App\Services\Costos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Recupera las fichas técnicas más parecidas a lo que pide el cliente.
 *
 * Reemplaza a AgentService::fichasReferenciaPorContexto(), que hacía LIKE palabra por palabra
 * y de cada match tomaba la ficha MÁS BARATA (`orderBy('costo_total')`) — un sesgo sistemático
 * a la baja que era la causa directa de estimados como CAMA SUIZA ENCHAPADA en -56%.
 *
 * Con 306 fichas, la similitud coseno en memoria es instantánea: no hace falta vector DB.
 */
class FichaRetriever
{
    private const MODELO_EMBEDDING = 'text-embedding-3-small';

    /**
     * Palabra clave del mueble → categoría de ficha. Sirve para descomponer híbridos:
     * "cama con escritorio" debe traer una ficha de CAMAS **y** una de ESCRITORIOS,
     * porque ninguna ficha del catálogo es las dos cosas a la vez.
     */
    private const TIPOS = [
        'camarote'   => 'CAMAROTES',
        'cuna'       => 'CAMAS CUNAS',
        'cama'       => 'CAMAS',
        'escritorio' => 'ESCRITORIOS',
        'sofa'       => 'SOFAS',
        'sofá'       => 'SOFAS',
        'modular'    => 'MODULARES',
        'nochero'    => 'MESAS DE NOCHE',
        'noche'      => 'MESAS DE NOCHE',
        'centro'     => 'MESAS DE CENTRO',
        'comedor'    => 'BASES COMEDOR',
        'cajonera'   => 'CAJONERAS',
        'cajonero'   => 'CAJONERAS',
        'cajones'    => 'CAJONERAS',
        'zapatera'   => 'ZAPATEROS',
        'zapatero'   => 'ZAPATEROS',
        'vitrina'    => 'VITRINAS',
        'butaco'     => 'BUTACOS',
        'consola'    => 'CONSOLAS Y MARCO ESPEJO',
        'espejo'     => 'CONSOLAS Y MARCO ESPEJO',
        'bifet'      => 'BIFET',
        'bar'        => 'MUEBLE BAR',
        'silla'      => 'SILLAS DE COMEDOR',
    ];

    /**
     * Fichas de referencia para un mueble descrito en texto libre.
     *
     * @param string $texto     Descripción + nombre de lo que pide el cliente.
     * @param array  $medidas   ['largo' => cm, 'ancho' => cm, 'alto' => cm]
     * @return Collection<int, object>  Fichas con `_score` y `_componente`.
     */
    public function buscarSimilares(string $texto, ?string $categoria = null, array $medidas = [], int $max = 5): Collection
    {
        $fichas = $this->fichasIndexadas();

        if ($fichas->isEmpty()) {
            return $this->fallbackLike($texto, $categoria, $max);
        }

        $consulta = $this->embed(trim($texto . ' ' . ($categoria ?? '')));
        if (! $consulta) {
            return $this->fallbackLike($texto, $categoria, $max);
        }

        // Puntuar TODAS las fichas por similitud (sin ordenar por costo — sin sesgo)
        $puntuadas = $fichas->map(function ($f) use ($consulta, $medidas) {
            $f = clone $f;
            $f->_score = $this->coseno($consulta, $f->_vec) + $this->bonoMedidas($f->nombre, $medidas);
            return $f;
        });

        // ── Descomposición de híbridos ───────────────────────────────────────
        // Si la descripción menciona varios tipos de mueble (cama + escritorio),
        // se trae la mejor ficha de CADA tipo. Ninguna ficha sola cubre el híbrido.
        $tipos = $this->detectarTipos($texto);

        if (count($tipos) > 1) {
            $seleccion = collect();

            foreach ($tipos as $cat) {
                $mejor = $puntuadas
                    ->filter(fn($f) => mb_strtoupper($f->categoria) === $cat)
                    ->sortByDesc('_score')
                    ->first();

                if ($mejor) {
                    $mejor = clone $mejor;
                    $mejor->_componente = $cat;
                    $seleccion->push($mejor);
                }
            }

            // Completar con las mejores globales si quedó corto
            if ($seleccion->count() < $max) {
                $ids   = $seleccion->pluck('id')->all();
                $resto = $puntuadas->reject(fn($f) => in_array($f->id, $ids, true))
                    ->sortByDesc('_score')
                    ->take($max - $seleccion->count());
                $seleccion = $seleccion->concat($resto);
            }

            return $this->limpiar($seleccion->take($max));
        }

        // ── Mueble simple: top-k por similitud ───────────────────────────────
        return $this->limpiar($puntuadas->sortByDesc('_score')->take($max));
    }

    /** Detecta qué tipos de mueble menciona el texto (para híbridos). */
    private function detectarTipos(string $texto): array
    {
        $t     = mb_strtolower($texto);
        $tipos = [];

        foreach (self::TIPOS as $palabra => $categoria) {
            if (str_contains($t, $palabra)) $tipos[$categoria] = true;
        }

        return array_keys($tipos);
    }

    /**
     * Bono de similitud cuando la medida del nombre de la ficha coincide con la pedida.
     * Los nombres traen la medida principal ("CAMA MACARENA DE 1.40 PINO"), así que una cama
     * de 1.40 debe puntuar más alto que una de 2.00 aunque el texto sea casi idéntico.
     */
    private function bonoMedidas(string $nombre, array $medidas): float
    {
        $pedida = null;
        foreach (['largo', 'ancho'] as $k) {
            if (! empty($medidas[$k])) {
                $pedida = (float) $medidas[$k] / 100; // cm → m
                break;
            }
        }
        if (! $pedida) return 0.0;

        // "1,40" / "1.40" / "140" en el nombre
        if (! preg_match('/(\d+[.,]\d+)\s*(?:MTS?|M\b)?/i', $nombre, $m)) return 0.0;

        $enFicha = (float) str_replace(',', '.', $m[1]);
        if ($enFicha <= 0) return 0.0;

        $desvio = abs($enFicha - $pedida) / max($pedida, 0.01);

        return $desvio <= 0.15 ? 0.05 : 0.0; // empujón suave, no domina la semántica
    }

    /**
     * Fichas con su embedding ya decodificado.
     *
     * Memo en memoria, no cache persistente: serializar los vectores al cache de disco los
     * devuelve como __PHP_Incomplete_Class al releerlos. Son 306 filas — cargarlas por
     * request cuesta milisegundos.
     */
    private ?Collection $memo = null;

    private function fichasIndexadas(): Collection
    {
        if ($this->memo !== null) return $this->memo;

        return $this->memo = DB::table('fichas_tecnicas')
            ->whereNotNull('embedding')
            ->get(['id', 'nombre', 'categoria', 'costo_materiales', 'costo_mano_obra', 'costo_total', 'embedding'])
            ->map(function ($f) {
                $f->_vec = json_decode($f->embedding, true) ?: [];
                unset($f->embedding);
                return $f;
            })
            ->filter(fn($f) => ! empty($f->_vec))
            ->values();
    }

    /** Quita los vectores antes de mandar las fichas al prompt (ahorra tokens). */
    private function limpiar(Collection $fichas): Collection
    {
        return $fichas->map(function ($f) {
            unset($f->_vec);
            $f->_score = round($f->_score, 4);
            return $f;
        })->values();
    }

    /**
     * Si todavía no se ha corrido `fichas:reindex`, se cae al método viejo por LIKE —
     * pero SIN el orderBy('costo_total') que sesgaba a la baja.
     */
    private function fallbackLike(string $texto, ?string $categoria, int $max): Collection
    {
        $palabras = collect(preg_split('/\s+/', mb_strtolower("$texto $categoria")))
            ->map(fn($p) => trim($p, '.,;:()'))
            ->filter(fn($p) => mb_strlen($p) >= 4)
            ->unique();

        $query = DB::table('fichas_tecnicas');

        if ($palabras->isNotEmpty()) {
            $query->where(function ($q) use ($palabras) {
                foreach ($palabras as $p) {
                    $q->orWhereRaw('LOWER(nombre) LIKE ?',    ['%' . $p . '%'])
                      ->orWhereRaw('LOWER(categoria) LIKE ?', ['%' . $p . '%']);
                }
            });
        }

        return $query->limit($max)
            ->get(['id', 'nombre', 'categoria', 'costo_materiales', 'costo_mano_obra', 'costo_total']);
    }

    // ── Embeddings ───────────────────────────────────────────────────────────

    /** Texto que representa a una ficha en el índice. */
    public function textoDeFicha(object $ficha, array $secciones = []): string
    {
        $partes = [$ficha->nombre, $ficha->categoria];

        foreach ($secciones as $s) {
            if ($s && mb_strtoupper($s) !== mb_strtoupper($ficha->nombre)) $partes[] = $s;
        }

        return implode(' | ', array_filter($partes));
    }

    /** @return array<float>|null */
    public function embed(string $texto): ?array
    {
        if (trim($texto) === '') return null;

        try {
            $r = OpenAI::embeddings()->create([
                'model' => self::MODELO_EMBEDDING,
                'input' => $texto,
            ]);

            return $r->embeddings[0]->embedding ?? null;
        } catch (\Throwable $e) {
            \Log::error('FichaRetriever::embed', ['err' => $e->getMessage()]);
            return null;
        }
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
