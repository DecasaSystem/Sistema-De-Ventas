<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SurtidoEnviado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $surtidoId,
        public int    $vendedorId,
        public string $supervisorNombre,
        public int    $cantidadProductos,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('vendedor.' . $this->vendedorId)];
    }

    public function broadcastWith(): array
    {
        return [
            'surtido_id'         => $this->surtidoId,
            'vendedor_id'        => $this->vendedorId,
            'supervisor_nombre'  => $this->supervisorNombre,
            'cantidad_productos' => $this->cantidadProductos,
        ];
    }

    public function broadcastAs(): string
    {
        return 'surtido.enviado';
    }
}
