<?php

namespace App\Jobs;

use App\Models\Orden;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa al asesor de las cotizaciones que están por vencer o que ya vencieron
 * sin respuesta del cliente.
 *
 * No marca nada en base de datos: "vencida" se calcula comparando la fecha, así
 * que la cotización ya se muestra vencida aunque este job no llegue a correr.
 * Esto es solo el recordatorio para que el vendedor haga seguimiento.
 */
class AvisarCotizacionesPorVencer implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Días de antelación con los que se avisa. */
    private const DIAS_AVISO = 3;

    public function handle(): void
    {
        $hoy   = now()->startOfDay();
        $techo = $hoy->copy()->addDays(self::DIAS_AVISO)->toDateString();

        // ── 1. Por vencer en los próximos días ───────────────────────────────
        $porVencer = Orden::cotizaciones()
            ->whereIn('cotizacion_estado', ['abierta', 'enviada'])
            ->whereNotNull('cotizacion_valida_hasta')
            ->whereDate('cotizacion_valida_hasta', '>=', $hoy->toDateString())
            ->whereDate('cotizacion_valida_hasta', '<=', $techo)
            ->with('cliente:id,nombre')
            ->get();

        foreach ($porVencer as $cot) {
            $dias = $hoy->diffInDays($cot->cotizacion_valida_hasta);

            NotificacionService::crear(
                'cotizacion_por_vencer',
                'Cotización por vencer',
                "{$cot->cotizacion_ref} — {$cot->contacto_display}: "
                    . ($dias === 0 ? 'vence hoy' : "vence en {$dias} día(s)"),
                ['orden_id' => $cot->id, 'cotizacion_id' => $cot->id],
                $cot->vendedor_id,
            );
        }

        // ── 2. Vencidas ayer: un solo aviso, no todos los días ───────────────
        $vencidas = Orden::cotizaciones()
            ->whereIn('cotizacion_estado', ['abierta', 'enviada'])
            ->whereDate('cotizacion_valida_hasta', '=', $hoy->copy()->subDay()->toDateString())
            ->with('cliente:id,nombre')
            ->get();

        foreach ($vencidas as $cot) {
            NotificacionService::crear(
                'cotizacion_vencida',
                'Cotización vencida',
                "{$cot->cotizacion_ref} — {$cot->contacto_display}: venció sin respuesta. "
                    . 'Puedes marcarla como perdida o renovar los precios.',
                ['orden_id' => $cot->id, 'cotizacion_id' => $cot->id],
                $cot->vendedor_id,
            );
        }
    }
}
