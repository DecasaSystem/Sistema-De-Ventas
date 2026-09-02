<?php

namespace App\Jobs;

use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mandar la notificación al teléfono, fuera de la petición.
 *
 * Enviar un push es hablar por HTTP con el servidor de Google o de Mozilla, y
 * eso tarda. Se hacía dentro del request, uno por destinatario: al cerrar un
 * paso del taller —que avisa a los del paso siguiente y al vendedor— la
 * pantalla se quedaba pensando varios segundos por unos avisos que al que
 * está esperando no le importan.
 *
 * La notificación se sigue guardando al momento, así que la campana no
 * cambia; lo único que se va a la cola es el aviso al teléfono.
 */
class EnviarPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public function __construct(
        private int $usuarioId,
        private string $titulo,
        private string $cuerpo,
        private array $datos = [],
        private ?string $tipo = null,
    ) {}

    public function handle(): void
    {
        PushService::enviarAUsuario($this->usuarioId, $this->titulo, $this->cuerpo, $this->datos, $this->tipo);
    }
}
