<?php

namespace App\Jobs;

use App\Models\Despacho;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlertarRutasAtrasadas implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $hoy = now()->toDateString();

        // Rutas asignadas al conductor cuya fecha de salida ya pasó y no han iniciado
        $atrasadas = Despacho::with(['camion:id,nombre', 'conductor:id,nombre'])
            ->where('estado', 'asignado')
            ->whereDate('fecha_despacho', '<', $hoy)
            ->get();

        if ($atrasadas->isEmpty()) {
            Log::info('[DECASA] AlertarRutasAtrasadas: sin rutas atrasadas para ' . $hoy);
            return;
        }

        $supervisores = Usuario::where('rol', 'supervisor')->where('activo', true)->get();

        foreach ($atrasadas as $despacho) {
            $nombreRuta   = $despacho->nombre_ruta ?? "Ruta #{$despacho->id}";
            $nombreCamion = $despacho->camion?->nombre ?? "Camión #{$despacho->camion_id}";
            $conductor    = $despacho->conductor?->nombre ?? 'Conductor';
            $fechaFmt     = \Carbon\Carbon::parse($despacho->fecha_despacho)->locale('es')->isoFormat('D [de] MMMM');
            $diasRetraso  = (int) now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($despacho->fecha_despacho)->startOfDay()
            );

            foreach ($supervisores as $sup) {
                NotificacionService::crear(
                    'ruta_atrasada',
                    'Ruta de despacho atrasada',
                    "{$nombreRuta} — {$nombreCamion} ({$conductor}) no salió el {$fechaFmt} · {$diasRetraso} día(s) de retraso",
                    ['despacho_id' => $despacho->id],
                    $sup->id,
                );
            }

            Log::warning('[DECASA] Ruta atrasada', [
                'despacho_id'    => $despacho->id,
                'nombre_ruta'    => $nombreRuta,
                'camion'         => $nombreCamion,
                'conductor'      => $conductor,
                'fecha_despacho' => $despacho->fecha_despacho,
                'dias_retraso'   => $diasRetraso,
            ]);
        }

        Log::info('[DECASA] AlertarRutasAtrasadas completado', [
            'fecha'    => $hoy,
            'atrasadas'=> $atrasadas->count(),
        ]);
    }

    public function uniqueId(): string
    {
        return 'alertar-rutas-atrasadas';
    }
}
