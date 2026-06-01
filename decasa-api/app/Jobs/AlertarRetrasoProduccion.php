<?php

namespace App\Jobs;

use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlertarRetrasoProduccion implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $hoy = now()->toDateString();

        // ── 1. Marcar como RETRASADO lo que ya venció ────────────────────────
        $vencidos = Produccion::where('estado', 'en_proceso')
            ->whereDate('fecha_compromiso', '<', $hoy)
            ->with([
                'ordenItem.producto:id,nombre',
                'ordenItem.orden.cliente:id,nombre,telefono',
                'ordenItem.orden.vendedor:id,nombre',
                'ordenItem.orden.tienda:id,nombre',
            ])
            ->get();

        foreach ($vencidos as $prod) {
            $prod->update(['estado' => 'retrasado']);

            $orden   = $prod->ordenItem->orden;
            $cliente = $orden->cliente->nombre;
            $producto = $prod->ordenItem->producto->nombre;
            $dias    = now()->diffInDays($prod->fecha_compromiso);

            NotificacionService::crear(
                'retrasado',
                'Producción retrasada',
                "{$producto} — {$cliente} · {$dias} día(s) de retraso",
                ['produccion_id' => $prod->id, 'orden_id' => $orden->id],
            );
            NotificacionService::crear(
                'retrasado',
                'Tu pedido está atrasado',
                "{$producto} para {$cliente} · {$dias} día(s) de retraso",
                ['produccion_id' => $prod->id, 'orden_id' => $orden->id],
                $orden->vendedor_id,
            );

            Log::warning("[DECASA] Producción #{$prod->id} RETRASADA", [
                'producto'        => $producto,
                'cliente'         => $cliente,
                'telefono'        => $orden->cliente->telefono,
                'vendedor'        => $orden->vendedor->nombre,
                'tienda'          => $orden->tienda->nombre,
                'fecha_compromiso'=> $prod->fecha_compromiso,
                'dias_retraso'    => $dias,
                'orden_id'        => $orden->id,
            ]);
        }

        // ── 2. Alertar los que vencen en 1-3 días ───────────────────────────
        $porVencer = Produccion::where('estado', 'en_proceso')
            ->whereRaw('DATEDIFF(fecha_compromiso, CURDATE()) BETWEEN 1 AND 3')
            ->with([
                'ordenItem.producto:id,nombre',
                'ordenItem.orden.cliente:id,nombre,telefono',
                'ordenItem.orden.vendedor:id,nombre',
                'ordenItem.orden.tienda:id,nombre',
            ])
            ->get();

        foreach ($porVencer as $prod) {
            $diasRestantes = (int) now()->startOfDay()
                ->diffInDays(\Carbon\Carbon::parse($prod->fecha_compromiso)->startOfDay(), false);

            $orden   = $prod->ordenItem->orden;
            $cliente = $orden->cliente->nombre;

            NotificacionService::crear(
                'por_vencer',
                "Entrega próxima ({$diasRestantes}d)",
                "{$prod->ordenItem->producto->nombre} para {$cliente} — vence en {$diasRestantes} día(s)",
                ['produccion_id' => $prod->id, 'orden_id' => $orden->id, 'dias_restantes' => $diasRestantes],
            );
            NotificacionService::crear(
                'por_vencer',
                'Tu pedido vence pronto',
                "{$prod->ordenItem->producto->nombre} para {$cliente} — vence en {$diasRestantes} día(s)",
                ['produccion_id' => $prod->id, 'orden_id' => $orden->id, 'dias_restantes' => $diasRestantes],
                $orden->vendedor_id,
            );

            Log::info("[DECASA] Producción #{$prod->id} vence en {$diasRestantes} día(s)", [
                'producto'        => $prod->ordenItem->producto->nombre,
                'cliente'         => $cliente,
                'fecha_compromiso'=> $prod->fecha_compromiso,
                'orden_id'        => $orden->id,
            ]);
        }

        // ── 3. Alertar entregas que son HOY ──────────────────────────────────
        $entregasHoy = Produccion::whereIn('estado', ['pendiente', 'en_proceso', 'listo'])
            ->whereRaw('DATE(fecha_compromiso) = CURDATE()')
            ->with([
                'ordenItem.producto:id,nombre',
                'ordenItem.orden.cliente:id,nombre,telefono',
                'ordenItem.orden.vendedor:id,nombre',
                'ordenItem.orden.tienda:id,nombre',
            ])
            ->get();

        foreach ($entregasHoy as $prod) {
            $orden    = $prod->ordenItem->orden;
            $producto = $prod->ordenItem->producto->nombre;
            $cliente  = $orden->cliente->nombre;

            NotificacionService::crear(
                'entrega_hoy',
                '📦 Entrega hoy',
                "{$producto} para {$cliente} se tiene que entregar hoy",
                ['produccion_id' => $prod->id, 'orden_id' => $orden->id],
            );
            NotificacionService::crear(
                'entrega_hoy',
                '📦 Tu pedido se entrega hoy',
                "{$producto} para {$cliente} se tiene que entregar hoy",
                ['produccion_id' => $prod->id, 'orden_id' => $orden->id],
                $orden->vendedor_id,
            );

            Log::info("[DECASA] Producción #{$prod->id} — entrega HOY", [
                'producto'        => $producto,
                'cliente'         => $cliente,
                'telefono'        => $orden->cliente->telefono,
                'vendedor'        => $orden->vendedor->nombre,
                'tienda'          => $orden->tienda->nombre,
                'orden_id'        => $orden->id,
            ]);
        }

        // ── 4. Atrasados por fecha_entrega_prom (stock o listos para despacho) ─────
        // Cubre items sin producción (stock) y con producción en pendiente/listo/pendiente_despachador.
        // Los items 'en_proceso' ya son detectados por la sección 1.
        $itemsEntregaAtrasada = OrdenItem::whereDate('fecha_entrega_prom', '<', $hoy)
            ->whereHas('orden', fn($q) => $q->whereNotIn('estado', ['entregado', 'cancelado']))
            ->where(function ($q) {
                $q->whereDoesntHave('produccion')
                  ->orWhereHas('produccion', fn($q2) =>
                      $q2->whereIn('estado', ['pendiente', 'listo', 'pendiente_despachador'])
                  );
            })
            ->with([
                'producto:id,nombre',
                'orden.cliente:id,nombre,telefono',
                'orden.vendedor:id,nombre',
                'orden.tienda:id,nombre',
            ])
            ->get();

        foreach ($itemsEntregaAtrasada as $item) {
            $orden    = $item->orden;
            $producto = $item->producto?->nombre ?? $item->nombre_custom ?? 'Producto';
            $cliente  = $orden->cliente->nombre;
            $dias     = (int) now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($item->fecha_entrega_prom)->startOfDay()
            );

            NotificacionService::crear(
                'retrasado',
                'Entrega retrasada',
                "{$producto} — {$cliente} · {$dias} día(s) de retraso (sin entregar)",
                ['orden_item_id' => $item->id, 'orden_id' => $orden->id],
            );
            NotificacionService::crear(
                'retrasado',
                'Tu pedido no ha sido entregado',
                "{$producto} para {$cliente} · {$dias} día(s) de retraso",
                ['orden_item_id' => $item->id, 'orden_id' => $orden->id],
                $orden->vendedor_id,
            );

            Log::warning("[DECASA] Entrega ATRASADA (sin producción activa)", [
                'orden_item_id'     => $item->id,
                'producto'          => $producto,
                'cliente'           => $cliente,
                'telefono'          => $orden->cliente->telefono,
                'vendedor'          => $orden->vendedor->nombre,
                'tienda'            => $orden->tienda->nombre,
                'fecha_entrega_prom'=> $item->fecha_entrega_prom,
                'dias_retraso'      => $dias,
                'orden_id'          => $orden->id,
                'orden_estado'      => $orden->estado,
            ]);
        }

        Log::info('[DECASA] AlertarRetrasoProduccion completado', [
            'fecha'                  => $hoy,
            'marcados_retrasados'    => $vencidos->count(),
            'por_vencer'             => $porVencer->count(),
            'entregas_hoy'           => $entregasHoy->count(),
            'entrega_atrasada_stock' => $itemsEntregaAtrasada->count(),
        ]);
    }

    /**
     * Garantiza que solo haya un job pendiente a la vez en la queue.
     */
    public function uniqueId(): string
    {
        return 'alertar-retraso-produccion';
    }
}
