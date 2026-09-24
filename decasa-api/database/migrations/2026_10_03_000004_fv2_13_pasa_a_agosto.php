<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arreglo puntual de datos: la FV2-13 (Jenny Rodríguez) es una venta de
 * agosto que se olvidó subir y se subió en septiembre.
 *
 *   FV2-13  (septiembre)  ->  8 de agosto
 *
 * Igual que la #4313 y la R-1102: `created_at` es la fecha de la venta en
 * todo el sistema —reportes de ventas, meta, pool—, así que con moverla basta
 * para que la cuente agosto. La comisión guarda copia de la fecha
 * (`mes_venta`, `fecha_venta`, `fecha_disponible`): se borra y se vuelve a
 * crear con la regla de siempre.
 *
 * Los pagos del cliente NO se mueven: esa plata entró cuando entró.
 *
 * Salvaguardas (si no calza, no la toca y lo dice en el log):
 *  - tiene que haber exactamente una FV2-13 subida en septiembre;
 *  - "Jenny" tiene que aparecer en el cliente o en quien la vendió;
 *  - si alguna de sus comisiones ya se pagó, no se mueve: esa plata ya salió.
 *
 * Se conserva la hora del día.
 */
return new class extends Migration
{
    private const TZ = 'America/Bogota';
    private const NOMBRE = 'Orden FV2-13';
    private const DIA_NUEVO = '2026-08-08';

    public function up(): void
    {
        $candidatas = DB::table('ordenes')
            ->where('serie', Orden::SERIE_FV2)
            ->where('serie_numero', 13)
            ->get()
            ->filter(fn ($o) => $this->enColombia($o->created_at)->format('Y-m') === '2026-09');

        if ($candidatas->count() !== 1) {
            echo '[' . self::NOMBRE . "] Se esperaba exactamente una subida en septiembre; hay {$candidatas->count()}. Nada que hacer.\n";
            return;
        }

        $orden = $candidatas->first();

        $cliente  = $orden->cliente_id  ? DB::table('clientes')->where('id', $orden->cliente_id)->value('nombre') : null;
        $vendedor = $orden->vendedor_id ? DB::table('usuarios')->where('id', $orden->vendedor_id)->value('nombre') : null;
        $esDeJenny = fn ($n) => $n && str_contains(mb_strtolower($n), 'jenny');

        if (! $esDeJenny($cliente) && ! $esDeJenny($vendedor)) {
            echo '[' . self::NOMBRE . "] No es de Jenny (cliente: {$cliente}, vendedor: {$vendedor}). No se toca.\n";
            return;
        }

        if (DB::table('comisiones')->where('orden_id', $orden->id)->where('estado', 'pagada')->exists()) {
            echo '[' . self::NOMBRE . "] Tiene comisión ya pagada. No se mueve: revisar a mano.\n";
            return;
        }

        $antes    = $this->enColombia($orden->created_at);
        $nueva    = Carbon::parse(self::DIA_NUEVO . ' ' . $antes->format('H:i:s'), self::TZ);
        $nuevaUtc = $nueva->copy()->setTimezone('UTC')->toDateTimeString();

        DB::transaction(function () use ($orden, $antes, $nueva, $nuevaUtc) {
            $cambios = ['created_at' => $nuevaUtc, 'updated_at' => now()];
            // Que la lista de órdenes tampoco la siga poniendo como de septiembre.
            if (Schema::hasColumn('ordenes', 'confirmada_en') && $orden->confirmada_en) {
                $cambios['confirmada_en'] = $nuevaUtc;
            }
            DB::table('ordenes')->where('id', $orden->id)->update($cambios);

            // La comisión se rehace con la fecha nueva: mes, fecha de venta y
            // fecha en que se puede cobrar.
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
