<?php

namespace App\Events;

use App\Models\Notificacion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaNotificacion implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Notificacion $notificacion) {}

    public function broadcastOn(): array
    {
        $uid   = $this->notificacion->usuario_id;
        $canal = $uid ? "notificaciones.{$uid}" : 'notificaciones';
        return [new Channel($canal)];
    }

    public function broadcastWith(): array
    {
        return $this->notificacion->toArray();
    }

    public function broadcastAs(): string
    {
        return 'nueva.notificacion';
    }
}
