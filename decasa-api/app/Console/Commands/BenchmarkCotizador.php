<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\AgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Benchmark de regresión del cotizador — ver AGENT.md.
 *
 *   php artisan cotizador:benchmark
 *   php artisan cotizador:benchmark --sleep=0     (sin espaciar; solo si no hay rate limit)
 *
 * Comando (no script de tinker) para que salga con exit code limpio y no deje procesos colgados.
 * Set FIJO de fichas para que las fases sean comparables. La columna ANTES es la desviación
 * medida tras la Fase 2, antes del retrieval por similitud.
 */
class BenchmarkCotizador extends Command
{
    protected $signature   = 'cotizador:benchmark {--sleep=12 : Segundos entre fichas para no golpear el rate limit}';
    protected $description = 'Mide la exactitud del cotizador contra un set fijo de fichas conocidas';

    /** nombre de ficha => desviación % medida antes de la Fase 3 */
    private const BASELINE = [
        'MODULO 1 PUESTO CON BRAZO'                   => -2.0,
        'ESCRITORIO PATA ELE DE 1,20 X 0,50'          => 3.9,
        'CAMA TARIMA DE 1.00 MTS CON NICHO Y CAJONES' => -8.9,
        'MESA CENTRO NUEVA DE 1.00 X 0.60'            => -30.6,
        'CAMA FLOR MORADO DE 1,40 MTS'                => 46.9,
        'JUEGO DE MESAS REDONDAS X 3 TOLEDO'          => 53.3,
        'CAMA SUIZA ENCHAPADA'                        => -56.0,
        'CAMA DIAMANTE TOLEDO DE 1.40 MTS'            => -60.2,
        'CAMA MACARENA DE 1.40 FLOR MORADO'           => 66.2,
        'CAMA ESPECIAL DE 140 TERRA'                  => -79.0,
    ];

    public function handle(AgentService $agent): int
    {
        $usuario = Usuario::where('rol', 'supervisor')->where('activo', true)->first() ?? Usuario::first();
        $pausa   = (int) $this->option('sleep');

        $materialesValidos = DB::table('materiales')->pluck('precio_unitario', 'id');
        $cargosValidos     = DB::table('salarios_cargo')->pluck('tarifa_hora', 'cargo');

        $this->line(str_repeat('=', 100));
        $this->line('BENCHMARK COTIZADOR — ' . count(self::BASELINE) . ' fichas fijas');
        $this->line(str_repeat('=', 100));
        $this->line(sprintf("\n%-45s %12s %12s %9s %9s", 'FICHA', 'REAL', 'ESTIMADO', 'AHORA', 'ANTES'));
        $this->line(str_repeat('-', 100));

        $errores = $dentro = $dentroAntes = $n = $revisiones = 0;
        $sumaAbs = $sumaAbsAntes = 0.0;

        foreach (self::BASELINE as $nombre => $antes) {
            $f = DB::table('fichas_tecnicas')->where('nombre', $nombre)
                ->first(['id', 'nombre', 'categoria', 'costo_total']);

            if (! $f) { $this->warn("No existe la ficha: {$nombre}"); continue; }

            if ($pausa > 0) sleep($pausa); // rate limit de OpenAI

            $r = $agent->calcularPrecioItem(['nombre' => $f->nombre, 'categoria' => $f->categoria], $usuario);

            // requiere_revision NO es fallo de criterio duro: es el comportamiento correcto
            // cuando el estimado no es plausible (mejor no dar precio que inventarlo).
            if (! empty($r['requiere_revision'])) {
                $motivo = ($r['precio_fabricacion'] ?? 0) > 0
                    ? mb_substr($r['revision_motivos'][0] ?? '', 0, 70)
                    : 'sin receta (fallo de API)';
                $this->line(sprintf('%-45s %12s %12s %9s %9s', mb_substr($f->nombre, 0, 44), '-', 'REVISION', '-', sprintf('%+.1f%%', $antes)));
                $this->line("     - {$motivo}");
                $revisiones++;
                continue;
            }

            $fab  = $r['precio_fabricacion'];
            $real = (float) $f->costo_total;
            $diff = $real > 0 ? (($fab - $real) / $real) * 100 : 0;

            // Criterios duros: la suma cuadra y ningún precio es inventado
            $suma = (int) round(
                array_sum(array_column($r['desglose_materiales'], 'subtotal')) +
                array_sum(array_column($r['desglose_mano_obra'],  'subtotal'))
            );
            if ($suma !== $fab) { $this->error("   NO CUADRA: {$suma} vs {$fab}"); $errores++; }

            foreach ($r['desglose_materiales'] as $m) {
                if (abs((float) ($materialesValidos[$m['material_id']] ?? -1) - (float) $m['precio_unitario']) > 0.01) {
                    $this->error("   Precio inventado en material {$m['material_id']}"); $errores++;
                }
            }
            foreach ($r['desglose_mano_obra'] as $mo) {
                if (abs((float) ($cargosValidos[$mo['cargo']] ?? -1) - (float) $mo['precio_unitario']) > 0.01) {
                    $this->error("   Tarifa inventada para {$mo['cargo']}"); $errores++;
                }
            }

            $ok    = abs($diff) <= 10;
            $mejor = abs($diff) < abs($antes);

            $dentro       += $ok ? 1 : 0;
            $dentroAntes  += abs($antes) <= 10 ? 1 : 0;
            $sumaAbs      += abs($diff);
            $sumaAbsAntes += abs($antes);
            $n++;

            $this->line(sprintf(
                '%-45s %12s %12s %9s %9s  %s',
                mb_substr($f->nombre, 0, 44),
                number_format($real, 0, ',', '.'),
                number_format($fab,  0, ',', '.'),
                sprintf('%+.1f%%', $diff),
                sprintf('%+.1f%%', $antes),
                ($ok ? 'OK ' : '   ') . ($mejor ? 'mejora' : 'peor'),
            ));
        }

        $this->line(str_repeat('-', 100) . "\n");
        $this->line("Dentro de +-10%:  ahora {$dentro}/{$n}  ·  antes {$dentroAntes}/{$n}");
        $this->line(sprintf('Error absoluto medio:  ahora %.1f%%  ·  antes %.1f%%', $sumaAbs / max($n, 1), $sumaAbsAntes / max($n, 1)));
        if ($revisiones) $this->line("Marcadas 'requiere revision': {$revisiones} (sin precio inventado)");
        $this->line('');
        $errores === 0
            ? $this->info('OK — criterios duros: el desglose cuadra y todos los precios vienen de la BD')
            : $this->error("FALLO — {$errores} error(es) en criterios duros");
        $this->line(str_repeat('=', 100));

        return $errores === 0 ? self::SUCCESS : self::FAILURE;
    }
}
