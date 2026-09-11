<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use App\Models\TiendaAsesor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo de datos: rehacer los repartos ya escritos con la regla nueva.
 *
 * Dos cosas cambiaron en el código y las filas que ya existían no se
 * enteran solas:
 *
 *  1. El 5% que deja un independiente se reparte entre el EQUIPO de la
 *     tienda (con sus reemplazos), no entre todos los usuarios con esa
 *     tienda como sede. En Decasa Norte, septiembre de 2026, ese abono
 *     estaba partido en cuatro —Paola de vacaciones incluida y Manuela por
 *     tener sede allá— cuando toca en tres: Marta, NN y Manuela cubriendo
 *     a Paola.
 *
 *  2. El divisor guardado en la meta se había quedado en 4 para Norte
 *     (alguien entró y salió del equipo el mismo día) y el asistente lo
 *     leía como "se reparte entre 4".
 *
 * Solo se tocan comisiones NO pagadas: lo que ya salió, salió. Es lo mismo
 * que hace el botón Recalcular en su primer paso, corrido una vez al
 * desplegar para no depender de que alguien lo pulse.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. El divisor de cada meta = cuánta gente hay en el equipo ese mes.
        $arregladas = 0;
        foreach (DB::table('metas_tienda')->get() as $meta) {
            $equipo = TiendaAsesor::where('tienda_id', $meta->tienda_id)
                ->where('mes', $meta->mes)->count();
            if ($equipo > 0 && (int) $meta->divisor_asesores !== $equipo) {
                DB::table('metas_tienda')->where('id', $meta->id)
                    ->update(['divisor_asesores' => $equipo, 'updated_at' => now()]);
                $arregladas++;
            }
        }
        echo "[repartos] divisores corregidos: $arregladas\n";

        // 2. Rehacer los repartos (abono de almacén, restauración de equipo)
        //    de toda orden con comisión sin pagar.
        $ordenIds = DB::table('comisiones')
            ->where('estado', '!=', 'pagada')
            ->whereNotNull('orden_id')
            ->distinct()->pluck('orden_id');

        $revisadas = 0;
        $cambiadas = 0;
        Orden::with('pagos')->whereIn('id', $ordenIds)->chunkById(100, function ($ordenes) use (&$revisadas, &$cambiadas) {
            foreach ($ordenes as $orden) {
                $cambiadas += ComisionController::sincronizarValorOrden($orden);
                $revisadas++;
            }
        });

        echo "[repartos] órdenes revisadas: $revisadas · valores puestos al día: $cambiadas\n";
    }

    public function down(): void
    {
        // Es un arreglo de datos: no hay estado anterior al que volver.
    }
};
