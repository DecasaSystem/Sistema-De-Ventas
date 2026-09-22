<?php

namespace App\Services;

use App\Models\Inventario;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Support\StockVariantes;
use Illuminate\Support\Facades\DB;

/**
 * Mover stock de una tienda a otra: la cuenta, en un solo sitio.
 *
 * Un traslado se ejecuta por tres puertas distintas —el supervisor que lo
 * hace en el acto, el validador que acepta uno pendiente, y el programado que
 * salta a su hora— y cada una llevaba su propia copia de la cuenta. Copias
 * que se fueron separando: una miraba lo apartado antes de mover y otra no.
 * Aquí está una sola vez.
 *
 * Lo que aporta de nuevo es la TELA. Antes solo viajaba el producto: en el
 * origen el reparto por tela se recortaba a lo que quedara y en el destino
 * entraban unidades sin tela, que dejaban de poder venderse por su color.
 * Ahora lo que sale de una tela allá entra en la misma tela acá.
 */
class MovimientoTraslado
{
    /**
     * Lo que de verdad se puede mandar de un producto (o de una tela suya).
     *
     * Libre = lo que hay − lo que ya tiene dueño. Una unidad apartada por una
     * orden no se puede mandar a otra tienda: el cliente la está esperando
     * donde está.
     *
     * @return array{libre:int, hay:int, apartado:int}
     */
    public static function loQueSePuedeMandar(int $productoId, int $tiendaId, ?int $varianteId = null, ?int $configId = null): array
    {
        if ($varianteId && $configId) {
            $fila = InventarioVarianteCombinacion::where('variante_id', $varianteId)
                ->where('config_id', $configId)->where('tienda_id', $tiendaId)->first();
        } elseif ($varianteId) {
            $fila = InventarioVariante::where('variante_id', $varianteId)
                ->where('tienda_id', $tiendaId)->first();
        } else {
            $fila = Inventario::where('producto_id', $productoId)
                ->where('tienda_id', $tiendaId)->first();
        }

        $hay      = (int) ($fila->cantidad_disponible ?? 0);
        $apartado = (int) ($fila->cantidad_reservada  ?? 0);

        // Una tela nunca puede mandar más de lo que el producto tenga libre:
        // su reparto vive DENTRO del total, no al lado.
        if ($varianteId) {
            $base      = self::loQueSePuedeMandar($productoId, $tiendaId);
            $libreTela = max(0, $hay - $apartado);

            return [
                'hay'      => $hay,
                'apartado' => $apartado,
                'libre'    => min($libreTela, $base['libre']),
            ];
        }

        return ['hay' => $hay, 'apartado' => $apartado, 'libre' => max(0, $hay - $apartado)];
    }

    /**
     * Comprueba que se puede mandar, o dice por qué no.
     *
     * @return string|null  el motivo, o null si se puede
     */
    public static function porQueNoSePuede(
        int $productoId, int $tiendaId, int $cantidad,
        ?int $varianteId, ?int $configId, string $nombreProducto, string $nombreTienda
    ): ?string {
        $stock = self::loQueSePuedeMandar($productoId, $tiendaId, $varianteId, $configId);

        if ($stock['hay'] <= 0 && ! $varianteId) {
            return "\"{$nombreProducto}\" no tiene inventario en {$nombreTienda}.";
        }

        if ($stock['libre'] < $cantidad) {
            $de = $varianteId ? ' de esa tela/medida' : '';
            $porApartado = $stock['apartado'] > 0
                ? " Hay {$stock['hay']}, pero {$stock['apartado']} ya está apartado para una orden."
                : '';

            return "Stock insuficiente{$de} para \"{$nombreProducto}\" en {$nombreTienda}: "
                 . "libre={$stock['libre']}, solicitado={$cantidad}.{$porApartado}";
        }

        return null;
    }

    /**
     * Mueve las unidades del origen al destino.
     *
     * Solo toca `cantidad_disponible`: lo apartado se queda donde está porque
     * nunca se manda (ver `loQueSePuedeMandar`). Si el ítem trae tela, se
     * mueve también su fila —y la del combo tela×medida si la hay—, creándola
     * en el destino cuando es la primera vez que llega ese color allá.
     */
    public static function mover(
        int $productoId, int $origenId, int $destinoId, int $cantidad,
        ?int $varianteId = null, ?int $configId = null
    ): void {
        Inventario::where('producto_id', $productoId)->where('tienda_id', $origenId)
            ->decrement('cantidad_disponible', $cantidad);

        Inventario::firstOrCreate(
            ['producto_id' => $productoId, 'tienda_id' => $destinoId],
            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
        )->increment('cantidad_disponible', $cantidad);

        if ($varianteId) {
            InventarioVariante::where('variante_id', $varianteId)->where('tienda_id', $origenId)
                ->decrement('cantidad_disponible', $cantidad);

            InventarioVariante::firstOrCreate(
                ['variante_id' => $varianteId, 'tienda_id' => $destinoId],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
            )->increment('cantidad_disponible', $cantidad);

            if ($configId) {
                InventarioVarianteCombinacion::where('variante_id', $varianteId)
                    ->where('config_id', $configId)->where('tienda_id', $origenId)
                    ->decrement('cantidad_disponible', $cantidad);

                InventarioVarianteCombinacion::firstOrCreate(
                    ['variante_id' => $varianteId, 'config_id' => $configId, 'tienda_id' => $destinoId],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
                )->increment('cantidad_disponible', $cantidad);
            }
        }

        // El reparto de las dos tiendas tiene que seguir cabiendo en su total:
        // en el origen porque bajó, y en el destino porque subió y puede haber
        // quedado una tela por debajo de lo que ya tenía marcado.
        StockVariantes::cuadrar($productoId, $origenId, 'Traslado a otra tienda');
        StockVariantes::cuadrar($productoId, $destinoId, 'Traslado recibido');
    }

    /**
     * El desglose por tela/medida de un producto en una tienda, con cuánto se
     * puede mandar de cada uno. Es lo que la pantalla necesita para dejar
     * elegir qué se traslada en vez de mandar "dos sofás" a secas.
     */
    public static function telasDe(int $productoId, int $tiendaId): array
    {
        return DB::table('inventario_variantes as iv')
            ->join('producto_variantes as pv', 'pv.id', '=', 'iv.variante_id')
            ->where('pv.producto_id', $productoId)
            ->where('iv.tienda_id', $tiendaId)
            ->where('iv.cantidad_disponible', '>', 0)
            ->select('iv.variante_id', 'iv.cantidad_disponible', 'iv.cantidad_reservada',
                     'pv.marca', 'pv.marca_tela', 'pv.nombre_color', 'pv.medida')
            ->get()
            ->map(fn ($v) => [
                'variante_id' => (int) $v->variante_id,
                'nombre'      => trim(implode(' · ', array_filter([
                    $v->marca, $v->marca_tela, $v->nombre_color, $v->medida,
                ]))) ?: 'Sin nombre',
                'hay'         => (int) $v->cantidad_disponible,
                'apartado'    => (int) $v->cantidad_reservada,
                'libre'       => max(0, (int) $v->cantidad_disponible - (int) $v->cantidad_reservada),
            ])
            ->values()
            ->all();
    }
}
