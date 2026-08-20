<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * El calendario de la nómina: en qué ciclo cae una fecha según con qué
 * frecuencia cobra el trabajador. Todo se deduce del calendario, así que
 * no hay nada que crear ni que mantener — el ciclo de alguien existe por
 * el solo hecho de que hoy es una fecha.
 *
 * TODA fecha del módulo tiene que pasar por `fecha()`, `dia()` u `hoy()`.
 * La app corre en UTC pero el taller vive en Bogotá, y si se mezcla una
 * medianoche UTC con una medianoche de Bogotá quedan 5 horas de diferencia:
 * `diffInDays` devuelve 13.79 en vez de 14, se trunca a 13 y la quincena
 * termina pagando un día de menos. Normalizando todo a la misma zona, las
 * diferencias vuelven a ser números enteros exactos.
 */
class CicloNomina
{
    public const FRECUENCIAS = ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'];

    /** Donde queda el taller: la nómina se cuenta en días de Bogotá. */
    public const ZONA = 'America/Bogota';

    /** Hoy, como día de calendario. */
    public static function hoy(): Carbon
    {
        return Carbon::today(self::ZONA);
    }

    /**
     * Una fecha de calendario (una columna `date` o un 'Y-m-d'): se toma el
     * año, mes y día tal cual vienen, sin convertir de zona — un 15 de
     * agosto es el 15 de agosto sin importar en qué huso se guardó.
     */
    public static function fecha(CarbonInterface|string $valor): Carbon
    {
        $c = $valor instanceof CarbonInterface ? $valor : Carbon::parse($valor);

        return Carbon::create($c->year, $c->month, $c->day, 0, 0, 0, self::ZONA);
    }

    /**
     * El día de calendario en que cayó un instante (`created_at`, `now()`).
     * Aquí sí se convierte primero a la zona del taller: algo guardado a
     * las 02:00 UTC pasó, en Bogotá, el día anterior.
     */
    public static function dia(CarbonInterface $instante): Carbon
    {
        return self::fecha($instante->copy()->setTimezone(self::ZONA));
    }

    /**
     * Los ciclos de 20 días no se pueden anclar al mes (no caben enteros),
     * así que se cuentan corridos desde una fecha fija, igual para todos:
     * así dos personas con la misma frecuencia cobran el mismo día y no
     * hay que llevarle la cuenta a cada una por separado.
     */
    private const ANCLA_20_DIAS = '2026-09-01';

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    /**
     * Los días que se pagan en un ciclo completo. Es la convención de
     * siempre (mes de 30, quincena de 15), no los días del calendario: la
     * quincena del 16 al 31 son 16 días corridos pero se pagan 15, igual
     * que la del 16 al 28 de febrero, que son 13. Si se pagaran los días
     * corridos, el mismo sueldo daría distinto según el mes.
     */
    public static function diasPagados(string $periodicidad): int
    {
        return match ($periodicidad) {
            'diario'    => 1,
            'semanal'   => 7,
            'quincenal' => 15,
            '20_dias'   => 20,
            'mensual'   => 30,
        };
    }

    /**
     * El ciclo de calendario en el que cae $fecha.
     *
     * @return array{0: Carbon, 1: Carbon} [inicio, fin], ambos a las 00:00
     */
    public static function rango(string $periodicidad, CarbonInterface|string $fecha): array
    {
        $f = self::fecha($fecha);

        return match ($periodicidad) {
            'diario' => [$f->copy(), $f->copy()],
            'semanal' => [
                $f->copy()->startOfWeek(Carbon::MONDAY),
                $f->copy()->startOfWeek(Carbon::MONDAY)->addDays(6),
            ],
            'quincenal' => $f->day <= 15
                ? [$f->copy()->startOfMonth(), $f->copy()->startOfMonth()->addDays(14)]
                : [$f->copy()->startOfMonth()->addDays(15), $f->copy()->endOfMonth()->startOfDay()],
            '20_dias' => self::rango20Dias($f),
            'mensual' => [$f->copy()->startOfMonth(), $f->copy()->endOfMonth()->startOfDay()],
        };
    }

    /** El ciclo que sigue al que empieza en $inicio. */
    public static function siguiente(string $periodicidad, CarbonInterface|string $inicio): array
    {
        [, $fin] = self::rango($periodicidad, $inicio);

        return self::rango($periodicidad, $fin->copy()->addDay());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function rango20Dias(Carbon $fecha): array
    {
        $ancla = self::fecha(self::ANCLA_20_DIAS);
        // floor y no intdiv: para una fecha anterior al ancla, intdiv trunca
        // hacia cero y devuelve el bucket futuro en vez del pasado.
        $bucket = (int) floor($ancla->diffInDays($fecha, false) / 20);
        $inicio = $ancla->copy()->addDays($bucket * 20);

        return [$inicio, $inicio->copy()->addDays(19)];
    }

    /** Cómo se llama el ciclo cuando hay que mostrarlo o guardarlo. */
    public static function nombre(string $periodicidad, CarbonInterface $inicio, CarbonInterface $fin): string
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

    public static function label(string $periodicidad): string
    {
        return match ($periodicidad) {
            'diario'    => 'Diario',
            'semanal'   => 'Semanal',
            'quincenal' => 'Quincenal',
            '20_dias'   => 'Cada 20 días',
            'mensual'   => 'Mensual',
            default     => ucfirst($periodicidad),
        };
    }
}
