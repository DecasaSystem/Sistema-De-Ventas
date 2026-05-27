<?php

namespace App\Events;

use App\Models\ConversacionWa;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaConversacionWa implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public ConversacionWa $conversacion) {}

    public function broadcastOn(): array
    {
        return [new Channel('redes')];
    }

    public function broadcastWith(): array
    {
        return $this->conversacion->load('tomadaPor:id,nombre')->toArray();
    }

    public function broadcastAs(): string
    {
        return 'conversacion.actualizada';
    }
}
