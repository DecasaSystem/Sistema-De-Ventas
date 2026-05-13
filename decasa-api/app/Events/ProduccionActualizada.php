<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProduccionActualizada implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $produccionId,
        public int    $ordenId,
        public string $estado,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('produccion')];
    }

    public function broadcastWith(): array
    {
        return [
            'produccion_id' => $this->produccionId,
            'orden_id'      => $this->ordenId,
            'estado'        => $this->estado,
        ];
    }

    public function broadcastAs(): string
    {
        return 'produccion.actualizada';
    }
}
