<?php

namespace App\Services;

use App\Models\Comision;
use App\Models\ConsultaCosto;
use App\Models\Orden;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\Usuario;

/**
 * Lo que tiene que pasar cada vez que una orden queda cancelada, venga por
 * donde venga.
 *
 * Antes vivía solo en `OrdenController::updateEstado` (el botón Cancelar). Las
 * otras puertas por las que una orden termina cancelada —devolverle la plata
 * de todo en Devoluciones, o el último reembolso de una garantía— hacían un
 * `update(['estado' => 'cancelado'])` a secas: la comisión pendiente quedaba
 * viva (y en el 5 % directo, en las restauraciones o en la parte del almacén
 * se podía pagar), el taller seguía con la pieza, la tela quedaba apartada y
 * nadie se enteraba (revisión del 2026-11-17).
 *
 * El inventario NO va aquí: lo suelta cada camino, porque cada uno sabe qué
 * tenía apartado (al cancelar desde la orden, lo de catálogo; en una
 * devolución, el producto ya se resolvió al decidirla).
 *
 * Dos pasos: `alCancelar` dentro de la transacción y `avisar` después, con la
 * orden ya guardada.
 */
class CancelacionDeOrden
{
    /**
     * Taller, tela, numeración y comisiones. Llamar dentro de la transacción,
     * antes de poner `estado = cancelado` (el aviso al taller tiene que ver
     * todavía las piezas vivas).
     *
     * @return array las órdenes corridas, si se pidió correr la numeración
     */
    public static function alCancelar(Orden $orden, Usuario $usuario, bool $correrNumeracion = false): array
    {
        // Avisar al taller ANTES de cancelarlas: después ya no se ve que
        // había trabajo vivo, y es justo lo que hay que decirle.
        AvisoProduccion::ordenCancelada($orden, $usuario);

        $produccionIds = Produccion::whereHas('ordenItem', fn ($q) => $q->where('orden_id', $orden->id))
            ->whereNotIn('estado', ['cancelado', 'entregado'])
            ->pluck('id');

        Produccion::whereIn('id', $produccionIds)->update(['estado' => 'cancelado']);

        // La tela que tenían apartada esas piezas vuelve a estar libre. Aquí
        // a mano: la cancelación de arriba va por consulta directa y no pasa
        // por el modelo de Produccion.
        ConsumoTelas::liberarOrden($orden);

        // Y sus pasos: si solo se cancela la pieza, el paso en curso seguía
        // saliéndole al ebanista en "Mis pasos" (lo mismo que ya hace
        // ProduccionController al cancelar desde el tablero).
        ProduccionPaso::whereIn('produccion_id', $produccionIds)
            ->whereIn('estado', ['pendiente', 'en_proceso'])
            ->update(['estado' => 'cancelado']);

        // Sin hueco: la orden suelta su número y las siguientes bajan uno. Va
        // aquí adentro para que, si algo falla, no quede el talonario corrido
        // por una orden que siguió viva.
        $corridas = $correrNumeracion ? NumeracionOrdenes::liberarYCorrer($orden, $usuario) : [];

        // Una venta cancelada no se comisiona. Quedaba la comisión viva y
        // pagable, y peor: su valor le seguía sumando a la meta de la tienda,
        // empujándola sobre el objetivo con una venta que no existió y
        // destrabando el pool de todo el equipo. Las ya pagadas no se tocan:
        // esa plata ya salió y borrar el registro sería perder el rastro.
        Comision::where('orden_id', $orden->id)
            ->where('estado', '!=', 'pagada')
            ->delete();

        return $corridas;
    }

    /**
     * Avisos de la cancelación: al vendedor, a quien cotizaba el costo y a
     * facturación si quedó plata recibida o una factura electrónica.
     *
     * @param string $referencia la que tenía la orden (si se corrió la
     *                           numeración, ya no es la misma)
     */
    public static function avisar(Orden $orden, Usuario $usuario, string $referencia): void
    {
        $orden->loadMissing('cliente');
        $cliente = $orden->cliente?->nombre ?? 'cliente';

        // Sin vendedor no se avisa: con null, NotificacionService se lo
        // reparte a todos los supervisores.
        if ($orden->vendedor_id) {
            NotificacionService::crear(
                'cancelado',
                'Tu orden fue cancelada',
                "Orden {$referencia} — {$cliente} fue cancelada",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        // Cerrar consultas de costo pendientes para evitar que queden huérfanas
        ConsultaCosto::where('orden_id', $orden->id)
            ->where('estado', 'pendiente')
            ->each(function ($consulta) use ($orden, $referencia, $cliente) {
                $consulta->update(['estado' => 'respondida', 'respondido_at' => now()]);
                // Notificar al cotizador que la orden fue cancelada
                NotificacionService::crear(
                    'cancelado',
                    'Cotización cancelada',
                    "La orden {$referencia} de {$cliente} fue cancelada. La consulta de costo ya no aplica.",
                    ['consulta_id' => $consulta->id, 'orden_id' => $orden->id],
                    $consulta->asignado_a_id,
                );
            });

        // Una orden cancelada que ya recibió plata deja un dinero sin destino:
        // facturación tiene que decidir si se devuelve o queda a favor.
        AvisoFacturacion::ordenCanceladaConPagos($orden, $usuario);
    }
}
