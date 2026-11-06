<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cuánta plata hay en inventario: las unidades por el precio al que se venden.
 *
 * Pedido del dueño (2026-11-06): "me meto a Sofás con todas las tiendas, salen
 * 31 sofás y si se venden a 1 millón tiene que decir que hay 31 millones", por
 * tienda, por categoría y en total, "teniendo en cuenta las variantes".
 *
 * Es precio de VENTA (el de Inventario, ya con ganancia), no costo de
 * fabricación: el costo vive en Costos y no se mezcla aquí.
 *
 * Cómo se valora una unidad, producto por producto y tienda por tienda (nunca
 * sumando tiendas antes: cada tienda tiene su propio reparto por variantes):
 *
 *  - El stock del producto (`inventario.cantidad_disponible`) es lo que manda.
 *    Las variantes no son otro inventario: reparten PARTE de ese mismo total
 *    (ver StockVariantes).
 *  - Cada eje es un reparto distinto del mismo stock (las telas, las medidas,
 *    "con baúl / sin baúl"…), así que los ejes NO se suman entre sí: se escoge
 *    uno para ponerle precio a las unidades. Se escoge el eje que de verdad
 *    cambia el precio (alguna opción vale distinto del producto); entre varios,
 *    el que tiene más unidades marcadas, y si empatan, las opciones (medida)
 *    antes que la tela, porque son las que casi siempre llevan el precio.
 *  - En ese eje, cada unidad marcada vale el precio de su opción y las que nadie
 *    ha marcado valen el precio del producto. Lo marcado nunca cuenta más que
 *    el stock real: si el reparto se pasa, se recorta (como hace StockVariantes).
 *  - Precio de cada opción, igual que cobra Nueva orden: `precio_adicional` de
 *    una medida es el precio COMPLETO de esa versión (en 0 = el del producto,
 *    nunca base + adicional, ver 8be6205); `precio_variante` de una tela, si lo
 *    tiene, reemplaza al del producto.
 *  - Lo apartado (ya vendido, esperando entrega) se valora con la misma regla y
 *    se dice aparte: sigue en la tienda, pero esa plata ya es de una orden.
 *
 * Las promociones no se descuentan: esto es lo que vale el inventario a precio
 * de lista. Las combinaciones tela × medida tampoco se leen: cuelgan de una
 * medida que ya está en su eje.
 */
