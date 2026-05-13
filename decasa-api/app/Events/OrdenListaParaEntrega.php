<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenListaParaEntrega implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $ordenId,
        public string $clienteNombre,
        public string $listoEntregaAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('despacho')];
    }

    public function broadcastWith(): array
    {
        return [
            'orden_id'        => $this->ordenId,
            'cliente_nombre'  => $this->clienteNombre,
            'listo_entrega_at' => $this->listoEntregaAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'orden.lista_entrega';
    }
}
