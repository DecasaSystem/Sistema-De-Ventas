<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenActualizada implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $ordenId,
        public int    $tiendaId,
        public string $estado,
        public string $clienteNombre,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('ordenes')];
    }

    public function broadcastWith(): array
    {
        return [
            'orden_id'       => $this->ordenId,
            'tienda_id'      => $this->tiendaId,
            'estado'         => $this->estado,
            'cliente_nombre' => $this->clienteNombre,
        ];
    }

    public function broadcastAs(): string
    {
        return 'orden.actualizada';
    }
}
