<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Poder poner un encargado distinto para las restauraciones y otro para los
 * muebles nuevos, en el mismo proceso.
 *
 * Los pasos de una restauración y los de un mueble nuevo son los mismos
 * —tapizar es tapizar—, pero no siempre los hace la misma gente: el taller
 * quiere que el ebanista lleve TODAS las restauraciones (telas, costuras, lo
 * que sea) y otra persona lleve lo nuevo. Hasta ahora `proceso_trabajadores`
 * solo decía "Mónica hace tapizado", sin poder decir de qué.
 *
 * Se resuelve con una línea de trabajo, no duplicando procesos:
 *
 *  - `proceso_trabajadores.linea` dice para cuál de las dos está esa persona
 *    en ese proceso. Por defecto 'ambas', que es exactamente como funciona
 *    hoy: nadie cambia de trabajo por esta migración.
 *  - `produccion_pasos.linea` dice de qué es la pieza. Se copia al armar el
 *    flujo desde `orden_items.es_restauracion` en vez de consultarse cada
 *    vez: así el filtro de "Mis pasos" es una comparación y el trabajo ya
 *    hecho no se reescribe si mañana alguien corrige la bandera del ítem.
 *
 * Y va detrás de un interruptor (`produccion_separa_restauraciones`). Apagado
 * —como queda— todo el mundo ve lo mismo que veía ayer. Encenderlo o apagarlo
 * es una casilla, porque esto puede cambiar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proceso_trabajadores', function (Blueprint $table) {
            $table->enum('linea', ['ambas', 'normal', 'restauracion'])
                ->default('ambas')
                ->after('usuario_id');
        });

        Schema::table('produccion_pasos', function (Blueprint $table) {
            $table->enum('linea', ['normal', 'restauracion'])
                ->default('normal')
                ->after('tipo_proceso');
        });

        // Lo que ya está en el taller queda con su línea de verdad, para que al
        // encender el interruptor los pasos en curso caigan donde deben y no
        // haya que esperar a que entren piezas nuevas.
        DB::statement("
            UPDATE produccion_pasos pp
            JOIN produccion p   ON p.id  = pp.produccion_id
            JOIN orden_items oi ON oi.id = p.orden_item_id
            SET pp.linea = 'restauracion'
            WHERE oi.es_restauracion = 1
        ");

        DB::table('configuracion')->insertOrIgnore([
            'clave' => 'produccion_separa_restauraciones',
            'valor' => '0',
        ]);
    }

    public function down(): void
    {
        Schema::table('proceso_trabajadores', fn (Blueprint $t) => $t->dropColumn('linea'));
        Schema::table('produccion_pasos', fn (Blueprint $t) => $t->dropColumn('linea'));
        DB::table('configuracion')->where('clave', 'produccion_separa_restauraciones')->delete();
    }
};
