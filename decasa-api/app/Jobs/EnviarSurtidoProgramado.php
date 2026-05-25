<?php

namespace App\Jobs;

use App\Events\SurtidoEnviado;
use App\Models\Surtido;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnviarSurtidoProgramado implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $surtidoId) {}

    public function handle(): void
    {
        $surtido = Surtido::with([
            'supervisor:id,nombre',
            'tiendas.vendedorValidador:id,nombre',
            'tiendas.tienda:id,nombre',
            'tiendas.items.producto:id,nombre',
        ])->find($this->surtidoId);

        if (! $surtido) {
            Log::warning("[SURTIDO_PROG] Surtido #{$this->surtidoId} no encontrado.");
            return;
        }

        if ($surtido->estado !== 'programado') {
            Log::info("[SURTIDO_PROG] Surtido #{$this->surtidoId} ya no está en estado programado ({$surtido->estado}), omitiendo.");
            return;
        }

        $surtido->update(['estado' => 'enviado']);

        $supervisor = $surtido->supervisor;

        foreach ($surtido->tiendas as $st) {
            $cantidadProductos = $st->items->count();

            try {
                event(new SurtidoEnviado(
                    $surtido->id,
                    $st->vendedor_validador_id,
                    $supervisor->nombre,
                    $cantidadProductos,
                ));
            } catch (\Throwable $e) {
                Log::warning("[SURTIDO_PROG] Error disparando evento para tienda {$st->tienda_id}: " . $e->getMessage());
            }

            NotificacionService::crear(
                'surtido_enviado',
                'Surtido pendiente de validación',
                "{$supervisor->nombre} envió {$cantidadProductos} producto(s) a tu tienda. Valida la recepción.",
                ['surtido_id' => $surtido->id],
                $st->vendedor_validador_id,
            );
        }

        Log::info("[SURTIDO_PROG] Surtido #{$surtido->id} enviado correctamente a " . $surtido->tiendas->count() . " tienda(s).");
    }
}
