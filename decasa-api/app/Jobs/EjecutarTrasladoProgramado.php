<?php

namespace App\Jobs;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Traslado;
use App\Services\AvisoTraslado;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EjecutarTrasladoProgramado implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $trasladoId) {}

    public function handle(): void
    {
        $traslado = Traslado::with([
            'supervisor:id,nombre',
            'tiendaOrigen:id,nombre',
            'tiendaDestino:id,nombre',
            'items',
        ])->find($this->trasladoId);

        if (! $traslado) {
            Log::warning("[TRASLADO_PROG] Traslado #{$this->trasladoId} no encontrado.");
            return;
        }

        if ($traslado->estado !== 'programado') {
            Log::info("[TRASLADO_PROG] Traslado #{$this->trasladoId} ya no está en estado programado ({$traslado->estado}), omitiendo.");
            return;
        }

        $nombreOrigen  = $traslado->tiendaOrigen->nombre;
        $nombreDestino = $traslado->tiendaDestino->nombre;

        try {
            DB::transaction(function () use ($traslado, $nombreOrigen, $nombreDestino) {
                foreach ($traslado->items as $item) {
                    // Se comprueba ahora, no cuando se programó: entre una
                    // cosa y la otra pudieron vender o apartar esas unidades.
                    $nombre = DB::table('productos')->where('id', $item->producto_id)->value('nombre')
                        ?? "Producto #{$item->producto_id}";
                    $motivo = \App\Services\MovimientoTraslado::porQueNoSePuede(
                        (int) $item->producto_id, (int) $traslado->tienda_origen_id, (int) $item->cantidad,
                        $item->variante_id, $item->combo_config_id, (string) $nombre, $nombreOrigen,
                    );
                    if ($motivo) throw new \RuntimeException($motivo);

                    \App\Services\MovimientoTraslado::mover(
                        (int) $item->producto_id,
                        (int) $traslado->tienda_origen_id, (int) $traslado->tienda_destino_id,
                        (int) $item->cantidad,
                        $item->variante_id, $item->combo_config_id,
                    );

                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $traslado->tienda_origen_id,
                        'tipo'        => 'traslado_salida',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Traslado #{$traslado->id} → $nombreDestino",
                        'usuario_id'  => $traslado->supervisor_id,
                    ]);
                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $traslado->tienda_destino_id,
                        'tipo'        => 'traslado_entrada',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Traslado #{$traslado->id} ← $nombreOrigen",
                        'usuario_id'  => $traslado->supervisor_id,
                    ]);
                }

                $traslado->update(['estado' => 'completado']);
            });

            Log::info("[TRASLADO_PROG] Traslado #{$traslado->id} ejecutado correctamente ({$nombreOrigen} → {$nombreDestino}).");

            // Un traslado programado cae solo, a la hora que sea: si no se avisa,
            // la tienda destino es la última en enterarse de lo que ya tiene.
            AvisoTraslado::llegada($traslado, $traslado->supervisor_id);

            NotificacionService::crear(
                'traslado_completado',
                'Traslado programado ejecutado',
                "El traslado #{$traslado->id} de $nombreOrigen → $nombreDestino se completó exitosamente.",
                ['traslado_id' => $traslado->id],
                $traslado->supervisor_id,
            );
        } catch (\RuntimeException $e) {
            Log::error("[TRASLADO_PROG] Error en traslado #{$traslado->id}: " . $e->getMessage());

            $traslado->update(['estado' => 'fallido']);

            NotificacionService::crear(
                'traslado_fallido',
                'Traslado programado fallido',
                "El traslado #{$traslado->id} de $nombreOrigen → $nombreDestino no pudo ejecutarse: " . $e->getMessage(),
                ['traslado_id' => $traslado->id],
                $traslado->supervisor_id,
            );
        }
    }
}
