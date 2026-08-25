<?php

namespace App\Jobs;

use App\Models\Usuario;
use App\Services\NotificacionService;
use App\Services\RevisionEncargos;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Avisa cuando hay gente a la que ya le tocaba revista.
 *
 * Sin esto el módulo solo sirve si alguien se acuerda de abrirlo, y el punto
 * entero era no depender de que alguien se acuerde.
 *
 * Va un solo aviso con el total, no uno por persona: doce notificaciones
 * seguidas un lunes se leen como ruido y se descartan todas juntas.
 */
class AvisarRevisionesEncargos implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $vencidos = Usuario::where('lleva_encargos', true)
            ->where('activo', true)
            ->get()
            ->filter(fn (Usuario $u) => RevisionEncargos::estadoDe($u)['estado'] === 'vencida')
            ->values();

        if ($vencidos->isEmpty()) {
            Log::info('[DECASA] AvisarRevisionesEncargos: nadie con revista vencida.');
            return;
        }

        // A quien administra el módulo. No a los supervisores por serlo: el
        // permiso es justo lo que dice quién se encarga de esto.
        $encargados = Usuario::where('acceso_encargos', true)->where('activo', true)->get();

        if ($encargados->isEmpty()) {
            Log::warning('[DECASA] Hay revistas de encargos vencidas y nadie con acceso_encargos a quién avisarle.');
            return;
        }

        $cuantos = $vencidos->count();
        $nombres = $vencidos->take(3)->pluck('nombre')->implode(', ');
        $resto   = $cuantos - min($cuantos, 3);

        $mensaje = $cuantos === 1
            ? "A {$nombres} le toca revisión de lo que tiene a cargo."
            : "A {$cuantos} personas les toca revisión: {$nombres}" . ($resto > 0 ? " y {$resto} más." : '.');

        foreach ($encargados as $encargado) {
            NotificacionService::crear(
                'encargo_revision',
                'Toca revisar encargos',
                $mensaje,
                ['vencidas' => $cuantos],
                $encargado->id,
            );
        }

        Log::info('[DECASA] AvisarRevisionesEncargos: avisadas ' . $cuantos . ' revistas vencidas.');
    }
}
