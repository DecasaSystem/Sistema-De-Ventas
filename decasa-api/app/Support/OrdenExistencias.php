<?php

namespace App\Support;

/**
 * Orden y filtro de existencias de las listas de stock (Inventario y Reserva
 * de fábrica). Una sola puerta para que las dos listas ordenen igual.
 *
 * orden:      stock_desc (por defecto), stock_asc, libre_desc,
 *             nombre_asc, nombre_desc, precio_desc, precio_asc
 * existencia: con_stock, agotados, bajo_minimo (este solo en una tienda:
 *             el mínimo es de cada tienda)
 *
 * Un valor que no se conoce se ignora en vez de dar error: la lista no
 * debe caerse porque una versión vieja de la app mande otra cosa.
 *
 * Con "todas las tiendas" la consulta va agrupada, así que se ordena y se
 * filtra sobre las sumas (HAVING).
 *
 * El desempate final por id no es cosmético: sin él el orden entre filas
 * empatadas queda indefinido (hay categorías con 33 productos en el mismo
 * número), y con LIMIT/OFFSET una fila puede salir en dos páginas y otra
 * en ninguna. Quien recorre todas las páginas para el Excel se la llevaría
 * repetida.
 */
class OrdenExistencias
{
    public static function aplicar($query, $orden, $existencia, bool $agrupado = false): void
    {
        $disp  = $agrupado ? 'COALESCE(SUM(inventario.cantidad_disponible), 0)' : 'COALESCE(inventario.cantidad_disponible, 0)';
        $res   = $agrupado ? 'COALESCE(SUM(inventario.cantidad_reservada), 0)'  : 'COALESCE(inventario.cantidad_reservada, 0)';
        $donde = $agrupado ? 'havingRaw' : 'whereRaw';

        if ($existencia === 'con_stock') {
            $query->{$donde}("{$disp} > 0");
        } elseif ($existencia === 'agotados') {
            $query->{$donde}("{$disp} <= 0");
        } elseif ($existencia === 'bajo_minimo' && ! $agrupado) {
            // Solo los que tienen un mínimo puesto: sin mínimo no hay "bajo".
            $query->whereRaw('COALESCE(inventario.stock_minimo, 0) > 0')
                  ->whereRaw("{$disp} <= COALESCE(inventario.stock_minimo, 0)");
        }

        match ($orden) {
            'stock_asc'   => $query->orderByRaw("{$disp} ASC"),
            'libre_desc'  => $query->orderByRaw("({$disp} - {$res}) DESC")->orderByRaw("{$disp} DESC"),
            'nombre_asc'  => $query->orderBy('productos.nombre'),
            'nombre_desc' => $query->orderBy('productos.nombre', 'desc'),
            'precio_desc' => $query->orderBy('productos.precio_base', 'desc'),
            'precio_asc'  => $query->orderBy('productos.precio_base'),
            default       => $query->orderByRaw("{$disp} DESC"),
        };

        $query->orderBy('productos.nombre')->orderBy('productos.id');
    }
}
