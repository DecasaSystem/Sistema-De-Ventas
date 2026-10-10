<?php

namespace App\Services\Finanzas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los ajustes del módulo de Finanzas, en `configuracion` (clave
 * `finanzas_config`). Todo editable desde Finanzas → Ajustes: son decisiones
 * del negocio y del contador, no del código (docs/plan-gestion-financiera.md §12).
 */
class ConfigFinanzas
{
    public const CLAVE = 'finanzas_config';

    public const DEFECTO = [
        // Los precios de venta traen IVA (el pool de comisiones divide por 1,19).
        'iva_pct'               => 19,
        // La FV2 marcada "sin descontar IVA" no lleva IVA adentro.
        'fv2_sin_iva_no_gravada' => true,
        // Cada cuánto se declara: 'bimestral' | 'cuatrimestral'.
        'periodo_iva'           => 'bimestral',
        // Lo que cobra el datáfono / Addi sobre lo pagado así.
        'franquicia_pct'        => 5.5,
        // Saldo en caja y bancos a una fecha: con él se proyecta "hasta cuándo alcanza".
        'saldo_inicial'         => null,
        'saldo_fecha'           => null,
        // Cómo se reparten los gastos generales entre tiendas: 'ventas' | 'partes_iguales' | 'ninguno'.
        'reparto_generales'     => 'ventas',
        // Umbrales del semáforo (% de los ingresos netos).
        'umbral_nomina_pct'     => 35,
        'umbral_margen_pct'     => 10,
        // Mostrar margen bruto estimado con las fichas de costo.
        'usar_costo_fichas'     => true,
    ];

    private static ?array $cache = null;

    public static function olvidarCache(): void
    {
        self::$cache = null;
    }

    public static function todo(): array
    {
        if (self::$cache !== null) return self::$cache;

        $guardado = [];
        try {
            if (\App\Support\Esquema::tabla('configuracion')) {
                $v = DB::table('configuracion')->where('clave', self::CLAVE)->value('valor');
                $guardado = $v ? (json_decode($v, true) ?: []) : [];
            }
        } catch (\Throwable) {
            $guardado = [];
        }

        return self::$cache = array_merge(self::DEFECTO, array_intersect_key($guardado, self::DEFECTO));
    }

    public static function get(string $clave)
    {
        return self::todo()[$clave] ?? null;
    }

    public static function guardar(array $valores): array
    {
        $cfg = array_merge(self::todo(), array_intersect_key($valores, self::DEFECTO));
        DB::table('configuracion')->updateOrInsert(
            ['clave' => self::CLAVE],
            ['valor' => json_encode($cfg), 'updated_at' => now()]
        );
        self::olvidarCache();

        return self::todo();
    }

    /** El divisor del IVA: 1,19 con el 19 %. */
    public static function divisorIva(): float
    {
        return 1 + ((float) self::get('iva_pct')) / 100;
    }
}
