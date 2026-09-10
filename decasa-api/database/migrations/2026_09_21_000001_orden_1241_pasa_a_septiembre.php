<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo puntual de datos: la orden #1241 quedó registrada el 31 de agosto y
 * tiene que ser del 1 de septiembre.
 *
 * `created_at` es la fecha de la venta en todo el sistema —reportes,
 * comisiones, taller—. Lo único que guarda una copia de esa fecha es la
 * comisión (`mes_venta`, `fecha_venta`, `fecha_disponible`), así que después
 * de mover la orden se borra y se vuelve a crear su comisión con la regla de
 * siempre (ComisionController::crearParaOrden, igual que hace la reasignación
 * en OrdenController::update).
 *
 * Salvaguardas:
 *  - Solo actúa si existe EXACTAMENTE una orden con numero_orden = 1241 y sin
 *    serie, y su fecha en hora de Colombia es el 2026-08-31. Si ya está en
 *    septiembre, o no calza, no toca nada y lo dice en el log del deploy.
 *  - Si alguna comisión de esa orden ya está 'lista' o 'pagada', aborta: esa
 *    plata ya se liquidó y moverla de mes es peligroso.
 *
 * Se conserva la hora del día: se corre la marca de tiempo exactamente 24 h,
 * así el 2026-08-31 23:30 (Col) pasa a 2026-09-01 23:30 (Col).
 */
return new class extends Migration
{
    private const NUMERO   = 1241;
    private const DIA_VIEJO = '2026-08-31';
    private const TZ        = 'America/Bogota';

    public function up(): void
    {
        $ordenes = DB::table('ordenes')
            ->where('numero_orden', self::NUMERO)
            ->whereNull('serie')
            ->get();

        if ($ordenes->count() !== 1) {
            echo '[#1241] Se esperaba exactamente una orden #' . self::NUMERO
                . ' sin serie; hay ' . $ordenes->count() . ". Nada que hacer.\n";
            return;
        }

        $orden      = $ordenes->first();
        $fechaCol   = Carbon::parse($orden->created_at, 'UTC')->setTimezone(self::TZ);

        if ($fechaCol->format('Y-m-d') !== self::DIA_VIEJO) {
            echo '[#1241] La orden #' . self::NUMERO . ' está en ' . $fechaCol->format('Y-m-d H:i')
                . ' (Col), no en ' . self::DIA_VIEJO . ". Nada que hacer.\n";
            return;
        }

        $comisiones = DB::table('comisiones')->where('orden_id', $orden->id)->get();
        $liquidada  = $comisiones->whereIn('estado', ['lista', 'pagada'])->isNotEmpty();

        if ($liquidada) {
            echo '[#1241] La orden #' . self::NUMERO . " tiene comisión lista o pagada. Abortar y revisar a mano.\n";
            return;
        }

        $nuevaCol = $fechaCol->copy()->addDay();               // 1 sep, misma hora local
        $nuevaUtc = $nuevaCol->copy()->setTimezone('UTC')->toDateTimeString();

        echo '[#1241] Orden #' . $orden->id . ' — antes: ' . $fechaCol->format('Y-m-d H:i')
            . ' (Col) · después: ' . $nuevaCol->format('Y-m-d H:i') . " (Col)\n";

        DB::transaction(function () use ($orden, $nuevaUtc, $comisiones) {
            DB::table('ordenes')->where('id', $orden->id)
                ->update(['created_at' => $nuevaUtc, 'updated_at' => now()]);

            // Rehacer la comisión con la fecha nueva (todas estaban pendientes).
            DB::table('comisiones')->where('orden_id', $orden->id)->delete();

            $modelo = Orden::find($orden->id);
            if ($modelo) {
                ComisionController::crearParaOrden($modelo);
            }

            $autorId = DB::table('usuarios')->where('rol', 'supervisor')->value('id')
                    ?? DB::table('usuarios')->value('id');

            if ($autorId && DB::getSchemaBuilder()->hasTable('orden_ediciones')) {
                DB::table('orden_ediciones')->insert([
                    'orden_id'   => $orden->id,
                    'usuario_id' => $autorId,
                    'cambios'    => json_encode([[
                        'campo'   => 'created_at',
                        'label'   => 'Fecha de la orden (arreglo de datos)',
                        'antes'   => Carbon::parse($orden->created_at, 'UTC')->setTimezone('America/Bogota')->toDateString(),
                        'despues' => '2026-09-01',
                    ]], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        });

        $rehechas = DB::table('comisiones')->where('orden_id', $orden->id)->count();
        echo '[#1241] LISTO. Comisiones rehechas: ' . $rehechas . "\n";
    }

    public function down(): void
    {
        // Arreglo de datos puntual: no se revierte automáticamente.
    }
};
