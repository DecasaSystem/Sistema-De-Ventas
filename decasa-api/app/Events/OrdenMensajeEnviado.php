<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje nuevo en el chat de una orden.
 *
 * Va por el canal de la orden para que a quien la tenga abierta le aparezca sin
 * recargar. ShouldBroadcastNow porque un chat con retraso de cola no es un chat.
 */
class OrdenMensajeEnviado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly array $mensaje, public readonly int $ordenId) {}

    public function broadcastOn(): array
    {
        return [new Channel("orden.{$this->ordenId}")];
    }

    public function broadcastAs(): string
    {
        return 'orden.mensaje';
    }

    public function broadcastWith(): array
    {
        return $this->mensaje;
    }
}
