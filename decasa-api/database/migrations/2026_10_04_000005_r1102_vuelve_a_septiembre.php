<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arreglo puntual de datos: la R-1102 vuelve al 1 de septiembre.
 *
 *   R-1102  31 de agosto  ->  1 de septiembre
 *
 * La migración 2026_10_03_000003 la había pasado a agosto; se corrigió: es
 * de septiembre. Igual que aquella, `created_at` es la fecha de la venta en
 * todo el sistema, así que con moverla basta; la comisión guarda copia de la
 * fecha (`mes_venta`, `fecha_venta`, `fecha_disponible`) y se rehace con la
 * regla de siempre, ahora en septiembre.
 *
 * Los pagos del cliente no se mueven: esa plata entró cuando entró.
 *
 * Salvaguardas (si no calza, no la toca y lo dice en el log):
 *  - tiene que haber exactamente una R-1102 con fecha del 31 de agosto;
 *  - si alguna de sus comisiones ya se pagó, no se mueve: esa plata ya salió.
 *
 * Se conserva la hora del día.
 */
return new class extends Migration
{
    private const TZ        = 'America/Bogota';
    private const NOMBRE    = 'Orden R-1102';
    private const DIA_NUEVO = '2026-09-01';

    public function up(): void
    {
        $candidatas = DB::table('ordenes')
            ->where('serie', Orden::SERIE_RESTAURACION)
            ->where('serie_numero', 1102)
            ->get()
            ->filter(fn ($o) => $this->enColombia($o->created_at)->format('Y-m-d') === '2026-08-31');

        if ($candidatas->count() !== 1) {
            echo '[' . self::NOMBRE . "] Se esperaba exactamente una del 31 de agosto; hay {$candidatas->count()}. Nada que hacer.\n";
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
            // Que la lista de órdenes también la ponga en septiembre.
            if (Schema::hasColumn('ordenes', 'confirmada_en') && $orden->confirmada_en) {
                $cambios['confirmada_en'] = $nuevaUtc;
            }
            DB::table('ordenes')->where('id', $orden->id)->update($cambios);

            // La comisión se rehace con la fecha nueva: mes, fecha de venta y
            // fecha en que se puede cobrar (el 20 de octubre para septiembre).
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
                        'despues' => $nueva->toDateString() . ' - vuelve a septiembre',
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
