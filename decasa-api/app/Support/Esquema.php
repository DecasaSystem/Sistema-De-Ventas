<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Schema::hasTable / hasColumn recordados por petición.
 *
 * Finanzas pregunta "¿ya existe tal tabla?" en cada fuente (para que las
 * pruebas con esquemas a mano no revienten), y el resumen pasaba por las mismas
 * fuentes varias veces: ~25 preguntas al esquema, cada una un viaje a Aiven
 * (~200 ms). Con el resto de consultas, /finanzas/resumen tardaba tanto que el
 * proxy de Vercel cortaba con 502 (visto el 2026-10-10, recién subido).
 *
 * Se olvida en CachesDePeticion (pruebas y trabajos de la cola).
 */
class Esquema
{
    private static array $tablas = [];
    private static array $columnas = [];

    public static function tabla(string $tabla): bool
    {
        return self::$tablas[$tabla] ??= Schema::hasTable($tabla);
    }

    public static function columna(string $tabla, string $columna): bool
    {
        return self::$columnas["$tabla.$columna"] ??= Schema::hasColumn($tabla, $columna);
    }

    public static function olvidar(): void
    {
        self::$tablas = [];
        self::$columnas = [];
    }
}
