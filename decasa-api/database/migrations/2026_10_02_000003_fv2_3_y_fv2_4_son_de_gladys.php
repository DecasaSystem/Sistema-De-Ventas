<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Las órdenes FV2-3 y FV2-4 quedaron a nombre de Sebastián y son de Gladys.
 *
 * Los dos son del equipo de Vía El Edén, así que la comisión no cambia de
 * tienda ni de monto para nadie —el pool se reparte por días, no por
 * ventas—, pero la orden tiene que decir quién la vendió: de ahí salen las
 * estadísticas de cada uno y quién puede editarla.
 *
 * Se hace como lo haría un supervisor desde "Reasignar vendedor": se cambia
 * la orden, se pasa la fila de comisión al nuevo vendedor y queda anotado
 * en el historial. Lo ya pagado no se mueve: si la comisión de alguna ya
 * está pagada a Sebastián, se cambia la orden y se deja aviso en el log.
 *
 * Si Gladys o Sebastián no aparecen por nombre, o las órdenes no están a
 * nombre de él, no se toca nada y queda anotado.
 */
return new class extends Migration
{
    private const ORDENES = [3, 4];

    public function up(): void
    {
        $gladys    = $this->unico('Gladys%',    'Gladys');
        $sebastian = $this->unico('Sebasti_n%', 'Sebastián');
        if (! $gladys || ! $sebastian) return;

        $autorId = DB::table('usuarios')->where('rol', 'supervisor')->where('activo', true)->value('id')
                ?? DB::table('usuarios')->value('id');

        foreach (self::ORDENES as $numero) {
            $orden = Orden::where('serie', Orden::SERIE_FV2)->where('serie_numero', $numero)->first();

            if (! $orden) {
                Log::warning("FV2-{$numero}: no existe, no se toca.");
                continue;
            }
            if ((int) $orden->vendedor_id === $gladys) {
                continue; // ya está como debe
            }
            if ((int) $orden->vendedor_id !== $sebastian) {
                Log::warning("FV2-{$numero}: no está a nombre de Sebastián (vendedor #{$orden->vendedor_id}), no se toca.");
                continue;
            }

            DB::transaction(function () use ($orden, $gladys, $sebastian, $autorId, $numero) {
                DB::table('ordenes')->where('id', $orden->id)
                    ->update(['vendedor_id' => $gladys, 'updated_at' => now()]);

                $pagadas = DB::table('comisiones')->where('orden_id', $orden->id)
                    ->where('vendedor_id', $sebastian)->where('estado', 'pagada')->count();
                if ($pagadas) {
                    Log::warning("FV2-{$numero}: la comisión ya se le pagó a Sebastián; la orden pasa a Gladys pero la comisión pagada se queda como está.");
                }

                DB::table('comisiones')->where('orden_id', $orden->id)
                    ->where('vendedor_id', $sebastian)->where('estado', '!=', 'pagada')
                    ->update(['vendedor_id' => $gladys, 'updated_at' => now()]);

                // Por si la tienda de cada uno fuera distinta y la venta digital.
                ComisionController::sincronizarTienda($orden->fresh());

                if ($autorId) {
                    DB::table('orden_ediciones')->insert([
                        'orden_id'   => $orden->id,
                        'usuario_id' => $autorId,
                        'cambios'    => json_encode([[
                            'campo'   => 'vendedor_id',
                            'label'   => 'Vendedor (arreglo de datos)',
                            'antes'   => 'Sebastián',
                            'despues' => 'Gladys - la orden se registró a nombre equivocado',
                        ]], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                }
            });

            Log::info("FV2-{$numero}: pasó de Sebastián a Gladys.");
        }
    }

    private function unico(string $patron, string $que): ?int
    {
        $ids = DB::table('usuarios')->where('nombre', 'like', $patron)->where('activo', true)->pluck('id');

        if ($ids->count() !== 1) {
            Log::warning("FV2-3/FV2-4 a Gladys: {$que} no se encontró o hay más de una ({$ids->count()}); hazlo a mano desde la orden → Reasignar vendedor.");
            return null;
        }

        return (int) $ids->first();
    }

    public function down(): void
    {
        // Un arreglo de datos no se deshace por rollback.
    }
};
