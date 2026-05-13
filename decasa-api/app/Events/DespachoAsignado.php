<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DespachoAsignado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $despachoId,
        public int    $conductorId,
        public int    $cantidadOrdenes,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('conductor.' . $this->conductorId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'despacho_id'      => $this->despachoId,
            'conductor_id'     => $this->conductorId,
            'cantidad_ordenes' => $this->cantidadOrdenes,
        ];
    }

    public function broadcastAs(): string
    {
        return 'despacho.asignado';
    }
}
