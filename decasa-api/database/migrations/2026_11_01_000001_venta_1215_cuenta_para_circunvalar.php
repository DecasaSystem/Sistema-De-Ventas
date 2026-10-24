<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La #1215 (Génesis, WhatsApp, 17 de julio) cuenta para Circunvalar.
 *
 * Una venta digital cuenta para la tienda que tenía el vendedor CUANDO vendió
 * (`tienda_vendedor_id`). La migración que estrenó esa columna
 * (2026_10_04_000002) llenó las digitales viejas con la tienda donde estaba la
 * comisión ese día, y la de la #1215 ya estaba en Unicentro porque antes se
 * miraba la tienda de HOY: Génesis pasó a Unicentro al cerrar Circunvalar (27
 * de agosto). En julio ella era de Circunvalar, y la orden también.
 *
 * Dueño, 2026-10-24: Circunvalar y Unicentro no se juntan. La venta se va a
 * Circunvalar, que no llegó a la meta: Génesis deja de cobrar ahí $134.454 y
 * esos $3,2 M dejan de sumarle al pool de Unicentro. Es la única digital de
 * Pereira con la tienda del vendedor distinta de la de la orden (revisado en
 * producción ese día).
 *
 * Solo corrige esa orden; si no la encuentra tal cual, no hace nada. Lo pagado
 * no se mueve (sincronizarTienda deja quietas las comisiones pagadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        $circunvalar = DB::table('tiendas')->where('nombre', 'Decasa Circunvalar')->value('id');
        if (! $circunvalar) return;

        $orden = Orden::where('numero_orden', 1215)->whereNull('serie')
            ->where('tienda_id', $circunvalar)
            ->whereNotNull('canal')->where('canal', '!=', 'fisica')
            ->first();
        if (! $orden || (int) $orden->tienda_vendedor_id === (int) $circunvalar) return;

        DB::table('ordenes')->where('id', $orden->id)->update(['tienda_vendedor_id' => $circunvalar]);

        $movidas = ComisionController::sincronizarTienda($orden->fresh());

        echo "[#1215] Cuenta para Circunvalar; {$movidas} comisión(es) movida(s).\n";
    }

    public function down(): void
    {
        // Es una corrección de datos: no se deshace.
    }
};
