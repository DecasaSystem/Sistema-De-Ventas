<?php

namespace App\Jobs;

use App\Models\Cita;
use App\Services\NotificacionService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecordatoriosCitas implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $tz     = 'America/Bogota';
        $hoy    = Carbon::today($tz)->toDateString();
        $manana = Carbon::tomorrow($tz)->toDateString();

        $citasHoy    = Cita::whereIn('estado', ['pendiente', 'confirmada'])->where('fecha_cita', $hoy)->get();
        $citasManana = Cita::whereIn('estado', ['pendiente', 'confirmada'])->where('fecha_cita', $manana)->get();

        foreach ($citasHoy as $cita) {
            $cliente = $cita->nombre_cliente ?? 'un cliente';
            NotificacionService::crear(
                'cita_recordatorio',
                "📅 Cita HOY — {$cliente}",
                "Hoy a las {$cita->hora}" . ($cita->motivo ? ": {$cita->motivo}" : ''),
                ['cita_id' => $cita->id],
                $cita->asesor_id
            );
        }

        foreach ($citasManana as $cita) {
            $cliente = $cita->nombre_cliente ?? 'un cliente';
            NotificacionService::crear(
                'cita_recordatorio',
                "🔔 Cita mañana — {$cliente}",
                "Mañana a las {$cita->hora}" . ($cita->motivo ? ": {$cita->motivo}" : ''),
                ['cita_id' => $cita->id],
                $cita->asesor_id
            );
        }

        Log::info('[citas] Recordatorios enviados', [
            'hoy'    => $citasHoy->count(),
            'manana' => $citasManana->count(),
        ]);
    }
}
