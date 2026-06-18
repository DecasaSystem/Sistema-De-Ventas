<?php

namespace App\Console\Commands;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SurtirTienda extends Command
{
    protected $signature   = 'decasa:surtir-tienda {tienda : Nombre (o parte del nombre) de la tienda} {--dry-run : Solo mostrar coincidencias sin guardar} {--force : Ejecutar sin confirmación interactiva}';
    protected $description = 'Carga inventario de cualquier tienda desde la lista en $excel';

    private array $excel = [
        ['cantidad' => 1,  'nombre' => 'SILLA CHESTER'],
        ['cantidad' => 2,  'nombre' => 'SILLAS TURQUESA'],
        ['cantidad' => 2,  'nombre' => 'SILLAS BARRA DIAMANTE'],
        ['cantidad' => 2,  'nombre' => 'SILLAS CHAPILLADAS'],
        ['cantidad' => 2,  'nombre' => 'SILLA DE BARRA EQUIS'],
        ['cantidad' => 2,  'nombre' => 'SILLA DE BARRA ROBLE'],
        ['cantidad' => 1,  'nombre' => 'SILLA DE BARRA TAPIZADA'],
        ['cantidad' => 2,  'nombre' => 'SILLA DE BARRA DISEÑO NUEVO IMPORTADO'],
        ['cantidad' => 4,  'nombre' => 'SILLAS DE COMEDOR DIAMANTE'],
        ['cantidad' => 6,  'nombre' => 'SILLAS DE COMEDOR OCEANO IMPORTADAS'],
        ['cantidad' => 6,  'nombre' => 'SILLAS DE COMEDOR ARCO IMPORTADAS'],
        ['cantidad' => 4,  'nombre' => 'SILLAS DE COMEDOR FILIPINAS'],
        ['cantidad' => 4,  'nombre' => 'SILLAS DE COMEDOR TAPIZADAS INV CIRCUNVALAR LINEAL'],
        ['cantidad' => 2,  'nombre' => 'SILLAS DE COMEDOR TURQUESA'],
        ['cantidad' => 10, 'nombre' => 'SILLAS DE COMEDOR ESMERALDA'],
        ['cantidad' => 5,  'nombre' => 'SILLAS DE COMEDOR MIMBRE SURTIDAS IMPORTACION'],
        ['cantidad' => 1,  'nombre' => 'MESA DE COMEDOR HIMALAYA'],
        ['cantidad' => 1,  'nombre' => 'MESA DE COMEDOR MADRID'],
        ['cantidad' => 1,  'nombre' => 'MESA DE COMEDOR MIMBRE NUEVA IMPORTACION'],
        ['cantidad' => 1,  'nombre' => 'BASE FYGI OVALADA EN LAMINA DE 160X1MT'],
        ['cantidad' => 1,  'nombre' => 'MESA DE COMEDOR PROMOCIONAL DE 4 PUESTOS'],
        ['cantidad' => 1,  'nombre' => 'MESA DE COMEDOR DANZA'],
        ['cantidad' => 1,  'nombre' => 'MESA DE COMEDOR DIAMANTE'],
        ['cantidad' => 1,  'nombre' => 'MESA DE CENTRO JUEGO DE MESA REDONDAS X2'],
        ['cantidad' => 1,  'nombre' => 'MESA DE CENTRO CILINDRICA'],
        ['cantidad' => 2,  'nombre' => 'MESAS DE CENTRO GOTA'],
        ['cantidad' => 1,  'nombre' => 'MESA DE CENTRO SAN DIEGO'],
        ['cantidad' => 1,  'nombre' => 'MESA DE CENTRO ROBLE CON CRISTAL'],
        ['cantidad' => 1,  'nombre' => 'PORTA MATEROS'],
        ['cantidad' => 1,  'nombre' => 'MESA DE TV PRADA'],
        ['cantidad' => 1,  'nombre' => 'MESA DE TV SAN DIEGO'],
        ['cantidad' => 1,  'nombre' => 'CAJONERO ALISTONADO'],
        ['cantidad' => 1,  'nombre' => 'COMEDOR OBREGON'],
    ];

    // ── Normalización ──────────────────────────────────────────────────────────

