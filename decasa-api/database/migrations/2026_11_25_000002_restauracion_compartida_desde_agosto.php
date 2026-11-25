<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use App\Support\ReglasComision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La restauración de un independiente compartida con una persona (5% para
 * cada uno) rige desde AGOSTO de 2026, no desde noviembre (usuario,
 * 2026-11-25: "todo se tiene que recalcular agosto, septiembre y octubre").
 *
 * La migración 2026_11_25_000001 la puso desde noviembre; esta la corre a
 * agosto (o la crea desde agosto si aquella no hizo nada) y pone al día cada
 * restauración compartida de esos meses. Lo pagado NO se toca; lo que falta
 * sale en Listas como diferencia:
 *  - Henry y Flabio: el bolsón de ese mes ya pagado crece (entra la
 *    restauración entera) y Listas les muestra lo que falta del bolsón.
 *  - El compañero: un renglón con lo que le falta para su 5% de la
 *    restauración entera (si ya cobró la mitad, la otra mitad; si no tenía
 *    tienda y no cobró nada, el 5% entero).
 *
 * La pantalla de Reglas no deja poner una regla hacia atrás en meses con
 * pagos; aquí se hace porque lo pidió el usuario y nada pagado se mueve.
 */
return new class extends Migration
{
    private const DESDE = '2026-08';

    public function up(): void
    {
        if (! Schema::hasTable('comision_reglas')) return;

        $general = DB::table('comision_reglas')
            ->where('clave', 'restauracion_compartida_persona')->where('alcance', 'general')
            ->orderBy('desde_mes')->get();

        if ($general->contains(fn ($r) => $r->desde_mes <= self::DESDE)) {
            echo "[Restauración compartida] Ya regía desde agosto o antes: no se cambió la regla.\n";
        } elseif ($nov = $general->first(fn ($r) => json_decode($r->valor, true) === 'cinco_cada_uno')) {
            DB::table('comision_reglas')->where('id', $nov->id)->update([
                'desde_mes' => self::DESDE, 'updated_at' => now(),
                'nota' => 'Usuario, 2026-11-25: 5% para cada uno desde agosto (antes desde noviembre).',
            ]);
        } elseif ($general->isEmpty()) {
            DB::table('comision_reglas')->insert([
                'clave' => 'restauracion_compartida_persona', 'alcance' => 'general', 'alcance_id' => null,
                'valor' => json_encode('cinco_cada_uno'), 'desde_mes' => self::DESDE,
                'nota' => 'Usuario, 2026-11-25: restauración compartida con una persona, 5% para cada uno desde agosto.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            echo "[Restauración compartida] Alguien cambió la regla en Reglas: no se tocó. Revísala allá.\n";
            return;
        }
        ReglasComision::olvidarCache();
        ComisionController::olvidarRestauracionesCompartidas();

        // Las restauraciones compartidas de independientes desde agosto. Se
        // ponen al día una por una: el recálculo de cada mañana solo pasa por
        // órdenes con algo sin pagar, y aquí puede estar todo pagado.
        $independientes = DB::table('usuarios')->where('independiente', true)->pluck('id');
        $puestas = 0;
        Orden::with('pagos')
            ->where('es_compartida', true)->whereNotNull('covendedor_id')
            ->whereIn('vendedor_id', $independientes)
            ->where('created_at', '>=', '2026-08-01 05:00:00')
            ->whereNotIn('estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            ->get()
            ->filter(fn ($o) => ComisionController::restauracionCompartidaVaEntera($o))
            ->each(function ($o) use (&$puestas) {
                ComisionController::sincronizarValorOrden($o);
                $puestas++;
            });

        ComisionController::anotar('regla_cambiada', null, self::DESDE, [
            'clave' => 'restauracion_compartida_persona', 'alcance' => 'general', 'despues' => 'cinco_cada_uno',
            'nota' => "Desde agosto (migración); {$puestas} restauraciones puestas al día",
        ]);
        ComisionController::pedirRecalculo('Migración: restauración compartida desde agosto');

        echo "[Restauración compartida] Desde agosto; {$puestas} restauraciones compartidas puestas al día. Lo pagado no se movió.\n";
    }

    public function down(): void
    {
        // Es una decisión del negocio: no se deshace.
    }
};
