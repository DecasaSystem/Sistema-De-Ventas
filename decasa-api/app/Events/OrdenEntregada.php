<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenEntregada implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int     $ordenId,
        public string  $clienteNombre,
        public string  $conductorNombre,
        public ?string $numeroOrden = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('supervisor')];
    }

    public function broadcastWith(): array
    {
        return [
            'orden_id'         => $this->ordenId,
            'numero_orden'     => $this->numeroOrden,
            'cliente_nombre'   => $this->clienteNombre,
            'conductor_nombre' => $this->conductorNombre,
        ];
    }

    public function broadcastAs(): string
    {
        return 'orden.entregada';
    }
}
