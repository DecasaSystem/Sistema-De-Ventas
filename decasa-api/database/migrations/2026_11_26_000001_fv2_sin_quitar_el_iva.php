<?php

use App\Http\Controllers\ComisionController;
use App\Support\ReglasComision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ninguna FV2 se le quita el IVA (usuario, 2026-11-25): "todos, desde
 * siempre, los FV2 no se les quita el IVA, a ningún FV2, ni de vendedores ni
 * de nadie". Antes solo iban enteras las que se marcaban a mano (las de Henry
 * y Flabio, migración fv2_sin_descontar_iva); las de las tiendas (Norte, El
 * Edén) se dividían por 1,19 en el pool.
 *
 * Es lo mismo que poner en Bonificaciones → Reglas "Quitar el IVA a las FV2"
 * = No, para todos. Desde octubre de 2026: el usuario pidió que lo ya pagado
 * no se mueva ("solo lo no pagado") y en producción (revisado el 2026-11-25,
 * solo lectura) agosto y septiembre de Norte y El Edén ya están pagados,
 * octubre no (salvo el renglón de NN en Norte, que es de la empresa). Lo
 * pagado no se recalcula nunca; lo que falta pagar de octubre y noviembre
 * se pone al día con el recálculo que se deja pedido.
 *
 * Si ya hay una regla general de esto, no hace nada.
 */
return new class extends Migration
{
    private const DESDE = '2026-10';

    public function up(): void
    {
        if (! Schema::hasTable('comision_reglas')) return;

        if (DB::table('comision_reglas')->where('clave', 'iva_en_fv2')->where('alcance', 'general')->exists()) {
            echo "[FV2 sin IVA] Ya había una regla general: no se cambió nada.\n";
            return;
        }

        DB::table('comision_reglas')->insert([
            'clave' => 'iva_en_fv2', 'alcance' => 'general', 'alcance_id' => null,
            'valor' => json_encode(false), 'desde_mes' => self::DESDE,
            'nota' => 'Usuario, 2026-11-25: a ninguna FV2 se le quita el IVA.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ReglasComision::olvidarCache();

        ComisionController::anotar('regla_nueva', null, self::DESDE, [
            'clave' => 'iva_en_fv2', 'alcance' => 'general', 'despues' => false,
            'nota' => 'A ninguna FV2 se le quita el IVA (migración)',
        ]);
        ComisionController::pedirRecalculo('Migración: a ninguna FV2 se le quita el IVA');

        echo '[FV2 sin IVA] Desde ' . self::DESDE . " ninguna FV2 resta el IVA.\n";
    }

    public function down(): void
    {
        // Es una decisión del negocio: no se deshace.
    }
};
