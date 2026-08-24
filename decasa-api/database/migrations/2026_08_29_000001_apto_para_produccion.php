<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién tiene que ver con el taller.
 *
 * Las dos listas de producción —a quién se pone de encargado de un proceso, y
 * a quién se anota como que hizo un paso— salían de "todos los trabajadores
 * activos". Eso mete ahí a conductores, cajeros y vendedores que nunca en su
 * vida van a tocar un mueble, y obliga a buscar entre cincuenta nombres al que
 * de verdad estaba en el taller.
 *
 * Con esta marca cada lista trae solo a quien corresponde:
 *  - encargado de un proceso -> apto Y que entre al programa (es quien ve el
 *    paso y lo confirma),
 *  - quién hizo el paso       -> apto, sin más: ahí es donde entra la fábrica,
 *    que es la que de verdad hace el trabajo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('apto_produccion')->default(false)->after('apto_comisiones');
        });

        // La gente de fábrica ES el taller: se marca sola.
        DB::table('usuarios')->where('no_usa_programa', true)->update(['apto_produccion' => true]);

        // Y quien ya venía metido en producción, para que nadie desaparezca de
        // las listas el día del cambio: los encargados de algún proceso...
        DB::table('usuarios')
            ->whereIn('id', DB::table('proceso_trabajadores')->select('usuario_id'))
            ->update(['apto_produccion' => true]);

        // ...y quien alguna vez quedó anotado como que hizo un paso.
        if (Schema::hasTable('paso_trabajadores')) {
            DB::table('usuarios')
                ->whereIn('id', DB::table('paso_trabajadores')->select('usuario_id'))
                ->update(['apto_produccion' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('apto_produccion'));
    }
};
