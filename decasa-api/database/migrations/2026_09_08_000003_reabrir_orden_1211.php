<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo puntual de datos: la orden 1211 se marcó ENTREGADA por error.
 * No ha salido y el cliente solo ha abonado $700.000.
 *
 * Confirmado con el usuario: el cliente solo dio $700.000 de anticipo. En el
 * sistema quedó un anticipo de $800.000 (08-jul) y un `saldo_final` de
 * $2.700.000 creado en la falsa entrega (05-ago, por "Conductor Prueba").
 * El total de la orden ($3.500.000) no cambia; la BASE MADRID vuelve
 * reservada para esta orden.
 *
 * Deshace lo que hace una entrega, igual que el botón "Revertir entrega":
 *   - devuelve al inventario los ítems de stock (disponible + reservada)
 *   - reabre la producción de los personalizados que quedó cerrada
 *   - borra el pago `saldo_final` de la falsa entrega y deja el anticipo en
 *     $700.000
 *   - quita el despacho_item / acta de esa entrega que no ocurrió
 *   - deja la orden en `listo_entrega` (lista, sin entregar)
 *
 * Es idempotente: si la orden ya no está entregada, no hace nada. Todo el
 * detalle queda en el log del deploy.
 */
return new class extends Migration
{
    private const ORDEN_ID = 1211;

    public function up(): void
    {
        $id = self::ORDEN_ID;

        $orden = DB::table('ordenes')->where('id', $id)->first();
        if (! $orden) {
            echo "[1211] La orden no existe. Nada que hacer.\n";
            return;
        }

        echo "[1211] Estado actual: {$orden->estado} · valor_total: {$orden->valor_total} · listo_entrega_at: " . ($orden->listo_entrega_at ?? 'null') . "\n";

        if (! in_array($orden->estado, ['entregado', 'devuelto'], true)) {
            echo "[1211] No está entregada/devuelta ({$orden->estado}). No se toca.\n";
            return;
        }

        $pagos = DB::table('pagos')->where('orden_id', $id)->orderBy('created_at')->get();
        echo "[1211] Pagos antes:\n";
        foreach ($pagos as $p) {
            echo "        #{$p->id}  {$p->tipo}  \$" . number_format((float) $p->monto, 0, ',', '.') . "  {$p->metodo}  ({$p->created_at})\n";
        }
        echo "[1211] Total abonado antes: \$" . number_format((float) $pagos->sum('monto'), 0, ',', '.') . "\n";

        DB::transaction(function () use ($id, $orden) {
            $items = DB::table('orden_items')->where('orden_id', $id)->get();

            // 1) Inventario de los ítems de stock: vuelve a estar disponible y
            //    reservado para esta orden (que sigue viva).
            foreach ($items as $it) {
                if ($it->es_personalizado || ! $it->producto_id) {
                    continue;
                }
                $cant     = (int) $it->cantidad;
                $origenId = $it->tienda_origen_id ?? $orden->tienda_id;

                DB::table('inventario')
                    ->where('producto_id', $it->producto_id)
                    ->where('tienda_id', $origenId)
                    ->update([
                        'cantidad_disponible' => DB::raw("cantidad_disponible + {$cant}"),
                        'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cant}"),
                    ]);

                if ($it->variante_id) {
                    DB::table('inventario_variantes')
                        ->where('variante_id', $it->variante_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible + {$cant}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cant}"),
                        ]);

                    if ($it->combo_config_id && DB::getSchemaBuilder()->hasTable('inventario_variante_combinaciones')) {
                        DB::table('inventario_variante_combinaciones')
                            ->where('variante_id', $it->variante_id)
                            ->where('config_id', $it->combo_config_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible + {$cant}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cant}"),
                            ]);
                    }
                }

                DB::table('inventario_movimientos')->insert([
                    'producto_id' => $it->producto_id,
                    'tienda_id'   => $origenId,
                    'tipo'        => 'entrada',
                    'cantidad'    => $cant,
                    'motivo'      => "Entrega revertida orden #{$id}: se marco entregada por error",
                    'usuario_id'  => null,
                    'created_at'  => now(),
                ]);

                echo "[1211] Inventario devuelto: producto {$it->producto_id} x{$cant} en tienda {$origenId}\n";
            }

            // 2) Producción de los personalizados: si la entrega la cerró, se
            //    reabre a "listo" (la pieza está hecha, solo no salió).
            $itemIds = $items->pluck('id')->all();
            if ($itemIds) {
                $reabiertas = DB::table('produccion')
                    ->whereIn('orden_item_id', $itemIds)
                    ->where('estado', 'entregado')
                    ->update(['estado' => 'listo', 'fecha_real' => null]);
                echo "[1211] Producciones reabiertas a 'listo': {$reabiertas}\n";
            }

            // Estado destino según la producción: si todo lo personalizado
            // está listo/entregado -> lista para entrega; si falta algo ->
            // sigue en producción. Sin personalizados -> lista para entrega.
            $estadosProd = $itemIds
                ? DB::table('produccion')->whereIn('orden_item_id', $itemIds)->pluck('estado')
                : collect();
            $estadoDestino = $estadosProd->isEmpty()
                ? 'listo_entrega'
                : ($estadosProd->every(fn ($e) => in_array($e, ['listo', 'entregado'], true))
                    ? 'listo_entrega'
                    : 'en_produccion');
            echo "[1211] Estado destino: {$estadoDestino} (producciones: " . $estadosProd->implode(',') . ")\n";

            // 3) Pagos: el abono real es $700.000. Se borra el `saldo_final`
            //    que creó la falsa entrega y se deja el anticipo en $700.000.
            $objetivo = 700000;

            foreach (DB::table('pagos')->where('orden_id', $id)->where('tipo', 'saldo_final')->get() as $p) {
                echo "[1211] Borrando pago de la falsa entrega: #{$p->id} {$p->tipo} \$" . number_format((float) $p->monto, 0, ',', '.') . " ({$p->metodo})\n";
            }
            DB::table('pagos')->where('orden_id', $id)->where('tipo', 'saldo_final')->delete();

            $restantes    = DB::table('pagos')->where('orden_id', $id)->get();
            $sumaRestante = (float) $restantes->sum('monto');

            if (abs($sumaRestante - $objetivo) < 1) {
                echo "[1211] El abono restante ya es \$" . number_format($objetivo, 0, ',', '.') . ". No se ajusta más.\n";
            } elseif ($restantes->count() === 1) {
                $unico = $restantes->first();
                echo "[1211] Ajustando pago #{$unico->id} ({$unico->tipo}) de \$" . number_format((float) $unico->monto, 0, ',', '.')
                   . " a \$" . number_format($objetivo, 0, ',', '.') . "\n";
                DB::table('pagos')->where('id', $unico->id)->update(['monto' => $objetivo]);
            } else {
                echo "[1211] ⚠ Quedan {$restantes->count()} pagos sumando \$" . number_format($sumaRestante, 0, ',', '.')
                   . " (esperado \$700.000). NO se ajustó ninguno — revisar a mano.\n";
            }

            // 4) Despacho/acta de la entrega que no ocurrió. Se registran las
            //    URLs en el log antes de borrar, por si hay que recuperarlas.
            $dItems = DB::table('despacho_items')->where('orden_id', $id)->get();
            foreach ($dItems as $di) {
                echo "[1211] despacho_item #{$di->id}: foto_producto=" . ($di->foto_producto ?? '-')
                   . " foto_pago=" . ($di->foto_pago ?? '-')
                   . " firma=" . ($di->firma_recibido_url ?? '-') . "\n";
                DB::table('devoluciones')->where('despacho_item_id', $di->id)->delete();
                $otros = DB::table('despacho_items')
                    ->where('despacho_id', $di->despacho_id)
                    ->where('id', '!=', $di->id)
                    ->count();
                DB::table('despacho_items')->where('id', $di->id)->delete();
                if ($otros === 0) {
                    DB::table('despachos')->where('id', $di->despacho_id)->delete();
                    echo "[1211] Despacho #{$di->despacho_id} y su item eliminados (acta descartada)\n";
                } else {
                    echo "[1211] despacho_item #{$di->id} eliminado (el despacho #{$di->despacho_id} tenía más órdenes, se conserva)\n";
                }
            }

            // 5) La orden vuelve a estar viva, sin entregar.
            DB::table('ordenes')->where('id', $id)->update([
                'estado'           => $estadoDestino,
                'listo_entrega_at' => $estadoDestino === 'listo_entrega'
                    ? ($orden->listo_entrega_at ?? now())
                    : null,
                'updated_at'       => now(),
            ]);

            // 6) Rastro en el historial de la orden. `usuario_id` es NOT NULL,
            //    así que se ancla a un supervisor (o cualquier usuario).
            $autorId = DB::table('usuarios')->where('rol', 'supervisor')->value('id')
                    ?? DB::table('usuarios')->value('id');
            if ($autorId && DB::getSchemaBuilder()->hasTable('orden_ediciones')) {
                DB::table('orden_ediciones')->insert([
                    'orden_id'   => $id,
                    'usuario_id' => $autorId,
                    'cambios'    => json_encode([[
                        'campo'   => 'estado',
                        'label'   => 'Entrega revertida (arreglo de datos)',
                        'antes'   => $orden->estado,
                        'despues' => $estadoDestino . ' - se habia marcado entregada por error, el cliente solo abono $700.000',
                    ]], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        });

        $despues = DB::table('ordenes')->where('id', $id)->first();
        $totalDespues = (float) DB::table('pagos')->where('orden_id', $id)->sum('monto');
        echo "[1211] LISTO. Estado: {$despues->estado} · Total abonado ahora: \$" . number_format($totalDespues, 0, ',', '.') . "\n";
    }

    public function down(): void
    {
        // Arreglo de datos puntual: no se revierte.
    }
};