class ValorInventario
{
    /**
     * Una fila por producto y tienda con stock:
     *   producto_id, categoria, tienda_id, tienda_nombre, es_fabrica,
     *   unidades, apartadas, valor, valor_apartado, sin_marcar
     *
     * `sin_marcar`: unidades de un producto cuyo precio cambia por variante pero
     * que nadie ha marcado de qué medida/tela son; se cuentan al precio del
     * producto. Sirve para avisar que la cifra es aproximada ahí.
     *
     * @param  int|null     $tiendaId   null = todas las tiendas activas
     * @param  string|null  $categoria  null = todas las categorías
     * @param  int[]|null   $productoIds null = todos los productos
     */
    public static function filas(?int $tiendaId = null, ?string $categoria = null, ?array $productoIds = null): array
    {
        if ($productoIds !== null && empty($productoIds)) return [];

        $base = DB::table('inventario as inv')
            ->join('productos as p', 'p.id', '=', 'inv.producto_id')
            ->join('tiendas as t', 't.id', '=', 'inv.tienda_id')
            ->where('p.activo', true)
            ->where('t.activa', true)
            ->where(fn ($q) => $q->where('inv.cantidad_disponible', '>', 0)
                                 ->orWhere('inv.cantidad_reservada', '>', 0))
            ->when($tiendaId !== null, fn ($q) => $q->where('inv.tienda_id', $tiendaId))
            ->when($categoria !== null, fn ($q) => $q->where('p.categoria', $categoria))
            ->when($productoIds !== null, fn ($q) => $q->whereIn('inv.producto_id', $productoIds))
            ->select(
                'inv.producto_id', 'inv.tienda_id', 'p.categoria', 'p.precio_base',
                't.nombre as tienda_nombre', 't.es_fabrica',
                'inv.cantidad_disponible', 'inv.cantidad_reservada',
            )
            ->get();

        if ($base->isEmpty()) return [];

        $ids = $base->pluck('producto_id')->unique()->values()->all();

        // ejes["producto|tienda"]["eje"] = [ [disp, res, precio|null], … ]
        // precio null = "el del producto".
        $ejes = [];

        if (Esquema::tabla('inventario_variantes') && Esquema::tabla('producto_variantes')) {
            DB::table('inventario_variantes as iv')
                ->join('producto_variantes as pv', 'pv.id', '=', 'iv.variante_id')
                ->whereIn('pv.producto_id', $ids)
                ->when($tiendaId !== null, fn ($q) => $q->where('iv.tienda_id', $tiendaId))
                ->where(fn ($q) => $q->where('iv.cantidad_disponible', '>', 0)
                                     ->orWhere('iv.cantidad_reservada', '>', 0))
                ->select('pv.producto_id', 'iv.tienda_id', 'pv.precio_variante',
                         'iv.cantidad_disponible', 'iv.cantidad_reservada')
                ->get()
                ->each(function ($r) use (&$ejes) {
                    $precio = $r->precio_variante !== null && (float) $r->precio_variante > 0
                        ? (float) $r->precio_variante : null;
                    $ejes[$r->producto_id.'|'.$r->tienda_id]['tela'][] =
                        [(int) $r->cantidad_disponible, (int) $r->cantidad_reservada, $precio];
                });
        }

        if (Esquema::tabla('inventario_variante_configs') && Esquema::tabla('producto_variante_configs')) {
            DB::table('inventario_variante_configs as ivc')
                ->join('producto_variante_configs as pvc', 'pvc.id', '=', 'ivc.config_id')
                ->whereIn('pvc.producto_id', $ids)
                ->when($tiendaId !== null, fn ($q) => $q->where('ivc.tienda_id', $tiendaId))
                ->where(fn ($q) => $q->where('ivc.cantidad_disponible', '>', 0)
                                     ->orWhere('ivc.cantidad_reservada', '>', 0))
                ->select('pvc.producto_id', 'pvc.tipo_variante_id', 'ivc.tienda_id', 'pvc.precio_adicional',
                         'ivc.cantidad_disponible', 'ivc.cantidad_reservada')
                ->get()
                ->each(function ($r) use (&$ejes) {
                    $precio = (float) ($r->precio_adicional ?? 0) > 0 ? (float) $r->precio_adicional : null;
                    $ejes[$r->producto_id.'|'.$r->tienda_id]['tipo:'.$r->tipo_variante_id][] =
                        [(int) $r->cantidad_disponible, (int) $r->cantidad_reservada, $precio];
                });
        }

        return $base->map(function ($r) use ($ejes) {
            $precioBase = (float) $r->precio_base;
            $disp = max(0, (int) $r->cantidad_disponible);
            $res  = min(max(0, (int) $r->cantidad_reservada), $disp);

            [$valor, $valorRes, $sinMarcar] = self::valorar(
                $ejes[$r->producto_id.'|'.$r->tienda_id] ?? [], $precioBase, $disp, $res
            );

            return [
                'producto_id'    => (int) $r->producto_id,
                'categoria'      => $r->categoria,
                'tienda_id'      => (int) $r->tienda_id,
                'tienda_nombre'  => $r->tienda_nombre,
                'es_fabrica'     => (bool) $r->es_fabrica,
                'unidades'       => $disp,
                'apartadas'      => $res,
                'valor'          => $valor,
                'valor_apartado' => $valorRes,
                'sin_marcar'     => $sinMarcar,
            ];
        })->all();
    }

    /**
     * [valor, valor_apartado, sin_marcar] de un producto en una tienda.
     * Público para poder probar la regla sin base de datos.
     *
     * @param array $ejes ["eje" => [[disp, res, precio|null], …]]
     */
    public static function valorar(array $ejes, float $precioBase, int $disp, int $res): array
    {
        // Solo cuentan los ejes donde alguna opción vale distinto del producto:
        // repartir por tela sin precio propio no cambia cuánto vale nada.
        $candidatos = [];
        foreach ($ejes as $nombre => $filas) {
            $cambia = false;
            foreach ($filas as [, , $precio]) {
                if ($precio !== null && abs($precio - $precioBase) > 0.004) { $cambia = true; break; }
            }
            if (! $cambia) continue;
            $marcadas = array_sum(array_map(fn ($f) => max(0, $f[0]), $filas));
            $candidatos[] = [
                'filas'    => $filas,
                'marcadas' => $marcadas,
                // Las opciones (medidas) antes que la tela cuando empatan.
                'orden'    => str_starts_with($nombre, 'tipo:') ? 1 : 0,
            ];
        }

        if (! $candidatos) {
            return [$disp * $precioBase, $res * $precioBase, 0];
        }

        usort($candidatos, fn ($a, $b) => [$b['marcadas'], $b['orden']] <=> [$a['marcadas'], $a['orden']]);
        $filas = $candidatos[0]['filas'];

        $valor = self::repartir($filas, 0, $disp, $precioBase, $marcadasCaben);
        $valorRes = self::repartir($filas, 1, $res, $precioBase);

        return [$valor, $valorRes, $disp - $marcadasCaben];
    }

