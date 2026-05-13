<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SurtidoRechazado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $surtidoId,
        public int    $supervisorId,
        public string $tiendaNombre,
        public string $vendedorNombre,
        public ?string $motivo,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('supervisor.' . $this->supervisorId)];
    }

    public function broadcastWith(): array
    {
        return [
            'surtido_id'      => $this->surtidoId,
            'tienda_nombre'   => $this->tiendaNombre,
            'vendedor_nombre' => $this->vendedorNombre,
            'motivo'          => $this->motivo,
        ];
    }

    public function broadcastAs(): string
    {
        return 'surtido.rechazado';
    }
}
