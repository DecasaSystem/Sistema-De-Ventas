<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
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

    /**
     * `$editado`: el autor lo corrigió o lo borró; la pantalla reemplaza el que
     * tiene con el mismo id en vez de agregarlo.
     */
    public function __construct(
        public readonly array $mensaje,
        public readonly int $ordenId,
        public readonly bool $editado = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("orden.{$this->ordenId}")];
    }

    public function broadcastAs(): string
    {
        return $this->editado ? 'orden.mensaje.editado' : 'orden.mensaje';
    }

    public function broadcastWith(): array
    {
        return $this->mensaje;
    }
}
