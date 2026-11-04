<?php

namespace App\Jobs;

use App\Models\AnexoGarantia;
use App\Services\OrdenDelAnexo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * El cliente firmó el enlace y el vendedor había escogido "crear la orden
 * sola apenas firme". Va en la cola y no en la petición del cliente: crear
 * una orden son muchas consultas (stock, telas, avisos, correo) y el cliente
 * no tiene por qué quedarse esperando con el teléfono en la mano.
 *
 * Un solo intento: si no se pudo (sin stock, cambió algo), el vendedor recibe
 * el motivo y la crea él desde Nueva orden; reintentar solo repetiría el aviso.
 */
class CrearOrdenFirmada implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $anexoId) {}

    public function handle(): void
    {
        $anexo = AnexoGarantia::find($this->anexoId);
        if (! $anexo || ! $anexo->crear_al_firmar || ! $anexo->tieneOrdenPendiente()) return;

        OrdenDelAnexo::crearYAvisar($anexo);
    }
}
