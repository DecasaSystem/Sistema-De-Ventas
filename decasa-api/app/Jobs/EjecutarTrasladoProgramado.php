<?php

namespace App\Jobs;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Traslado;
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
                    $inv = Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $traslado->tienda_origen_id)
                        ->first();

                    if (! $inv) {
                        $nombre = DB::table('productos')->where('id', $item->producto_id)->value('nombre') ?? "Producto #{$item->producto_id}";
                        throw new \RuntimeException("\"$nombre\" no tiene inventario en $nombreOrigen.");
                    }

                    $libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
                    if ($libre < $item->cantidad) {
                        $nombre = DB::table('productos')->where('id', $item->producto_id)->value('nombre') ?? "Producto #{$item->producto_id}";
                        throw new \RuntimeException(
                            "Stock insuficiente para \"$nombre\" en $nombreOrigen: libre={$libre}, solicitado={$item->cantidad}."
                        );
                    }

                    Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $traslado->tienda_origen_id)
                        ->decrement('cantidad_disponible', $item->cantidad);

                    $invDest = Inventario::firstOrCreate(
                        ['producto_id' => $item->producto_id, 'tienda_id' => $traslado->tienda_destino_id],
                        ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
                    );
                    $invDest->increment('cantidad_disponible', $item->cantidad);

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
