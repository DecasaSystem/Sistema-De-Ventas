<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Descuento que solo vale si el cliente paga en efectivo o transferencia.
 *
 * Si paga cualquier parte con tarjeta, el descuento se quita y el total vuelve
 * a su valor sin rebaja. Lo usan dos flujos distintos: el cobro desde la orden
 * (PagoController) y el cobro del conductor al entregar (DespachoController).
 */
class DescuentoCondicionadoService
{
    /**
     * Quita el descuento: sube el valor de la orden, ajusta la comisión al valor
     * nuevo, deja constancia en el historial y avisa a supervisión y facturación.
     *
     * No borra el descuento, lo marca: así queda el rastro de que existió y de
     * cuándo se perdió.
     */
    public static function quitar(Orden $orden, Usuario $usuario, string $metodo): void
    {
        if (! $orden->tieneDescuentoCondicionadoVivo()) {
            return;
        }

        $descuento  = (float) $orden->descuento_condicionado;
        $valorAntes = (float) $orden->valor_total;
        $valorNuevo = $valorAntes + $descuento;

        DB::transaction(function () use ($orden, $usuario, $metodo, $descuento, $valorAntes, $valorNuevo) {
            $orden->update([
                'valor_total' => $valorNuevo,
                'descuento_condicionado_revertido_at' => now(),
            ]);

            // La comisión sigue al valor real de la venta. Se recalcula con la
            // regla completa en vez de escribir el total a mano: esto corre
            // justo cuando el cliente paga con tarjeta, y hacerlo a mano
            // borraba el descuento del 5,5% del datáfono que acababa de
            // aplicarse, devolviéndole al vendedor una comisión inflada.
            \App\Http\Controllers\ComisionController::sincronizarValorOrden($orden->fresh());

            DB::table('orden_ediciones')->insert([
                'orden_id'   => $orden->id,
                'usuario_id' => $usuario->id,
                'cambios'    => json_encode([[
                    'campo'   => 'descuento_condicionado',
                    'label'   => "Descuento por pago en efectivo/transferencia retirado (pago con {$metodo})",
                    'antes'   => $valorAntes,
                    'despues' => $valorNuevo,
                ]], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });

        self::notificar($orden, $usuario, $metodo, $descuento, $valorNuevo);
    }

    /**
     * Texto para mostrarle al cliente cuando pregunta por qué cambió el precio.
     */
    public static function explicacion(Orden $orden): string
    {
        $descuento = '$' . number_format((float) $orden->descuento_condicionado, 0, ',', '.');
        $total     = '$' . number_format($orden->valorSinDescuentoCondicionado(), 0, ',', '.');

        return "Este pedido tenía {$descuento} de descuento por pago en efectivo o transferencia. "
             . "Al pagar con tarjeta el descuento no aplica, por eso el total es {$total}.";
    }

    private static function notificar(Orden $orden, Usuario $usuario, string $metodo, float $descuento, float $valorNuevo): void
    {
        $orden->loadMissing('cliente');

        $montoFmt = '$ ' . number_format($descuento, 0, ',', '.');
        $nuevoFmt = '$ ' . number_format($valorNuevo, 0, ',', '.');
        $cliente  = $orden->cliente?->nombre ?? 'cliente';

        $destinatarios = Usuario::where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->where(fn($q) => $q->where('rol', 'supervisor')->orWhere('facturacion', true))
            ->get();

        foreach ($destinatarios as $d) {
            NotificacionService::crear(
                tipo:      'descuento_revertido',
                titulo:    'Descuento retirado por pago con ' . $metodo,
                mensaje:   "Orden {$orden->referencia} de {$cliente}: se retiró {$montoFmt} de descuento "
                         . "({$usuario->nombre}). El total quedó en {$nuevoFmt}. "
                         . 'Saldo pendiente: $' . number_format($orden->saldoPendiente(), 0, ',', '.') . '.',
                datos:     ['orden_id' => $orden->id],
                usuarioId: $d->id,
                // Sube el total de una orden ya vendida: es plata, va marcado.
                urgente:   true,
            );
        }
    }
}
