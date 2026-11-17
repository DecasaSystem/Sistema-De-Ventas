<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenMensaje;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Support\Esquema;
use Illuminate\Support\Facades\DB;

/**
 * Cancelar en Producción un producto que el cliente ya no quiere, y
 * descancelarlo si cambia de opinión (usuario, 2026-11-17).
 *
 * Antes cancelar una pieza solo cambiaba su estado: el producto seguía en la
 * orden como "por entregar" y cobrándose. Lo demás se podía entregar, pero la
 * orden quedaba en "entrega parcial" para siempre, nunca se cerraba y el
 * saldo seguía contando ese producto.
 *
 * Decisión del usuario: **deja de cobrarse**. Sale de la orden igual que lo
 * que se quita en una devolución (`devuelto_en`, `recalcularTotal`): el total
 * baja, la bonificación se rehace con lo que sí se vendió y lo pagado de más
 * queda a favor del cliente. Al cancelar se pregunta cada vez, porque a veces
 * se cancela solo la fabricación (estaba en la tienda) y el producto sigue en
 * la orden: en ese caso no se pasa por aquí.
 *
 * `cantidad_cancelada` guarda cuántas unidades salieron, para que
 * descancelar las devuelva a la orden y vuelvan a cobrarse.
 */
class CancelarProductoDeLaOrden
{
    public const MOTIVO = 'Cancelado en Producción: el cliente ya no lo quiere';

