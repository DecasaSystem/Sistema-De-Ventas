<?php

namespace App\Jobs;

use App\Models\NominaPeriodo;
use App\Services\NominaPeriodoService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Crea sola la quincena que corresponde a "hoy" — nadie tiene que acordarse
 * de abrir un período nuevo cada 1 y cada 16. Si ya existe (porque se creó a
 * mano o el job ya corrió), no hace nada: correr de más nunca duplica.
 *
 * DESDE evita generar retroactivamente la quincena del 16-31 de agosto de
 * 2026, que ya estaba en curso (y posiblemente ya creada a mano) cuando esto
 * se construyó — la automatización arranca limpia en la siguiente.
 */
class GenerarPeriodoNomina implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DESDE = '2026-09-01';

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function handle(): void
    {
        $hoy = Carbon::today('America/Bogota');

        if ($hoy->day <= 15) {
            $inicio = $hoy->copy()->startOfMonth();
            $fin    = $hoy->copy()->startOfMonth()->addDays(14);
        } else {
            $inicio = $hoy->copy()->startOfMonth()->addDays(15);
            $fin    = $hoy->copy()->endOfMonth()->startOfDay();
        }

        if ($inicio->lt(Carbon::parse(self::DESDE))) {
            Log::info('[nomina] Quincena anterior al arranque automático, no se genera', ['inicio' => $inicio->toDateString()]);
            return;
        }

        $yaExiste = NominaPeriodo::whereDate('fecha_inicio', $inicio->toDateString())
            ->whereDate('fecha_fin', $fin->toDateString())
            ->exists();

        if ($yaExiste) {
            return;
        }

        $nombre = "{$inicio->day} al {$fin->day} de " . self::MESES[$inicio->month - 1] . " de {$inicio->year}";

        $periodo = NominaPeriodoService::crear($nombre, $inicio, $fin);

        Log::info('[nomina] Período generado automáticamente', [
            'periodo_id' => $periodo->id,
            'nombre'     => $nombre,
        ]);
    }
}