    private function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim(mb_strtolower(
            strtr($s, [
                'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
                'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
                'ñ'=>'n','Ñ'=>'n',
                '–'=>'-','—'=>'-',
            ])
        )));
    }

    /** Detecta categoría de BD a partir del nombre del Excel. */
    private function detectCat(string $n): ?string
    {
        if (preg_match('/\bbarra\b/', $n))                                      return 'sillas_barra';
        if (preg_match('/\bcomedor\b/', $n) && preg_match('/\bsill/', $n))     return 'sillas_comedor';
        if (preg_match('/\baux(iliar)?\b/', $n))                                return 'sillas_aux';
        if (preg_match('/mesas? de centro|mesas de centro/', $n))               return 'mesas_centro';
        if (preg_match('/mesa (?:de )?(?:tv|television)|mesa tv/', $n))         return 'mesas_tv';
        if (preg_match('/mesa (?:de )?noche|mesa noche/', $n))                  return 'mesas_noche';
        if (preg_match('/mesa (?:de )?comedor|^base /', $n))                    return 'comedores';
        if (preg_match('/sofa cama/', $n))                                       return 'sofa_camas';
        if (preg_match('/modular/', $n))                                         return 'sofas_modulares';
        if (preg_match('/\bsofa\b/', $n))                                        return 'sofas';
        if (preg_match('/\bcama\b/', $n))                                        return 'camas';
        if (preg_match('/cajonero|bife/', $n))                                   return 'cajoneros';
        return null;
    }

    /** Elimina el prefijo de tipo de mueble y devuelve solo el modelo. */
    private function stripPrefix(string $n): string
    {
        static $prefixes = [
            'sillas de barra ', 'silla de barra ', 'sillas barra ', 'silla barra ',
            'sillas de comedor ', 'silla de comedor ', 'sillas comedor ', 'silla comedor ',
            'sillas auxiliar ', 'silla auxiliar ', 'sillas aux ', 'silla aux ',
            'mesas de centro ', 'mesa de centro ',
            'mesas de tv ', 'mesa de tv ', 'mesa tv ',
            'mesas de noche ', 'mesa de noche ', 'mesa noche ',
            'sofa cama ', 'mesa de comedor ', 'mesa comedor ',
            'base de comedor ', 'base comedor ', 'base ',
            'modular ', 'cajonero ', 'bife ', 'sofa ',
            'sillas ', 'silla ', 'mesas ', 'mesa ',
        ];
        foreach ($prefixes as $p) {
            if (str_starts_with($n, $p)) {
                return trim(substr($n, strlen($p)));
            }
        }
        return $n;
    }

    // ── Handle ─────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $buscar = '%' . $this->argument('tienda') . '%';
        $tienda = DB::table('tiendas')->where('nombre', 'like', $buscar)->first();
        if (!$tienda) {
            $this->error('Tienda no encontrada.');
            DB::table('tiendas')->get()->each(fn($t) => $this->line("  [{$t->id}] {$t->nombre}"));
            return self::FAILURE;
        }
        $this->info("Tienda: [{$tienda->id}] {$tienda->nombre}");
        $this->newLine();

        $productos = DB::table('productos')->where('activo', true)->get(['id', 'nombre', 'categoria']);

        // Mapa por nombre completo normalizado
        $dbFull = [];
        // Mapa [categoría][modelo] → producto
        $dbCatModel = [];

        foreach ($productos as $p) {
            $key   = $this->norm($p->nombre);
            $cat   = $p->categoria ?? '';
            $model = $this->stripPrefix($key);
            $dbFull[$key] = $p;
            $dbCatModel[$cat][$model] = $p;
        }

        // Consolidar filas duplicadas
        $consolidado = [];
        foreach ($this->excel as $row) {
            if ($row['cantidad'] <= 0) continue;
            $key = $this->norm($row['nombre']);
            if (!isset($consolidado[$key])) {
                $consolidado[$key] = ['nombre_original' => $row['nombre'], 'cantidad' => 0];
            }
            $consolidado[$key]['cantidad'] += $row['cantidad'];
        }

        $matched   = [];
        $unmatched = [];

        foreach ($consolidado as $keyExcel => $row) {
            $result = $this->findMatch($keyExcel, $dbFull, $dbCatModel);
            if ($result) {
                $matched[] = array_merge($result, [
                    'cantidad' => $row['cantidad'],
                    'excel'    => $row['nombre_original'],
                ]);
            } else {
                $unmatched[] = $row['nombre_original'];
            }
        }

        $this->info('═══ COINCIDENCIAS (' . count($matched) . ') ═══');
        foreach ($matched as $m) {
            $this->line("  [{$m['tag']}] {$m['excel']} → [{$m['producto']->id}] {$m['producto']->nombre}  (x{$m['cantidad']})");
        }

        $this->newLine();
        $this->warn('═══ SIN COINCIDENCIA (' . count($unmatched) . ') ═══');
        foreach ($unmatched as $u) {
            $this->line("  ✗ {$u}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Modo dry-run: no se guardaron cambios.');
            return self::SUCCESS;
        }

        if (empty($matched)) {
            $this->warn('No hay coincidencias para guardar.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("¿Actualizar [{$tienda->nombre}] con " . count($matched) . " productos?")) {
            $this->info('Cancelado.');
            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $act = 0; $cre = 0;

        foreach ($matched as $m) {
            $inv = Inventario::firstOrCreate(
                ['producto_id' => $m['producto']->id, 'tienda_id' => $tienda->id],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
            );
            $anterior = $inv->cantidad_disponible;
            $inv->cantidad_disponible = $m['cantidad'];
            $inv->save();

            InventarioMovimiento::create([
                'producto_id' => $m['producto']->id,
                'tienda_id'   => $tienda->id,
                'tipo'        => 'entrada',
                'cantidad'    => $m['cantidad'],
                'motivo'      => "Carga inventario {$tienda->nombre} — anterior: {$anterior}",
                'usuario_id'  => 1,
            ]);

            $inv->wasRecentlyCreated ? $cre++ : $act++;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->newLine();
        $this->info("✓ Inventario actualizado: {$act} actualizados, {$cre} creados.");
        return self::SUCCESS;
    }

    // ── Matching ───────────────────────────────────────────────────────────────

    private function findMatch(string $keyExcel, array $dbFull, array $dbCatModel): ?array
    {
        // Paso 1: nombre completo exacto
        if (isset($dbFull[$keyExcel])) {
            return ['producto' => $dbFull[$keyExcel], 'tag' => '✓'];
        }

        // Paso 2: fuzzy nombre completo ≥ 90%
        $r = $this->fuzzySearch($keyExcel, $dbFull, 90);
        if ($r) return $r;

        $cat   = $this->detectCat($keyExcel);
        $model = $this->stripPrefix($keyExcel);

        // Paso 3: modelo exacto dentro de la categoría detectada
        if ($cat && isset($dbCatModel[$cat][$model])) {
            return ['producto' => $dbCatModel[$cat][$model], 'tag' => "cat:{$cat}"];
        }

        // Paso 4: fuzzy de modelo ≥ 80% dentro de la categoría
        if ($cat && isset($dbCatModel[$cat])) {
            $r = $this->fuzzySearch($model, $dbCatModel[$cat], 80);
            if ($r) {
                $r['tag'] = "cat:{$cat}~{$r['score']}%";
                return $r;
            }
        }

        // Paso 5: palabra distintiva única en toda la BD
        $r = $this->uniqueWordMatch($keyExcel, $cat, $dbFull);
        if ($r) return $r;

        return null;
    }

    /**
     * Busca una palabra característica (no genérica) del nombre Excel en todos
     * los productos de la BD. Si solo un producto contiene esa palabra → match.
     * Respeta la categoría detectada para evitar falsos positivos entre tipos distintos
     * (no mezcla "barra" con "comedor" si hay conflicto explícito).
     */
    private function uniqueWordMatch(string $keyExcel, ?string $cat, array $dbFull): ?array
    {
        static $stopWords = [
            'mesa','mesas','silla','sillas','base','bases','sofa','modular',
            'de','la','el','en','y','a','con','por','para','del','un','una',
            'al','los','las','x2','x3','x4','con','sin','juego','nueva',
            'nuevo','nuevas','nuevos','importadas','importados','importacion',
            'importada','importado','surtidas','surtidos','inv','circunvalar',
            'lineal','puestos','barra','comedor','centro','tv','noche','aux',
        ];

        $words = array_filter(
            explode(' ', $keyExcel),
            fn($w) => strlen($w) >= 4 && !in_array($w, $stopWords)
        );

        // Categorías de "sillas" relacionadas (pueden cruzarse si la palabra es única)
        $sillaCats = ['sillas_barra', 'sillas_aux', 'sillas_comedor'];

        foreach ($words as $word) {
            // Probar la palabra y sin la 's' final (plural español)
            $variants = [$word];
            if (str_ends_with($word, 's') && strlen($word) > 4) {
                $variants[] = substr($word, 0, -1);
            }

            foreach ($variants as $w) {
                $hits = [];
                foreach ($dbFull as $key => $prod) {
                    if (str_contains($key, $w)) {
                        $hits[] = $prod;
                    }
                }
                if (count($hits) !== 1) continue;

                $prodCat = $hits[0]->categoria;

                // Sin categoría detectada → match libre
                if ($cat === null) {
                    return ['producto' => $hits[0], 'tag' => "word:{$w}"];
                }
                // Misma categoría → match seguro
                if ($prodCat === $cat) {
                    return ['producto' => $hits[0], 'tag' => "word:{$w}"];
                }
                // Cruce dentro del grupo "sillas" solo si el nombre no dice "barra"
                // y el match no es hacia sillas_barra cuando el Excel dice "comedor"
                if (in_array($cat, $sillaCats) && in_array($prodCat, $sillaCats)) {
                    $excelDiceBarra   = str_contains($keyExcel, 'barra');
                    $matchEsBarra     = $prodCat === 'sillas_barra';
                    // No cruzar barra→comedor ni comedor→barra
                    if ($excelDiceBarra === $matchEsBarra) {
                        return ['producto' => $hits[0], 'tag' => "word:{$w}"];
                    }
                }
                // Cruce comedores ↔ mesas_centro, etc. → solo si categorías son iguales
            }
        }

        return null;
    }

    private function fuzzySearch(string $needle, array $haystack, float $minPct): ?array
    {
        $bestProd  = null;
        $bestScore = 0;
        foreach ($haystack as $key => $prod) {
            similar_text($needle, $key, $pct);
            if ($pct > $bestScore && $pct >= $minPct) {
                $bestScore = $pct;
                $bestProd  = $prod;
            }
        }
        if ($bestProd) {
            return ['producto' => $bestProd, 'tag' => '~' . round($bestScore, 1) . '%', 'score' => round($bestScore, 1)];
        }
        return null;
    }
}
