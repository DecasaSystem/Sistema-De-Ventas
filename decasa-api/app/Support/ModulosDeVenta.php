<?php

namespace App\Support;

/**
 * Vender ya no es el rol: son módulos que se prenden por persona
 * (docs/metodologia-de-trabajo-por-rol.md, fase 2). Antes Nueva orden,
 * Órdenes, Clientes, Cotizaciones, Caja, Consultar costo y Estadísticas los
 * veía cualquiera que no fuera conductor o despachador, y en el servidor no
 * se frenaban: un conductor podía listar todas las órdenes por la API.
 *
 * La migración `2026_11_20_000002_modulos_de_venta_en_usuarios` los prendió a
 * todos los que los tenían, así que nadie perdió nada el día del cambio.
 */
final class ModulosDeVenta
{
    /** columna => lo que ve la gente */
    public const MODULOS = [
        'acceso_nueva_orden'     => 'Nueva orden',
        'acceso_ordenes'         => 'Órdenes',
        'acceso_clientes'        => 'Clientes',
        'acceso_cotizaciones'    => 'Cotizaciones',
        'acceso_caja'            => 'Caja',
        'acceso_consultas_costo' => 'Consultar costo',
        'acceso_estadisticas'    => 'Estadísticas',
    ];

    /** Los roles que nunca los tuvieron en pantalla. */
    public const SIN_VENTAS_ANTES = ['conductor', 'despachador'];

    public static function es(string $columna): bool
    {
        return array_key_exists($columna, self::MODULOS);
    }

    /**
     * Lo de siempre, para cuando la columna todavía no existe (pruebas con el
     * esquema montado a mano, o la app vieja antes de migrar).
     */
    public static function comoAntes(?string $rol): bool
    {
        return ! in_array($rol, self::SIN_VENTAS_ANTES, true);
    }

    /** Con qué arrancan los que se crean de aquí en adelante. */
    public static function deArranque(?string $arquetipo): bool
    {
        return in_array($arquetipo, ['vendedor', 'supervisor'], true);
    }

    /**
     * Los que trae un rol cuando no se le han escogido (`roles.modulos_venta`
     * null). Vendedor: lo que dijo el usuario el 2026-11-19 ("Nueva orden,
     * Órdenes, Clientes, Cotizaciones, Mis estadísticas, Consultar costo; solo
     * eso"): Caja va aparte. Supervisor: todos.
     */
    public const DEL_ARQUETIPO = [
        'vendedor'   => ['acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones',
                         'acceso_consultas_costo', 'acceso_estadisticas'],
        'supervisor' => ['acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones',
                         'acceso_caja', 'acceso_consultas_costo', 'acceso_estadisticas'],
    ];

    /** Los módulos de venta que trae un rol (lista de columnas). */
    public static function delRol(?\App\Models\Rol $rol, ?string $rolTexto = null): array
    {
        if ($rol && is_array($rol->modulos_venta ?? null)) {
            return array_values(array_intersect(array_keys(self::MODULOS), $rol->modulos_venta));
        }

        return self::DEL_ARQUETIPO[$rol?->arquetipo ?? $rolTexto] ?? [];
    }

    /**
     * Lo que le queda a la persona: lo de su rol, más lo que se le agregó,
     * menos lo que se le quitó. Devuelve columna => bool.
     */
    public static function quedan(array $delRol, array $ajustes): array
    {
        $out = [];
        foreach (array_keys(self::MODULOS) as $c) {
            $out[$c] = array_key_exists($c, $ajustes) ? (bool) $ajustes[$c] : in_array($c, $delRol, true);
        }

        return $out;
    }

    /**
     * Los ajustes que hacen falta para que la persona quede con `$quiere`
     * siendo `$delRol` lo de su rol: solo las diferencias. Lo que coincide con
     * el rol no se guarda, así sigue al rol si el rol cambia después.
     */
    public static function ajustesPara(array $delRol, array $quiere): array
    {
        $ajustes = [];
        foreach (array_keys(self::MODULOS) as $c) {
            if (! array_key_exists($c, $quiere)) continue;
            if ((bool) $quiere[$c] !== in_array($c, $delRol, true)) {
                $ajustes[$c] = (bool) $quiere[$c];
            }
        }

        return $ajustes;
    }

    /**
     * Vuelve a poner las columnas de una persona según su rol y sus ajustes.
     * Las columnas son lo que lee todo el código (middleware, pantallas); el
     * rol y los ajustes son de dónde salen.
     */
    public static function aplicar(\App\Models\Usuario $u): void
    {
        if (! Esquema::columna('usuarios', 'modulos_ajustados')) return;

        $u->loadMissing('rolAsignado');
        $quedan = self::quedan(self::delRol($u->rolAsignado, $u->rol), $u->modulos_ajustados ?? []);
        $u->forceFill($quedan)->saveQuietly();
    }
}
