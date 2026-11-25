<?php

use App\Http\Controllers\ComisionController;
use App\Support\ReglasComision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restauración de un independiente compartida con otra persona: 5% para cada
 * uno (usuario, 2026-11-24). "Se hace una restauración de 2 millones y la sube
 * Henry: le toca a Henry 5%, a Flabio 5%, y si la comparte con un vendedor sin
 * tienda, ese vendedor también se lleva el 5%".
 *
 * Antes se partía como una venta compartida: al bolsón entraba la mitad (Henry
 * y Flabio cobraban el 5% de $1.000.000) y el compañero cobraba sobre la otra
 * mitad; un vendedor o un supervisor sin tienda no cobraba nada. Con una
 * tienda ya era así (entera al bolsón y la tienda su 5%), por eso se igualó.
 *
 * Es lo mismo que poner en Bonificaciones → Reglas "Restauración de un
 * independiente compartida con otra persona" = "Entera al bolsón y el
 * compañero cobra aparte", para todos, desde noviembre de 2026 (el mes que se
 * está vendiendo). Lo vendido antes queda como se calculó; si se quiere desde
 * antes, se cambia la fecha en Reglas (solo deja en meses sin pagar).
 *
 * Si ya hay una regla general de esto, no hace nada. Deja pedido el recálculo
 * para que las restauraciones compartidas de noviembre queden al día.
 */
return new class extends Migration
{
    private const DESDE = '2026-11';

    public function up(): void
    {
        if (! Schema::hasTable('comision_reglas')) return;

        if (DB::table('comision_reglas')->where('clave', 'restauracion_compartida_persona')->where('alcance', 'general')->exists()) {
            echo "[Restauración compartida] Ya había una regla general: no se cambió nada.\n";
            return;
        }

        DB::table('comision_reglas')->insert([
            'clave' => 'restauracion_compartida_persona', 'alcance' => 'general', 'alcance_id' => null,
            'valor' => json_encode('cinco_cada_uno'), 'desde_mes' => self::DESDE,
            'nota' => 'Usuario, 2026-11-24: restauración compartida con una persona, 5% para cada uno.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ReglasComision::olvidarCache();

        ComisionController::anotar('regla_nueva', null, self::DESDE, [
            'clave' => 'restauracion_compartida_persona', 'alcance' => 'general', 'despues' => 'cinco_cada_uno',
            'nota' => 'Restauración compartida con una persona: 5% para cada uno (migración)',
        ]);
        ComisionController::pedirRecalculo('Migración: restauración compartida con una persona');

        echo '[Restauración compartida] Desde ' . self::DESDE . " va entera al bolsón y el compañero cobra su 5%.\n";
    }

    public function down(): void
    {
        // Es una decisión del negocio: no se deshace.
    }
};
