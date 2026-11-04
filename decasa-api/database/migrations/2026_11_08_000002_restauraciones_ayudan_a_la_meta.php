<?php

use App\Http\Controllers\ComisionController;
use App\Support\ReglasComision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enciende "Las restauraciones ayudan a llegar a la meta" para todas las
 * tiendas, desde siempre (dueño, 2026-11-04): si las ventas solas llegan a la
 * meta, las restauraciones van aparte sin quitarles el IVA; si las ventas no
 * llegan pero con las restauraciones sí, ese mes cuentan como ventas (pool con
 * el IVA quitado, no se pagan aparte); si ni con ellas se llega, no se paga
 * nada, tampoco las restauraciones.
 *
 * Es lo mismo que guardar la regla en Comisiones → Reglas, para todos, desde
 * el primer mes que tiene comisiones. Lo ya pagado no se mueve (el monto de una
 * comisión pagada queda congelado). Simulado en una copia de producción: solo
 * cambia Unicentro agosto (R-1100: Juan Esteban y Génesis, $3.750 cada uno → $0).
 *
 * Si la regla ya está puesta, no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comision_reglas')) return;

        $ya = DB::table('comision_reglas')->where('clave', 'restauracion_ayuda_meta')->where('alcance', 'general')->exists();
        if ($ya) {
            echo "[Restauraciones y meta] La regla ya estaba puesta: no se cambió nada.\n";
            return;
        }

        $desde = DB::table('comisiones')->min('mes_venta') ?? '2026-01';
        DB::table('comision_reglas')->insert([
            'clave' => 'restauracion_ayuda_meta', 'alcance' => 'general', 'alcance_id' => null,
            'valor' => json_encode(true), 'desde_mes' => $desde,
            'nota' => 'Decisión del dueño (2026-11-04): las restauraciones ayudan a llegar a la meta, desde siempre.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ReglasComision::olvidarCache();

        ComisionController::anotar('regla_nueva', null, $desde, [
            'clave' => 'restauracion_ayuda_meta', 'alcance' => 'general', 'despues' => true,
            'nota' => 'Las restauraciones ayudan a llegar a la meta, desde siempre (migración)',
        ]);
        ComisionController::pedirRecalculo('migración restauraciones ayudan a la meta');

        echo "[Restauraciones y meta] Regla puesta para todas las tiendas desde {$desde}.\n";
    }

    public function down(): void
    {
        // Es una decisión del negocio: no se deshace.
    }
};
