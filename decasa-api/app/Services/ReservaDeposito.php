<?php

namespace App\Services;

use App\Events\InventarioActualizado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Models\InventarioVarianteConfig;
use App\Models\Tienda;
use Illuminate\Support\Facades\DB;

/**
 * Deja unidades terminadas en la Reserva de Fábrica.
 *
 * Es la misma operación que hacen a mano `ReservaController::entrada` /
 * `entradaVariante` y `ProductoVarianteConfigController::entrada` /
 * `entradaCombinacion`, pero encadenada: sube el stock base y, si la pieza
 * lleva tela y/o medida, además el desglose que corresponda. Se llama cuando
 * una producción con `destino = 'reserva'` termina su último paso.
 */
class ReservaDeposito
{
    public static function fabrica(): Tienda
    {
        return Tienda::where('es_fabrica', true)->firstOrFail();
    }

    /**
     * @param int      $productoId    Producto de catálogo que se produjo.
     * @param int      $cantidad      Unidades terminadas.
     * @param int|null $varianteId    Variante de tela/color (o talla) si aplica.
     * @param int|null $comboConfigId Opción de variante personalizada (medida) si aplica.
     * @param string   $motivo        Texto para el movimiento de inventario.
     * @param int      $usuarioId     Quién autorizó el ingreso.
     */
    public static function depositar(
        int $productoId,
        int $cantidad,
        ?int $varianteId,
        ?int $comboConfigId,
        string $motivo,
        int $usuarioId,
    ): void {
        $fabrica = self::fabrica();

        DB::transaction(function () use ($productoId, $cantidad, $varianteId, $comboConfigId, $motivo, $usuarioId, $fabrica) {
            // 1. Stock base del producto en fábrica.
            $base = Inventario::firstOrCreate(
                ['producto_id' => $productoId, 'tienda_id' => $fabrica->id],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
            );
            $base->increment('cantidad_disponible', $cantidad);

            // 2. Desglose por variante de tela/color (o talla).
            if ($varianteId) {
                InventarioVariante::firstOrCreate(
                    ['variante_id' => $varianteId, 'tienda_id' => $fabrica->id],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
                )->increment('cantidad_disponible', $cantidad);
            }

            // 3. Desglose por opción de variante personalizada (medida).
            if ($comboConfigId) {
                InventarioVarianteConfig::firstOrCreate(
                    ['config_id' => $comboConfigId, 'tienda_id' => $fabrica->id],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0],
                )->increment('cantidad_disponible', $cantidad);
            }

            // 4. La combinación exacta tela × medida, cuando lleva las dos.
            if ($varianteId && $comboConfigId) {
                InventarioVarianteCombinacion::firstOrCreate(
                    ['variante_id' => $varianteId, 'config_id' => $comboConfigId, 'tienda_id' => $fabrica->id],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0],
                )->increment('cantidad_disponible', $cantidad);
            }

            // 5. Rastro en el historial de fábrica.
            InventarioMovimiento::create([
                'producto_id' => $productoId,
                'tienda_id'   => $fabrica->id,
                'variante_id' => $varianteId,
                'usuario_id'  => $usuarioId,
                'tipo'        => 'entrada',
                'cantidad'    => $cantidad,
                'motivo'      => $motivo,
            ]);
        });

        event(new InventarioActualizado($fabrica->id, $productoId, 'entrada'));
    }
}
