<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El cliente firmó el anexo. Al vendedor que está armando la orden le aparece
 * sin recargar. Solo viaja el id y el estado: los datos se piden con sesión.
 */
class AnexoFirmado implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly int $anexoId) {}

    public function broadcastOn(): array
    {
        return [new Channel("anexo.{$this->anexoId}")];
    }

    public function broadcastAs(): string
    {
        return 'anexo.firmado';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->anexoId, 'estado' => 'firmado'];
    }
}
