<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arreglo puntual de datos: la #4313 y la R-1102 son ventas de agosto que se
 * subieron en septiembre.
 *
 *   #4313   2 de septiembre  ->  30 de agosto
 *   R-1102  (septiembre)     ->  31 de agosto
 *
 * `created_at` es la fecha de la venta en todo el sistema —reportes de
 * ventas, meta, pool, bolsón de restauraciones—, así que con moverla basta
 * para que todo eso la cuente en agosto. Lo único que guarda una copia de la
 * fecha es la comisión (`mes_venta`, `fecha_venta`, `fecha_disponible`): se
 * borra y se vuelve a crear con la regla de siempre, como en la #1241.
 *
 * Los pagos del cliente NO se mueven: esa plata sí entró en septiembre.
 *
 * Salvaguardas, por orden (si no calza, no la toca y lo dice en el log):
 *  - tiene que haber exactamente una orden con ese número en la fecha
 *    esperada (el número normal se repite entre Armenia y Pereira);
 *  - si alguna de sus comisiones ya se pagó, no se mueve: esa plata ya salió.
 *
 * Se conserva la hora del día.
 */
return new class extends Migration
{
    private const TZ = 'America/Bogota';

    public function up(): void
    {
        // #4313: número normal, sin serie, hoy el 2 de septiembre.
        $this->mover(
            'Orden #4313',
            DB::table('ordenes')->where('numero_orden', 4313)->whereNull('serie'),
            fn (Carbon $f) => $f->format('Y-m-d') === '2026-09-02',
            '2026-08-30',
        );

        // R-1102: serie de restauración, subida en septiembre.
        $this->mover(
            'Orden R-1102',
            DB::table('ordenes')->where('serie', Orden::SERIE_RESTAURACION)->where('serie_numero', 1102),
            fn (Carbon $f) => $f->format('Y-m') === '2026-09',
            '2026-08-31',
        );
    }

    private function mover(string $nombre, $consulta, callable $esLaFechaVieja, string $diaNuevo): void
    {
        $candidatas = $consulta->get()->filter(
            fn ($o) => $esLaFechaVieja($this->enColombia($o->created_at))
        );

        if ($candidatas->count() !== 1) {
            echo "[{$nombre}] Se esperaba exactamente una en la fecha vieja; hay {$candidatas->count()}. Nada que hacer.\n";
            return;
        }

        $orden = $candidatas->first();

        if (DB::table('comisiones')->where('orden_id', $orden->id)->where('estado', 'pagada')->exists()) {
            echo "[{$nombre}] Tiene comisión ya pagada. No se mueve: revisar a mano.\n";
            return;
        }

        $antes = $this->enColombia($orden->created_at);
        $nueva = Carbon::parse($diaNuevo . ' ' . $antes->format('H:i:s'), self::TZ);
        $nuevaUtc = $nueva->copy()->setTimezone('UTC')->toDateTimeString();

        DB::transaction(function () use ($orden, $antes, $nueva, $nuevaUtc) {
            $cambios = ['created_at' => $nuevaUtc, 'updated_at' => now()];
            // Si se confirmó (borrador completado) también en septiembre, que
            // la lista de órdenes no la siga poniendo de primera.
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

        echo "[{$nombre}] Movida de {$antes->format('Y-m-d H:i')} a {$nueva->format('Y-m-d H:i')} (Col). "
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
