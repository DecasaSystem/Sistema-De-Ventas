<?php

namespace App\Jobs;

use App\Models\Empleado;
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
 * Crea solos los períodos que correspondan a "hoy" — nadie tiene que
 * acordarse de abrir uno nuevo. Cada trabajador tiene su propia frecuencia
 * de pago (la mayoría quincenal, pero puede haber diario/semanal/cada 20
 * días/mensual), así que este job recorre las 5 frecuencias cada vez que
 * corre y solo genera la que le toca a cada una, con solo esos trabajadores
 * adentro. Si ya existe (a mano o de una corrida anterior), no hace nada:
 * correr de más nunca duplica.
 *
 * DESDE evita generar retroactivamente lo que ya estaba en curso (quincena
 * del 16-31 de agosto de 2026) cuando esto se construyó — la automatización
 * arranca limpia en la siguiente ocasión de cada frecuencia.
 */
class GenerarPeriodoNomina implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DESDE = '2026-09-01';

    private const FRECUENCIAS = ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'];

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function handle(): void
    {
        $hoy = Carbon::today('America/Bogota');

        foreach (self::FRECUENCIAS as $periodicidad) {
            $this->generarSiCorresponde($periodicidad, $hoy);
        }
    }

    private function generarSiCorresponde(string $periodicidad, Carbon $hoy): void
    {
        [$inicio, $fin] = $this->rangoPara($periodicidad, $hoy);

        if ($inicio->lt(Carbon::parse(self::DESDE))) {
            return;
        }

        $yaExiste = NominaPeriodo::where('periodicidad', $periodicidad)
            ->whereDate('fecha_inicio', $inicio->toDateString())
            ->whereDate('fecha_fin', $fin->toDateString())
            ->exists();

        if ($yaExiste) {
            return;
        }

        $hayTrabajadores = Empleado::where('activo', true)->where('periodicidad', $periodicidad)->exists();
        if (! $hayTrabajadores) {
            return;
        }

        $nombre = $this->nombrePara($periodicidad, $inicio, $fin);
        $periodo = NominaPeriodoService::crear($nombre, $inicio, $fin, $periodicidad);

        Log::info('[nomina] Período generado automáticamente', [
            'periodo_id'   => $periodo->id,
            'periodicidad' => $periodicidad,
            'nombre'       => $nombre,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangoPara(string $periodicidad, Carbon $hoy): array
    {
        return match ($periodicidad) {
            'diario' => [$hoy->copy(), $hoy->copy()],
            'semanal' => [
                $hoy->copy()->startOfWeek(Carbon::MONDAY),
                $hoy->copy()->startOfWeek(Carbon::MONDAY)->addDays(6),
            ],
            'quincenal' => $hoy->day <= 15
                ? [$hoy->copy()->startOfMonth(), $hoy->copy()->startOfMonth()->addDays(14)]
                : [$hoy->copy()->startOfMonth()->addDays(15), $hoy->copy()->endOfMonth()->startOfDay()],
            '20_dias' => $this->rango20Dias($hoy),
            'mensual' => [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()->startOfDay()],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango20Dias(Carbon $hoy): array
    {
        $desde = Carbon::parse(self::DESDE);
        // floor, no intdiv: para una fecha anterior a DESDE, intdiv trunca
        // hacia cero y calcula por error el primer bucket futuro en vez de
        // uno pasado — floor lo manda correctamente antes de DESDE, donde
        // el chequeo de arriba ya lo descarta.
        $bucket = (int) floor($desde->diffInDays($hoy, false) / 20);
        $inicio = $desde->copy()->addDays($bucket * 20);

        return [$inicio, $inicio->copy()->addDays(19)];
    }

    private function nombrePara(string $periodicidad, Carbon $inicio, Carbon $fin): string
    {
        if ($periodicidad === 'diario') {
            return "{$inicio->day} de " . self::MESES[$inicio->month - 1] . " de {$inicio->year}";
        }

        if ($periodicidad === 'mensual') {
            return ucfirst(self::MESES[$inicio->month - 1]) . " de {$inicio->year}";
        }

        $prefijo = $periodicidad === 'semanal' ? 'Semana del ' : '';

        if ($inicio->month === $fin->month && $inicio->year === $fin->year) {
            return "{$prefijo}{$inicio->day} al {$fin->day} de " . self::MESES[$inicio->month - 1] . " de {$inicio->year}";
        }

        // Cruza de mes (o de año): se nombran los dos extremos completos.
        return "{$prefijo}{$inicio->day} de " . self::MESES[$inicio->month - 1] .
            " al {$fin->day} de " . self::MESES[$fin->month - 1] . " de {$fin->year}";
    }
}
