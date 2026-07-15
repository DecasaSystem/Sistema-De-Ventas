<?php

namespace App\Console\Commands;

use App\Services\Costos\FichaRetriever;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera el embedding de cada ficha técnica para la búsqueda por similitud (AGENT.md, Fase 3).
 *
 *   php artisan fichas:reindex          → solo las que no tienen embedding
 *   php artisan fichas:reindex --todas  → regenera todas
 */
class ReindexFichas extends Command
{
    protected $signature   = 'fichas:reindex {--todas : Regenerar el embedding de todas las fichas}';
    protected $description = 'Indexa las fichas técnicas para la búsqueda por similitud del cotizador';

    public function handle(FichaRetriever $retriever): int
    {
        $query = DB::table('fichas_tecnicas');

        if (! $this->option('todas')) {
            $query->whereNull('embedding');
        }

        $fichas = $query->get(['id', 'nombre', 'categoria']);

        if ($fichas->isEmpty()) {
            $this->info('No hay fichas por indexar. Usa --todas para regenerarlas.');
            return self::SUCCESS;
        }

        // Las secciones son los componentes del mueble — le dan mucha señal al embedding
        $secciones = DB::table('ficha_tecnica_items')
            ->whereIn('ficha_tecnica_id', $fichas->pluck('id'))
            ->whereNotNull('seccion')
            ->distinct()
            ->get(['ficha_tecnica_id', 'seccion'])
            ->groupBy('ficha_tecnica_id');

        $this->info("Indexando {$fichas->count()} ficha(s)…");
        $barra   = $this->output->createProgressBar($fichas->count());
        $fallos  = 0;

        foreach ($fichas as $ficha) {
            $texto = $retriever->textoDeFicha(
                $ficha,
                $secciones->get($ficha->id, collect())->pluck('seccion')->all(),
            );

            $vector = $retriever->embed($texto);

            if (! $vector) {
                $fallos++;
                $barra->advance();
                continue;
            }

            DB::table('fichas_tecnicas')->where('id', $ficha->id)->update([
                'embedding'    => json_encode($vector),
                'embedding_at' => now(),
            ]);

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);

        $ok = $fichas->count() - $fallos;
        $this->info("✅ {$ok} ficha(s) indexada(s)." . ($fallos ? "  ⚠️ {$fallos} fallo(s)." : ''));

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
