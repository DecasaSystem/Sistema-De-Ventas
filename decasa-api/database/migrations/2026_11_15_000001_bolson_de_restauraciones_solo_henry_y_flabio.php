<?php

use App\Http\Controllers\ComisionController;
use App\Support\ReglasComision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El bolsón de las restauraciones es SOLO de Henry y Flabio, desde siempre
 * (usuario, 2026-11-13: "las comisiones de cualquier restauración son solo
 * Henry y Flabio… que si pongo otro independiente no llegue y me dañe eso").
 *
 * Qué pasaba: quién cobra del bolsón se decide con quién está marcado como
 * independiente HOY, no con quién lo estaba ese mes. Ese día marcaron como
 * independientes a Margarita, Juan Peláez y Nicolás (supervisores que venden a
 * conocidos) y Listas los metió en el bolsón de agosto, septiembre y octubre,
 * que estaban sin pagar: unos $580.000 más a cada uno solo en noviembre. Se
 * desmarcaron; esto es para poder marcarlos sin que vuelva a pasar.
 *
 * Es lo mismo que poner en Bonificaciones → Reglas "Entra al bolsón de
 * restauraciones": para todos No, Henry Sí y Flabio Sí, desde el primer mes
 * con bonificaciones. La pantalla no deja ponerla hacia atrás en meses con
 * pagos; aquí sí se puede porque NO cambia ni un peso: hasta hoy los únicos
 * independientes marcados son Henry y Flabio, así que en cada mes el bolsón
 * queda igual que como se calculó (y lo pagado está congelado igual).
 *
 * Si no encuentra a los dos marcados como independientes, o si ya hay una
 * regla general de "entra al bolsón", no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comision_reglas')) return;

        if (DB::table('comision_reglas')->where('clave', 'entra_al_bolson')->where('alcance', 'general')->exists()) {
            echo "[Bolsón] Ya había una regla general de quién entra al bolsón: no se cambió nada.\n";
            return;
        }

        $ellos = DB::table('usuarios')->where('independiente', true)
            ->whereIn('nombre', ['Henry', 'Flabio'])->pluck('id', 'nombre');
        if ($ellos->count() !== 2) {
            echo "[Bolsón] No se encontró a Henry y a Flabio marcados como independientes: no se cambió nada.\n";
            return;
        }

        // Otro independiente marcado hoy cambiaría meses sin pagar: entonces
        // no es "no cambia ni un peso" y lo decide una persona en la pantalla.
        $otros = DB::table('usuarios')->where('independiente', true)
            ->whereNotIn('id', $ellos->values())->pluck('nombre');
        if ($otros->isNotEmpty()) {
            echo '[Bolsón] Hay otros independientes marcados (' . $otros->implode(', ') . '): no se cambió nada. '
                . "Desmárcalos, o pon la regla en Bonificaciones → Reglas.\n";
            return;
        }

        $desde = DB::table('comisiones')->min('mes_venta') ?? '2026-01';
        $nota  = 'Usuario, 2026-11-13: el bolsón de restauraciones es solo de Henry y Flabio, desde siempre.';
        $filas = [['general', null, false]];
        foreach ($ellos as $id) $filas[] = ['usuario', (int) $id, true];

        DB::transaction(function () use ($filas, $desde, $nota) {
            foreach ($filas as [$alcance, $id, $valor]) {
                DB::table('comision_reglas')->insert([
                    'clave' => 'entra_al_bolson', 'alcance' => $alcance, 'alcance_id' => $id,
                    'valor' => json_encode($valor), 'desde_mes' => $desde, 'nota' => $nota,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        ReglasComision::olvidarCache();

        ComisionController::anotar('regla_nueva', null, $desde, [
            'clave' => 'entra_al_bolson', 'alcance' => 'general', 'despues' => false,
            'nota' => 'Bolsón solo de Henry y Flabio, desde siempre (migración)',
        ]);

        echo "[Bolsón] Desde {$desde} solo entran Henry y Flabio. Ningún monto cambia.\n";
    }

    public function down(): void
    {
        // Es una decisión del negocio: no se deshace.
    }
};
