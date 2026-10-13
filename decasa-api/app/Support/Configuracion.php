<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Valores de la tabla `configuracion` leídos de a varios, recordados por
 * petición.
 *
 * El resumen de Finanzas leía tres claves en tres viajes a Aiven (~200 ms
 * cada uno): los ajustes de Finanzas, si el bono es salario y los ajustes de
 * prestaciones (2026-10-13). Con `junto` la primera lectura trae de una vez las
 * que se sabe que se van a necesitar.
 *
 * Quien escribe una clave llama olvidar(); se olvida también en
 * CachesDePeticion (pruebas y trabajos de la cola).
 */
class Configuracion
{
    /** Las claves que lee Finanzas en una misma petición. */
    public const DE_FINANZAS = [
        \App\Services\Finanzas\ConfigFinanzas::CLAVE,
        \App\Services\CostoEmpleador::CLAVE_BONO,
        \App\Services\Prestaciones::CLAVE,
    ];

    /** @var array<string, ?string> */
    private static array $valores = [];

    /** @param string[] $junto otras claves que conviene traer en el mismo viaje */
    public static function valor(string $clave, array $junto = []): ?string
    {
        if (array_key_exists($clave, self::$valores)) return self::$valores[$clave];

        $faltan = array_values(array_filter(
            array_unique([$clave, ...$junto]),
            fn ($c) => ! array_key_exists($c, self::$valores)
        ));
        $filas = DB::table('configuracion')->whereIn('clave', $faltan)->pluck('valor', 'clave');
        foreach ($faltan as $c) {
            self::$valores[$c] = $filas[$c] ?? null;
        }

        return self::$valores[$clave];
    }

    public static function olvidar(): void
    {
        self::$valores = [];
    }
}
