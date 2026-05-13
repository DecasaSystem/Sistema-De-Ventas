<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventarioActualizado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int    $tiendaId,
        public int    $productoId,
        public string $tipo, // entrada | reserva | salida | liberacion
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('inventario')];
    }

    public function broadcastWith(): array
    {
        return [
            'tienda_id'   => $this->tiendaId,
            'producto_id' => $this->productoId,
            'tipo'        => $this->tipo,
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventario.actualizado';
    }
}