    /** El producto sale de la orden: no se entrega ni se cobra. */
    public static function quitar(OrdenItem $item, Usuario $usuario): void
    {
        DB::transaction(function () use ($item, $usuario) {
            $item  = OrdenItem::lockForUpdate()->findOrFail($item->id);
            $orden = $item->orden;

            $n = $item->pendienteEntregar();
            if ($n <= 0) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => 'Ese producto ya se le entregó al cliente: no se puede quitar de la orden desde aquí.',
                ], 422));
            }

            // Un mueble de la tienda que se mandó a retapizar estaba apartado:
            // se suelta para que se pueda vender otra vez.
            if (! $item->es_personalizado && $item->producto_id) {
                self::moverApartado($item, $orden, -$n, $usuario, "Se canceló en Producción, sale de la orden {$orden->referencia}");
            }

            if ($n >= (int) $item->cantidad) {
                $item->update([
                    'devuelto_en'        => RangoFechas::hoyTexto(),
                    'motivo_devolucion'  => self::MOTIVO,
                    'cantidad_cancelada' => $n,
                ]);
            } else {
                // Ya se le entregó una parte: se queda con eso y el resto sale.
                $item->update([
                    'cantidad'           => (int) $item->cantidad - $n,
                    'cantidad_cancelada' => $n,
                ]);
            }

            $valor = $n * (float) $item->precio_unitario;
            self::ponerAlDia($orden);

            $saldo = $orden->fresh()->saldoPendiente();
            self::anotar($orden, $usuario, "Se canceló \"" . self::nombre($item) . "\" ×{$n} en Producción: el cliente ya no lo quiere. "
                . 'Deja de cobrarse ($' . number_format($valor, 0, ',', '.') . ')'
                . ($saldo < -0.5 ? '. Le quedan $' . number_format(-$saldo, 0, ',', '.') . ' a favor' : '') . '.');
            self::avisar($orden, $usuario, 'Se quitó un producto de tu orden',
                "\"" . self::nombre($item) . "\" se canceló en Producción y ya no se cobra en la orden {$orden->referencia}.");
        });
    }

    /**
     * El cliente lo vuelve a querer: la pieza vuelve al taller como pendiente
     * y, si había salido de la orden, vuelve a ella y a cobrarse.
     */
    public static function descancelar(Produccion $produccion, Usuario $usuario): void
    {
        DB::transaction(function () use ($produccion, $usuario) {
            $produccion = Produccion::lockForUpdate()->findOrFail($produccion->id);
            if ($produccion->estado !== 'cancelado') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => 'Esa pieza no está cancelada.',
                ], 422));
            }

            $item  = $produccion->orden_item_id ? OrdenItem::lockForUpdate()->find($produccion->orden_item_id) : null;
            $orden = $item?->orden;
            if ($orden && $orden->estado === 'cancelado') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => 'La orden está cancelada: no se puede descancelar uno de sus productos.',
                ], 422));
            }

            // Vuelve al taller desde el principio: lo hecho antes queda en el
            // historial de pasos (cancelado), el taller le arma el flujo otra vez.
            $produccion->update(['estado' => 'pendiente']);

            if (! $item || ! $orden) return;

            $n = (int) ($item->cantidad_cancelada ?? 0);
            if ($n > 0) {
                if ($item->devuelto_en !== null) {
                    $item->update(['devuelto_en' => null, 'motivo_devolucion' => null, 'cantidad_cancelada' => 0]);
                } else {
                    $item->update(['cantidad' => (int) $item->cantidad + $n, 'cantidad_cancelada' => 0]);
                }

                if (! $item->es_personalizado && $item->producto_id) {
                    self::moverApartado($item, $orden, $n, $usuario, "Se descanceló en Producción, vuelve a la orden {$orden->referencia}");
                }
            }

            self::ponerAlDia($orden);

            $texto = "Se descanceló \"" . self::nombre($item) . "\": vuelve al taller"
                . ($n > 0 ? ' y a la orden ($' . number_format($n * (float) $item->precio_unitario, 0, ',', '.') . ' se vuelven a cobrar)' : '')
                . '.';
            self::anotar($orden, $usuario, $texto);
            self::avisar($orden, $usuario, 'Un producto de tu orden vuelve al taller', $texto);
        });
    }

    /** Total, bonificación y estado de la orden con lo que de verdad lleva. */
    private static function ponerAlDia(Orden $orden): void
    {
        $orden->refresh();
        $orden->recalcularTotal();
        ComisionController::sincronizarValorOrden($orden->fresh());

        $orden = $orden->fresh()->load('items.produccion');
        if (in_array($orden->estado, Orden::ESTADOS_NO_COMERCIALES, true) || $orden->estado === 'cancelado') return;

        // Sin nada que entregar, se queda abierta (con lo pagado a favor) para
        // que se le agregue algo o se resuelva la plata, como en devoluciones.
        if ($orden->items->filter->estaVivo()->isEmpty()) {
            $orden->update(['estado' => 'pendiente_anticipo']);
            return;
        }

        $estado  = $orden->estadoTrasEntrega();
        $cambios = ['estado' => $estado];
        if ($estado === 'listo_entrega' && ! $orden->listo_entrega_at && Esquema::columna('ordenes', 'listo_entrega_at')) {
            $cambios['listo_entrega_at'] = now();
        }
        $orden->update($cambios);
    }

    /** Suelta (n < 0) o vuelve a apartar (n > 0) el mueble de la tienda. */
    private static function moverApartado(OrdenItem $item, Orden $orden, int $n, Usuario $usuario, string $motivo): void
    {
        $tiendaId = $item->tienda_origen_id ?? $orden->tienda_id;
        $mover = function ($fila) use ($n) {
            if ($fila) $fila->update(['cantidad_reservada' => max((int) $fila->cantidad_reservada + $n, 0)]);
        };
        if ($item->variante_id) {
            $mover(InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)->first());
        }
        $mover(Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $tiendaId)->first());

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id, 'tienda_id' => $tiendaId,
            'tipo'        => $n < 0 ? 'liberacion' : 'reserva',
            'cantidad'    => abs($n), 'motivo' => $motivo, 'usuario_id' => $usuario->id,
        ]);
    }

    private static function nombre(OrdenItem $item): string
    {
        return $item->nombre_custom ?: ($item->producto?->nombre ?? 'Producto');
    }

    /** Queda en el chat de la orden, como las decisiones de devolución. */
    private static function anotar(Orden $orden, Usuario $usuario, string $texto): void
    {
        OrdenMensaje::create(['orden_id' => $orden->id, 'usuario_id' => $usuario->id, 'mensaje' => $texto]);
    }

    /** Al vendedor de la orden (si no la tiene nadie, no se reparte a todos). */
    private static function avisar(Orden $orden, Usuario $usuario, string $titulo, string $texto): void
    {
        if (! $orden->vendedor_id || (int) $orden->vendedor_id === (int) $usuario->id) return;

        NotificacionService::crear('orden_editada', $titulo, $texto, ['orden_id' => $orden->id], $orden->vendedor_id);
    }
}
