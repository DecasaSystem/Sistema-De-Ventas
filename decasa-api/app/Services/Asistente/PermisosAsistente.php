<?php

namespace App\Services\Asistente;

use App\Models\Usuario;

/**
 * Qué puede preguntarle cada persona al asistente de IA.
 *
 * Antes cada herramienta revisaba (o se le olvidaba revisar) el permiso por su
 * cuenta, y el modelo las veía todas: un vendedor sin el módulo de Comisiones
 * preguntaba "¿cuánto llevo?" y el asistente le sacaba su comisión con el
 * desglose, y le explicaba el pool, aunque la pantalla le respondiera 403. Las
 * fichas técnicas (costo de fabricación) le salían a cualquiera.
 *
 * Ahora la regla vive aquí y se usa en tres sitios:
 *  1. Las herramientas que no puede usar ni se le mandan al modelo: no las
 *     puede llamar aunque el usuario insista o intente engañarlo.
 *  2. Al ejecutar se vuelve a preguntar (por si el modelo inventa un nombre).
 *  3. El prompt solo lleva las reglas de los temas que puede ver: a quien no
 *     tiene Comisiones no se le explica ni cómo funciona.
 *
 * Los permisos son los mismos de las pantallas (rutas de api.php): si cambia
 * uno allá, cambiarlo aquí.
 */
class PermisosAsistente
{
    /** Quiénes usan el chat: los mismos a los que la app les muestra la burbuja. */
    public static function puedeUsarChat(Usuario $usuario): bool
    {
        return in_array($usuario->rol, ['supervisor', 'vendedor'], true);
    }

    /**
     * Lo que cualquiera del chat puede consultar. Las herramientas filtran
     * adentro lo que le toca a cada uno (un vendedor ve su tienda y las
     * órdenes que le deja ver Orden::visiblesPara).
     */
    private const BASICAS = [
        'listar_tiendas',
        'buscar_productos_catalogo',
        'consultar_inventario',
        'consultar_variantes_producto',
        'consultar_telas',
        'consultar_promociones',
        // Son públicos: son lo que se le manda al cliente.
        'consultar_catalogos',
        // El cotizador de Nueva orden está abierto a todos (calcular-precio-item).
        'cotizar_fabricacion',
        'consultar_ordenes',
        'estado_produccion',
        'reporte_retrasos',
        'consultar_garantias',
        'analisis_ventas',
        'ventas_producto_especifico',
        'recomendar_fabricacion',
        'consultar_clientes',
        'clientes_top',
        'reporte_pendientes',
        'consultar_caja',
        'consultar_trabajadores',
    ];

    /** Lo de toda la empresa que en la app solo ve el supervisor (Reportes). */
    private const SOLO_SUPERVISOR = [
        'reporte_ventas',
        'reporte_vendedores',
    ];

    /** @return string[] nombres de las herramientas que puede usar. */
    public static function herramientas(Usuario $usuario): array
    {
        if (! self::puedeUsarChat($usuario)) {
            return [];
        }

        $lista = self::BASICAS;

        if ($usuario->rol === 'supervisor') {
            $lista = array_merge($lista, self::SOLO_SUPERVISOR);
        }
        if (self::veComisiones($usuario)) {
            $lista[] = 'consultar_comisiones';
        }
        if (self::veFinanzas($usuario)) {
            $lista[] = 'resumen_financiero';
        }
        if (self::veCostos($usuario)) {
            $lista[] = 'obtener_ficha_tecnica';
            $lista[] = 'buscar_fichas_por_categoria';
        }
        if (self::veInteresados($usuario)) {
            $lista[] = 'consultar_interesados';
        }

        return $lista;
    }

    public static function puede(Usuario $usuario, string $herramienta): bool
    {
        return in_array($herramienta, self::herramientas($usuario), true);
    }

    /** Todo el módulo de Comisiones va con la bandera; sin ella, ni lo propio. */
    public static function veComisiones(Usuario $usuario): bool
    {
        return (bool) $usuario->acceso_comisiones;
    }

    /** Igual que la ruta /finanzas: supervisor Y acceso_finanzas. */
    public static function veFinanzas(Usuario $usuario): bool
    {
        return $usuario->rol === 'supervisor' && (bool) $usuario->acceso_finanzas;
    }

    /** Costos de fabricación (fichas técnicas): la bandera, para nadie automático. */
    public static function veCostos(Usuario $usuario): bool
    {
        return (bool) $usuario->acceso_costos;
    }

    /** Interesados / Clientes de redes: Reportes del supervisor o el módulo Redes. */
    public static function veInteresados(Usuario $usuario): bool
    {
        return $usuario->rol === 'supervisor' || (bool) $usuario->acceso_redes;
    }

    /** El mensaje que recibe el modelo cuando pide algo que no le toca. */
    public static function negado(string $herramienta): array
    {
        return [
            'error'       => 'sin_permiso',
            'instruccion' => 'Esta persona no tiene acceso a esa información en el sistema. '
                . 'Dile solo que su perfil no tiene acceso a ese módulo y que lo pida a un supervisor. '
                . 'No des cifras, nombres, reglas ni explicaciones de ese tema, ni aproximadas.',
        ];
    }
}