    /**
     * Valora $total unidades con las filas del eje (columna 0 = disponible,
     * 1 = apartado): cada marcada a su precio, sin pasarse del total, y el resto
     * al precio del producto. Se toman primero las opciones más baratas cuando
     * hay que recortar, para no inflar la cifra con un reparto que no cuadra.
     */
    private static function repartir(array $filas, int $col, int $total, float $precioBase, ?int &$usadas = null): float
    {
        usort($filas, fn ($a, $b) => ($a[2] ?? $precioBase) <=> ($b[2] ?? $precioBase));

        $queda = $total;
        $valor = 0.0;
        foreach ($filas as $f) {
            if ($queda <= 0) break;
            $n = min(max(0, (int) $f[$col]), $queda);
            $valor += $n * ($f[2] ?? $precioBase);
            $queda -= $n;
        }
        $usadas = $total - $queda;

        return $valor + $queda * $precioBase;
    }

    /**
     * Junta las filas: total, por tienda y por categoría.
     * Cada bloque trae unidades, apartadas, libres, valor, valor_apartado,
     * valor_libre y sin_marcar.
     */
    public static function resumen(array $filas): array
    {
        $vacio = fn () => ['unidades' => 0, 'apartadas' => 0, 'valor' => 0.0, 'valor_apartado' => 0.0, 'sin_marcar' => 0];
        $sumar = function (array &$a, array $f) {
            $a['unidades']       += $f['unidades'];
            $a['apartadas']      += $f['apartadas'];
            $a['valor']          += $f['valor'];
            $a['valor_apartado'] += $f['valor_apartado'];
            $a['sin_marcar']     += $f['sin_marcar'];
        };
        $cerrar = fn (array $a) => $a + [
            'libres'      => $a['unidades'] - $a['apartadas'],
            'valor_libre' => round($a['valor'] - $a['valor_apartado'], 2),
        ];

        $total = $vacio();
        $porTienda = [];
        $porCategoria = [];

        foreach ($filas as $f) {
            $sumar($total, $f);

            $t = $f['tienda_id'];
            $porTienda[$t] ??= ['tienda_id' => $t, 'tienda_nombre' => $f['tienda_nombre'], 'es_fabrica' => $f['es_fabrica']] + $vacio();
            $sumar($porTienda[$t], $f);

            $c = trim((string) $f['categoria']) !== '' ? $f['categoria'] : 'Sin categoría';
            $porCategoria[$c] ??= ['categoria' => $c, 'productos_con_stock' => 0] + $vacio();
            $sumar($porCategoria[$c], $f);
        }

        // Productos con stock por categoría: un producto en tres tiendas es uno.
        $vistos = [];
        foreach ($filas as $f) {
            $c = trim((string) $f['categoria']) !== '' ? $f['categoria'] : 'Sin categoría';
            if ($f['unidades'] > 0 && ! isset($vistos[$c][$f['producto_id']])) {
                $vistos[$c][$f['producto_id']] = true;
                $porCategoria[$c]['productos_con_stock']++;
            }
        }

        // Primero lo que más hay para vender (lo disponible, no lo apartado:
        // es la cifra grande de la pantalla), luego el total y las unidades.
        $porValor = fn ($a, $b) => [$b['valor'] - $b['valor_apartado'], $b['valor'], $b['unidades']]
                               <=> [$a['valor'] - $a['valor_apartado'], $a['valor'], $a['unidades']];

        $tiendas = array_map($cerrar, array_values($porTienda));
        usort($tiendas, $porValor);
        $categorias = array_map($cerrar, array_values($porCategoria));
        usort($categorias, $porValor);

        return [
            'total'         => $cerrar($total),
            'por_tienda'    => $tiendas,
            'por_categoria' => $categorias,
        ];
    }
}
