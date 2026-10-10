<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Schema::hasTable / hasColumn recordados.
 *
 * Finanzas pregunta "¿ya existe tal tabla?" en cada fuente (para que las
 * pruebas con esquemas a mano no revienten), y el resumen pasaba por las mismas
 * fuentes varias veces: ~25 preguntas al esquema, cada una un viaje a Aiven
 * (~200 ms). Con el resto de consultas, /finanzas/resumen tardaba tanto que el
 * proxy de Vercel cortaba con 502 (visto el 2026-10-10, recién subido).
 *
 * Primero se recordó por petición, pero así cada carga de Finanzas seguía
 * pagando ~15 preguntas (3 s). El esquema de producción solo cambia cuando
 * se despliega con migraciones nuevas, así que fuera de las pruebas también se
 * guarda en la caché, con la versión de las migraciones en la llave: un
 * deploy que trae una migración nueva pregunta de nuevo solo.
 *
 * Lo de la petición se olvida en CachesDePeticion (pruebas y trabajos de la cola).
 */
class Esquema
{
    private static array $tablas = [];
    private static array $columnas = [];
    private static ?string $version = null;

    public static function tabla(string $tabla): bool
    {
        return self::$tablas[$tabla] ??= self::recordado("t:$tabla", fn () => Schema::hasTable($tabla));
    }

    public static function columna(string $tabla, string $columna): bool
    {
        return self::$columnas["$tabla.$columna"] ??= self::recordado("c:$tabla.$columna", fn () => Schema::hasColumn($tabla, $columna));
    }

    public static function olvidar(): void
    {
        self::$tablas = [];
        self::$columnas = [];
    }

    private static function recordado(string $clave, callable $preguntar): bool
    {
        // Las pruebas montan y cambian esquemas a mano en el mismo proceso.
        if (app()->runningUnitTests()) return $preguntar();

        // Solo se guarda el "sí existe": las migraciones van solo hacia adelante
        // (nada se borra), mientras que un "no existe" puede ser una migración
        // que falló al arrancar (`migrate --force || true`) y se arregla luego.
        $llave = 'esquema:' . self::version() . ':' . $clave;
        try {
            if (Cache::get($llave)) return true;
        } catch (\Throwable) {
            return $preguntar();
        }

        $existe = $preguntar();
        if ($existe) {
            try { Cache::forever($llave, 1); } catch (\Throwable) {}
        }

        return $existe;
    }

    /** Cuántas migraciones hay y cuál es la última: cambia con cada deploy que trae una. */
    private static function version(): string
    {
        if (self::$version !== null) return self::$version;

        $archivos = glob(database_path('migrations/*.php')) ?: [];

        return self::$version = count($archivos) . '-' . md5((string) end($archivos));
    }
}
