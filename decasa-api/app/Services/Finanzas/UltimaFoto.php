<?php

namespace App\Services\Finanzas;

use Illuminate\Support\Facades\Cache;

/**
 * La última foto de cada pantalla de Finanzas, guardada en el servidor.
 *
 * Calcular el resumen son ~44 consultas a Aiven (~200 ms cada una): ~10 s.
 * La pantalla ya pintaba lo último que vio en esa sesión mientras llegaba lo
 * nuevo (utils/memoriaFinanzas), pero al abrir la app o recargar no había
 * nada y se veía el spinner todo ese rato (queja del 2026-10-28). Ahora cada
 * cálculo deja su copia aquí y la pantalla, mientras calcula, pide la copia
 * (`?foto=1`, sin consultas de Finanzas) y la pinta atenuada con su hora.
 *
 * Es solo para pintar mientras tanto: la cifra que queda es siempre la
 * recién calculada. En el servidor y no en el aparato: son las cifras más
 * sensibles del sistema (por eso memoriaFinanzas vive solo en memoria).
 * Lo que tiene botón Pagar (los próximos pagos del resumen) no se guarda:
 * uno viejo invitaría a pagar dos veces.
 */
class UltimaFoto
{
    /** Más que esto ya no sirve ni para pintar mientras tanto. */
    private const DIAS = 40;

    public static function guardar(string $clave, array $datos): void
    {
        try {
            Cache::put(self::llave($clave), [
                'datos'   => $datos,
                'foto_at' => now(Periodo::TZ)->toIso8601String(),
            ], now()->addDays(self::DIAS));
        } catch (\Throwable) {
            // Sin caché la pantalla funciona igual (solo sin foto).
        }
    }

    /** @return array{datos: array, foto_at: string}|null */
    public static function leer(string $clave): ?array
    {
        try {
            $foto = Cache::get(self::llave($clave));
        } catch (\Throwable) {
            return null;
        }

        return is_array($foto) && isset($foto['datos']) ? $foto : null;
    }

    private static function llave(string $clave): string
    {
        return 'finanzas:foto:' . $clave;
    }
}
