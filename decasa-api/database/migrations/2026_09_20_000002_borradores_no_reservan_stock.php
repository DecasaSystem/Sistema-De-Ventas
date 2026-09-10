<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cambio de regla: un borrador YA NO reserva stock (a partir de este deploy
 * lo hace `store()`/`update()`; la reserva se hace toda junta al confirmarlo
 * en `completarBorrador`).
 *
 * Esta migración suelta lo que los borradores VIEJOS dejaron apartado: por
 * cada orden que sigue en estado `borrador`, devuelve al `cantidad_reservada`
 * lo que sus ítems de catálogo tenían tomado — en `inventario`,
 * `inventario_variantes` y `inventario_variante_combinaciones` — y deja un
 * movimiento de `liberacion` con el rastro.
 *
 * Es defensiva: no baja de 0, y solo toca ítems de stock (no personalizados,
 * no mueble único, no restauración). Todo el detalle queda en el log del
 * deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ordenes')) {
            echo "[borradores] tabla 'ordenes' no existe (entorno de test). Nada que hacer.\n";
            return;
        }

        $borradores = DB::table('ordenes')->where('estado', 'borrador')->get(['id', 'tienda_id']);

        if ($borradores->isEmpty()) {
            echo "[borradores] No hay borradores. Nada que soltar.\n";
            return;
        }

        echo "[borradores] {$borradores->count()} borradores por revisar.\n";

        $tieneVariantes  = DB::getSchemaBuilder()->hasTable('inventario_variantes');
        $tieneCombis     = DB::getSchemaBuilder()->hasTable('inventario_variante_combinaciones');
        $totalLiberado   = 0;

        foreach ($borradores as $orden) {
            $items = DB::table('orden_items')
                ->where('orden_id', $orden->id)
                ->where('es_personalizado', false)
                ->where('producto_unico', false)
                ->where('es_restauracion', false)
                ->whereNotNull('producto_id')
                ->get(['producto_id', 'variante_id', 'combo_config_id', 'tienda_origen_id', 'cantidad']);

            foreach ($items as $it) {
                $cant     = (int) $it->cantidad;
                if ($cant <= 0) continue;
                $origenId = $it->tienda_origen_id ?? $orden->tienda_id;

                DB::transaction(function () use ($it, $cant, $origenId, $orden, $tieneVariantes, $tieneCombis, &$totalLiberado) {
                    $inv = DB::table('inventario')
                        ->where('producto_id', $it->producto_id)
                        ->where('tienda_id', $origenId)
                        ->first();

                    if ($inv && $inv->cantidad_reservada > 0) {
                        $baja = min($cant, (int) $inv->cantidad_reservada);
                        DB::table('inventario')->where('id', $inv->id)
                            ->update(['cantidad_reservada' => DB::raw("cantidad_reservada - {$baja}")]);

                        DB::table('inventario_movimientos')->insert([
                            'producto_id' => $it->producto_id,
                            'tienda_id'   => $origenId,
                            'tipo'        => 'liberacion',
                            'cantidad'    => $baja,
                            'motivo'      => "Borrador #{$orden->id}: los borradores ya no reservan stock",
                            'usuario_id'  => null,
                            'created_at'  => now(),
                        ]);
                        $totalLiberado += $baja;
                        echo "[borradores] Orden {$orden->id}: -{$baja} reservado de producto {$it->producto_id} en tienda {$origenId}\n";
                    }

                    if ($it->variante_id && $tieneVariantes) {
                        DB::table('inventario_variantes')
                            ->where('variante_id', $it->variante_id)
                            ->where('tienda_id', $origenId)
                            ->where('cantidad_reservada', '>', 0)
                            ->update([
                                'cantidad_reservada' => DB::raw("GREATEST(cantidad_reservada - {$cant}, 0)"),
                            ]);

                        if ($it->combo_config_id && $tieneCombis) {
                            DB::table('inventario_variante_combinaciones')
                                ->where('variante_id', $it->variante_id)
                                ->where('config_id', $it->combo_config_id)
                                ->where('tienda_id', $origenId)
                                ->where('cantidad_reservada', '>', 0)
                                ->update([
                                    'cantidad_reservada' => DB::raw("GREATEST(cantidad_reservada - {$cant}, 0)"),
                                ]);
                        }
                    }
                });
            }
        }

        echo "[borradores] LISTO. Total de unidades liberadas: {$totalLiberado}\n";
    }

    public function down(): void
    {
        // Arreglo de datos puntual + cambio de regla: no se revierte.
    }
};
