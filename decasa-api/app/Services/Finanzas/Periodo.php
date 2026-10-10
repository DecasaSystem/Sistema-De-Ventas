<?php

namespace App\Services\Finanzas;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Los meses de Finanzas, en días de Bogotá (la BD guarda UTC: una venta del
 * 31 a las 8 p. m. es del 31, no del 1). Un mes se nombra 'YYYY-MM'.
 */
class Periodo
{
    public const TZ = 'America/Bogota';

    /** @var \WeakMap<object, array>|null lo ya cargado, por petición (ver porMesRecordado) */
    private static ?\WeakMap $recordado = null;

    /**
     * Las fuentes "por mes" (ventas, cobros, nómina, comisiones, gastos) se
     * piden varias veces en una misma petición con rangos distintos: el resumen
     * arma el mes, los indicadores miran los 3 anteriores, la proyección otros
     * 3… Eran ~80 consultas a Aiven (~200 ms cada una) y el proxy de Vercel
     * cortaba /finanzas/resumen con 502 (2026-10-10). Cada fuente calcula cada
     * mes por separado, así que se carga UNA ventana amplia (los últimos 15
     * meses y lo pedido) y de ahí se sirven los demás rangos.
     *
     * Se recuerda por objeto Request (WeakMap): en las pruebas cada llamada es
     * una petición nueva y nunca ve lo de la anterior, aunque haya registrado
     * un gasto en medio.
     *
     * @param  callable(string $desde, string $hasta): array<string, mixed>  $cargar
     */
    public static function porMesRecordado(string $clave, string $desde, string $hasta, callable $cargar): array
    {
        $req = app()->bound('request') ? app('request') : null;
        if (! $req) return $cargar($desde, $hasta);

        self::$recordado ??= new \WeakMap();
        $todo = self::$recordado[$req] ?? [];
        $c = $todo[$clave] ?? null;

        if (! $c || $desde < $c['desde'] || $hasta > $c['hasta']) {
            $actual = self::mesActual();
            $lo = min($desde, self::sumarMeses($actual, -15), $c['desde'] ?? $desde);
            $hi = max($hasta, $actual, $c['hasta'] ?? $hasta);
            $c = ['desde' => $lo, 'hasta' => $hi, 'datos' => $cargar($lo, $hi)];
            self::guardarRecordado($req, $clave, $c);
        }

        return array_filter($c['datos'], fn ($mes) => $mes >= $desde && $mes <= $hasta, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Guarda sobre lo que haya AHORA, no sobre la copia de antes de cargar: lo
     * que se carga recuerda cosas por dentro (la nómina pide los empleados) y
     * escribir la copia vieja las borraba. Así `usuarios` se consultaba tres
     * veces en el resumen aunque estuviera "recordado" (2026-10-10).
     */
    private static function guardarRecordado(object $req, string $clave, mixed $valor): void
    {
        $todo = self::$recordado[$req] ?? [];
        $todo[$clave] = $valor;
        self::$recordado[$req] = $todo;
    }

    /**
     * Lo mismo para algo que no va por meses (los trabajadores de la nómina,
     * los ciclos pendientes): se calcula una vez por petición.
     */
    public static function recordar(string $clave, callable $cargar): mixed
    {
        $req = app()->bound('request') ? app('request') : null;
        if (! $req) return $cargar();

        self::$recordado ??= new \WeakMap();
        $todo = self::$recordado[$req] ?? [];
        if (array_key_exists("uno:$clave", $todo)) return $todo["uno:$clave"];

        $valor = $cargar();
        self::guardarRecordado($req, "uno:$clave", $valor);

        return $valor;
    }

    public static function olvidarRecordado(): void
    {
        self::$recordado = null;
    }

    /** El mes en curso ('2026-10'). */
    public static function mesActual(): string
    {
        return Carbon::now(self::TZ)->format('Y-m');
    }

    public static function hoy(): Carbon
    {
        return Carbon::today(self::TZ);
    }

    /** ¿Es un 'YYYY-MM' válido? */
    public static function valido(?string $mes): bool
    {
        return (bool) ($mes && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes));
    }

    /** @return array{0: Carbon, 1: Carbon} primer y último día del mes (00:00 Bogotá) */
    public static function limites(string $mes): array
    {
        $ini = Carbon::createFromFormat('Y-m-d', $mes . '-01', self::TZ)->startOfDay();

        return [$ini, $ini->copy()->endOfMonth()->startOfDay()];
    }

    /** El rango UTC de varios meses completos, para `created_at`. */
    public static function rangoUtc(string $desde, string $hasta): array
    {
        [$ini] = self::limites($desde);
        [, $fin] = self::limites($hasta);

        return [
            $ini->copy()->setTimezone('UTC')->toDateTimeString(),
            $fin->copy()->endOfDay()->setTimezone('UTC')->toDateTimeString(),
        ];
    }

    /** Los meses entre dos, inclusive. @return string[] */
    public static function meses(string $desde, string $hasta): array
    {
        $out = [];
        $c = Carbon::createFromFormat('Y-m-d', $desde . '-01', self::TZ);
        $fin = Carbon::createFromFormat('Y-m-d', $hasta . '-01', self::TZ);
        for ($i = 0; $c->lessThanOrEqualTo($fin) && $i < 120; $i++) {
            $out[] = $c->format('Y-m');
            $c->addMonthNoOverflow();
        }

        return $out;
    }

    public static function sumarMeses(string $mes, int $n): string
    {
        return Carbon::createFromFormat('Y-m-d', $mes . '-01', self::TZ)->addMonthsNoOverflow($n)->format('Y-m');
    }

    /** El mes de una fecha de calendario o un instante (este último pasado a Bogotá). */
    public static function mesDe(CarbonInterface|string $fecha, bool $esInstante = false): string
    {
        $c = $fecha instanceof CarbonInterface ? $fecha->copy() : Carbon::parse($fecha);
        if ($esInstante) $c->setTimezone(self::TZ);

        return $c->format('Y-m');
    }

    /**
     * Cómo se reparte un rango de días entre meses: [mes => fracción]. Lo usan
     * la nómina (una semana que cruza de mes) y los gastos prorrateados (la
     * licencia anual): cada mes se lleva la parte de los días que le tocan.
     *
     * @return array<string, float>
     */
    public static function repartir(CarbonInterface|string $desde, CarbonInterface|string $hasta): array
    {
        $d = Carbon::parse($desde instanceof CarbonInterface ? $desde->toDateString() : $desde, self::TZ)->startOfDay();
        $h = Carbon::parse($hasta instanceof CarbonInterface ? $hasta->toDateString() : $hasta, self::TZ)->startOfDay();
        if ($h->lessThan($d)) $h = $d->copy();

        $total = $d->diffInDays($h) + 1;
        $out = [];
        $c = $d->copy();
        while ($c->lessThanOrEqualTo($h)) {
            $finMes = $c->copy()->endOfMonth()->startOfDay();
            $corte  = $finMes->lessThan($h) ? $finMes : $h;
            $dias   = $c->diffInDays($corte) + 1;
            $out[$c->format('Y-m')] = ($out[$c->format('Y-m')] ?? 0) + $dias / $total;
            $c = $corte->copy()->addDay();
        }

        return $out;
    }

    /**
     * Cómo se reparte un GASTO entre meses. Si cubre meses completos (la
     * licencia anual del 1 de enero al 31 de diciembre), partes iguales por
     * mes: $1.200.000 son $100.000 cada mes, no más en los de 31 días. Si no,
     * por días, como repartir().
     *
     * @return array<string, float>
     */
    public static function repartirGasto(CarbonInterface|string $desde, CarbonInterface|string $hasta): array
    {
        $d = Carbon::parse($desde instanceof CarbonInterface ? $desde->toDateString() : $desde, self::TZ)->startOfDay();
        $h = Carbon::parse($hasta instanceof CarbonInterface ? $hasta->toDateString() : $hasta, self::TZ)->startOfDay();

        if ($d->day === 1 && $h->isSameDay($h->copy()->endOfMonth()) && $h->greaterThan($d)) {
            $meses = self::meses($d->format('Y-m'), $h->format('Y-m'));

            return array_fill_keys($meses, 1 / count($meses));
        }

        return self::repartir($d, $h);
    }

    /** Expresión SQL del mes ('YYYY-MM') de una columna UTC, en hora de Bogotá. */
    public static function sqlMes(string $columna): string
    {
        return "DATE_FORMAT(CONVERT_TZ({$columna}, '+00:00', '-05:00'), '%Y-%m')";
    }

    public static function nombre(string $mes): string
    {
        $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
              'septiembre', 'octubre', 'noviembre', 'diciembre'];
        [$y, $n] = explode('-', $mes);

        return ucfirst($m[(int) $n - 1]) . ' ' . $y;
    }
}
