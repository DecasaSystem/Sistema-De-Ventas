<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConsultaMensajeEnviado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly array $mensaje, public readonly int $consultaId) {}

    public function broadcastOn(): array
    {
        return [new Channel("consulta.{$this->consultaId}")];
    }

    public function broadcastAs(): string
    {
        return 'consulta.mensaje';
    }

    public function broadcastWith(): array
    {
        return $this->mensaje;
    }
}
