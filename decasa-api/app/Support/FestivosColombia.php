<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Festivos de Colombia y días hábiles.
 *
 * Hace falta para los plazos de las garantías: la Ley 1480 (art. 58) da 15
 * días HÁBILES para responder una reclamación, y contar días de calendario
 * haría que el sistema diera por vencido algo que todavía está a tiempo (o al
 * revés, en Semana Santa).
 *
 * Es la misma cuenta que usan los agentes de chat (`fechas.js` en
 * Desktop/Agentes), sacada de la ley (Ley 51 de 1983, "Ley Emiliani") y no de
 * una lista a mano, para no tener que actualizarla cada año:
 *   - fijos: 1 ene, 1 may, 20 jul, 7 ago, 8 dic, 25 dic;
 *   - trasladables al lunes siguiente: 6 ene, 19 mar, 29 jun, 15 ago, 12 oct,
 *     1 nov, 11 nov;
 *   - según la Pascua: Jueves y Viernes Santo, Ascensión (+43), Corpus
 *     Christi (+64) y Sagrado Corazón (+71), que ya caen en lunes.
 */
final class FestivosColombia
{
    /** @var array<int, array<string, string>> */
    private static array $porAnio = [];

    /** @return array<string, string>  ['yyyy-mm-dd' => nombre] */
    public static function delAnio(int $y): array
    {
        if (isset(self::$porAnio[$y])) return self::$porAnio[$y];

        $f = [];
        $poner = function (CarbonImmutable $d, string $nombre) use (&$f) {
            $f[$d->toDateString()] = $nombre;
        };
        $fecha  = fn (int $m, int $d) => CarbonImmutable::create($y, $m, $d, 0, 0, 0, 'UTC');
        $alLunes = fn (CarbonImmutable $d) => $d->dayOfWeek === 1 ? $d : $d->addDays((8 - $d->dayOfWeek) % 7);

        $poner($fecha(1, 1), 'Año Nuevo');
        $poner($fecha(5, 1), 'Día del Trabajo');
        $poner($fecha(7, 20), 'Día de la Independencia');
        $poner($fecha(8, 7), 'Batalla de Boyacá');
        $poner($fecha(12, 8), 'Inmaculada Concepción');
        $poner($fecha(12, 25), 'Navidad');
        $poner($alLunes($fecha(1, 6)), 'Reyes Magos');
        $poner($alLunes($fecha(3, 19)), 'San José');
        $poner($alLunes($fecha(6, 29)), 'San Pedro y San Pablo');
        $poner($alLunes($fecha(8, 15)), 'Asunción de la Virgen');
        $poner($alLunes($fecha(10, 12)), 'Día de la Raza');
        $poner($alLunes($fecha(11, 1)), 'Todos los Santos');
        $poner($alLunes($fecha(11, 11)), 'Independencia de Cartagena');

        $pascua = self::domingoDePascua($y);
        $poner($pascua->subDays(3), 'Jueves Santo');
        $poner($pascua->subDays(2), 'Viernes Santo');
        $poner($pascua->addDays(43), 'Ascensión del Señor');
        $poner($pascua->addDays(64), 'Corpus Christi');
        $poner($pascua->addDays(71), 'Sagrado Corazón');

        return self::$porAnio[$y] = $f;
    }

    public static function esFestivo(\DateTimeInterface $d): bool
    {
        $d = CarbonImmutable::instance($d);

        return isset(self::delAnio($d->year)[$d->toDateString()]);
    }

    /** Lunes a viernes que no sea festivo. El sábado no es hábil para la ley. */
    public static function esHabil(\DateTimeInterface $d): bool
    {
        $d = CarbonImmutable::instance($d);

        return ! $d->isWeekend() && ! self::esFestivo($d);
    }

    /**
     * La fecha que resulta de contar N días hábiles DESPUÉS de $desde (el día
     * de la reclamación no cuenta: "a partir del día siguiente").
     */
    public static function sumarHabiles(\DateTimeInterface $desde, int $dias): CarbonImmutable
    {
        $d = CarbonImmutable::instance($desde)->startOfDay();
        while ($dias > 0) {
            $d = $d->addDay();
            if (self::esHabil($d)) $dias--;
        }

        return $d;
    }

    /** Domingo de Pascua (algoritmo anónimo gregoriano / Meeus). */
    private static function domingoDePascua(int $y): CarbonImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($y, $mes, $dia, 0, 0, 0, 'UTC');
    }
}
