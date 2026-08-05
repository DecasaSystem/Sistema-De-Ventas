<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mantiene el desglose por variantes dentro del stock que realmente hay.
 *
 * De las unidades de un producto en una tienda, algunas están marcadas como
 * de tal tela o tal medida. Ese reparto no es un inventario aparte: reparte
 * parte del mismo total, así que lo marcado nunca puede pasarse del total.
 *
 * Se rompía al vender sin elegir variante: se descontaba el total del producto
 * y el reparto quedaba igual. Vendiendo la única unidad que había, la tienda
 * quedaba en cero con una unidad todavía marcada de un color — y el sistema
 * seguía ofreciendo ese color a los clientes.
 *
 * Aquí se recorta lo marcado hasta que quepa. No se bloquea la venta: si se
 * vendió sin decir el color y solo quedaba esa unidad, la que salió fue esa.
 * Recortar es reconocerlo; dejarlo como estaba es prometer algo que no hay.
 */
class StockVariantes
{
    /**
     * Cuadra el reparto de un producto en una tienda tras bajar su stock base.
     * Devuelve lo que hubo que recortar (vacío si todo cuadraba).
     */
    public static function cuadrar(int $productoId, int $tiendaId, string $motivo = ''): array
    {
        $inv = DB::table('inventario')
            ->where('producto_id', $productoId)
            ->where('tienda_id', $tiendaId)
            ->first();

        $base    = $inv ? (int) $inv->cantidad_disponible : 0;
        $baseRes = $inv ? (int) $inv->cantidad_reservada  : 0;

        // Los dos ejes son repartos distintos del MISMO stock, no se suman
        // entre sí: cada uno se compara por su cuenta contra el total.
        $ajustes = array_merge(
            self::recortarEje(
                'inventario_variantes',
                DB::table('inventario_variantes as iv')
                    ->join('producto_variantes as pv', 'pv.id', '=', 'iv.variante_id')
                    ->where('pv.producto_id', $productoId)
                    ->where('iv.tienda_id', $tiendaId)
                    ->orderByDesc('iv.cantidad_disponible')
                    ->select('iv.id', 'iv.cantidad_disponible', 'iv.cantidad_reservada')
                    ->get(),
                $base,
                $baseRes
            ),
            self::recortarEje(
                'inventario_variante_configs',
                DB::table('inventario_variante_configs as ivc')
                    ->join('producto_variante_configs as pvc', 'pvc.id', '=', 'ivc.config_id')
                    ->where('pvc.producto_id', $productoId)
                    ->where('ivc.tienda_id', $tiendaId)
                    ->orderByDesc('ivc.cantidad_disponible')
                    ->select('ivc.id', 'ivc.cantidad_disponible', 'ivc.cantidad_reservada')
                    ->get(),
                $base,
                $baseRes
            )
        );

        // Las combinaciones (tela × medida) cuelgan de su tela: si esa se
        // recortó, lo que colgaba de ella tiene que caber en lo que quedó.
        $ajustes = array_merge($ajustes, self::cuadrarCombinaciones($productoId, $tiendaId));

        if ($ajustes) {
            Log::warning('Reparto por variantes recortado para que quepa en el stock', [
                'producto_id' => $productoId,
                'tienda_id'   => $tiendaId,
                'motivo'      => $motivo,
                'ajustes'     => $ajustes,
            ]);
        }

        return $ajustes;
    }

    /**
     * Recorta las filas de un eje hasta que su suma quepa en el total.
     *
     * Se empieza por la que más tiene: al vender sin decir el color, lo más
     * probable es que saliera del montón más grande, y así se toca el menor
     * número de filas.
     */
    private static function recortarEje(string $tabla, $filas, int $base, int $baseRes): array
    {
        $ajustes = [];

        $exceso = $filas->sum(fn ($f) => (int) $f->cantidad_disponible) - $base;
        foreach ($filas as $fila) {
            if ($exceso <= 0) break;
            $tiene = (int) $fila->cantidad_disponible;
            if ($tiene <= 0) continue;

            $quita = min($tiene, $exceso);
            DB::table($tabla)->where('id', $fila->id)
                ->update(['cantidad_disponible' => $tiene - $quita]);

            $fila->cantidad_disponible = $tiene - $quita;
            $exceso -= $quita;
            $ajustes[] = ['tabla' => $tabla, 'id' => $fila->id, 'disponible_menos' => $quita];
        }

        // Lo reservado no puede pasarse ni de lo que queda disponible en su
        // propia fila ni de lo reservado del producto.
        $excesoRes = $filas->sum(fn ($f) => (int) $f->cantidad_reservada) - $baseRes;
        foreach ($filas as $fila) {
            $res  = (int) $fila->cantidad_reservada;
            $tope = (int) $fila->cantidad_disponible;
            $nuevo = min($res, $tope);
            if ($excesoRes > 0 && $nuevo > 0) {
                $quita  = min($nuevo, $excesoRes);
                $nuevo -= $quita;
                $excesoRes -= $quita;
            }
            if ($nuevo !== $res) {
                DB::table($tabla)->where('id', $fila->id)
                    ->update(['cantidad_reservada' => $nuevo]);
                $ajustes[] = ['tabla' => $tabla, 'id' => $fila->id, 'reservado_menos' => $res - $nuevo];
            }
        }

        return $ajustes;
    }

    /** Cada combinación tiene que caber en el stock de su tela. */
    private static function cuadrarCombinaciones(int $productoId, int $tiendaId): array
    {
        $ajustes  = [];
        $variantes = DB::table('producto_variantes')->where('producto_id', $productoId)->pluck('id');

        foreach ($variantes as $varianteId) {
            $tope = (int) DB::table('inventario_variantes')
                ->where('variante_id', $varianteId)->where('tienda_id', $tiendaId)
                ->value('cantidad_disponible');

            $filas = DB::table('inventario_variante_combinaciones')
                ->where('variante_id', $varianteId)->where('tienda_id', $tiendaId)
                ->orderByDesc('cantidad_disponible')
                ->select('id', 'cantidad_disponible', 'cantidad_reservada')
                ->get();
            if ($filas->isEmpty()) continue;

            $exceso = $filas->sum(fn ($f) => (int) $f->cantidad_disponible) - $tope;
            foreach ($filas as $fila) {
                if ($exceso <= 0) break;
                $tiene = (int) $fila->cantidad_disponible;
                if ($tiene <= 0) continue;

                $quita = min($tiene, $exceso);
                $queda = $tiene - $quita;
                DB::table('inventario_variante_combinaciones')->where('id', $fila->id)->update([
                    'cantidad_disponible' => $queda,
                    'cantidad_reservada'  => min((int) $fila->cantidad_reservada, $queda),
                ]);
                $exceso -= $quita;
                $ajustes[] = [
                    'tabla' => 'inventario_variante_combinaciones',
                    'id' => $fila->id, 'disponible_menos' => $quita,
                ];
            }
        }

        return $ajustes;
    }
}
