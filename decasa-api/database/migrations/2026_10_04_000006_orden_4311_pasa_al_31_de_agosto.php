<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arreglo puntual de datos: la #4311 es una venta del 31 de agosto que se
 * olvidó subir ese día y quedó el 1 de septiembre, en el mes siguiente.
 *
 *   #4311  1 de septiembre  ->  31 de agosto
 *
 * Igual que la #4313 y la R-1102: `created_at` es la fecha de la venta en
 * todo el sistema —reportes, meta, pool—, así que con moverla basta; la
 * comisión guarda copia de la fecha (`mes_venta`, `fecha_venta`,
 * `fecha_disponible`) y se rehace con la regla de siempre, ahora en agosto.
 *
 * Los pagos del cliente no se mueven: esa plata entró cuando entró.
 *
 * Salvaguardas (si no calza, no la toca y lo dice en el log):
 *  - tiene que haber exactamente una #4311 del 1 de septiembre (el número
 *    normal se repite entre Armenia y Pereira: se busca también por fecha);
 *  - si alguna de sus comisiones ya se pagó, no se mueve: esa plata ya salió.
 *
 * Se conserva la hora del día.
 */
return new class extends Migration
{
    private const TZ        = 'America/Bogota';
    private const NOMBRE    = 'Orden #4311';
    private const DIA_NUEVO = '2026-08-31';

    public function up(): void
    {
        $candidatas = DB::table('ordenes')
            ->where('numero_orden', 4311)
            ->whereNull('serie')
            ->get()
            ->filter(fn ($o) => $this->enColombia($o->created_at)->format('Y-m-d') === '2026-09-01');

        if ($candidatas->count() !== 1) {
            echo '[' . self::NOMBRE . "] Se esperaba exactamente una del 1 de septiembre; hay {$candidatas->count()}. Nada que hacer.\n";
            return;
        }

        $orden = $candidatas->first();

        if (DB::table('comisiones')->where('orden_id', $orden->id)->where('estado', 'pagada')->exists()) {
            echo '[' . self::NOMBRE . "] Tiene comisión ya pagada. No se mueve: revisar a mano.\n";
            return;
        }

        $antes    = $this->enColombia($orden->created_at);
        $nueva    = Carbon::parse(self::DIA_NUEVO . ' ' . $antes->format('H:i:s'), self::TZ);
        $nuevaUtc = $nueva->copy()->setTimezone('UTC')->toDateTimeString();

        DB::transaction(function () use ($orden, $antes, $nueva, $nuevaUtc) {
            $cambios = ['created_at' => $nuevaUtc, 'updated_at' => now()];
            // Que la lista de órdenes también la ponga en agosto.
            if (Schema::hasColumn('ordenes', 'confirmada_en') && $orden->confirmada_en) {
                $cambios['confirmada_en'] = $nuevaUtc;
            }
            DB::table('ordenes')->where('id', $orden->id)->update($cambios);

            // La comisión se rehace con la fecha nueva: mes, fecha de venta y
            // fecha en que se puede cobrar (el 20 de septiembre para agosto).
            DB::table('comisiones')->where('orden_id', $orden->id)->delete();
            if ($modelo = Orden::find($orden->id)) {
                ComisionController::crearParaOrden($modelo);
            }

            $autorId = DB::table('usuarios')->where('rol', 'supervisor')->value('id')
                    ?? DB::table('usuarios')->value('id');

            if ($autorId && Schema::hasTable('orden_ediciones')) {
                DB::table('orden_ediciones')->insert([
                    'orden_id'   => $orden->id,
                    'usuario_id' => $autorId,
                    'cambios'    => json_encode([[
                        'campo'   => 'created_at',
                        'label'   => 'Fecha de la orden (arreglo de datos)',
                        'antes'   => $antes->toDateString(),
                        'despues' => $nueva->toDateString() . ' - venta de agosto subida en septiembre',
                    ]], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        });

        echo '[' . self::NOMBRE . "] Movida de {$antes->format('Y-m-d H:i')} a {$nueva->format('Y-m-d H:i')} (Col). "
            . 'Comisiones rehechas: ' . DB::table('comisiones')->where('orden_id', $orden->id)->count() . "\n";
    }

    private function enColombia($fecha): Carbon
    {
        return Carbon::parse($fecha, 'UTC')->setTimezone(self::TZ);
    }

    public function down(): void
    {
        // Arreglo de datos puntual: no se revierte automáticamente.
    }
};
