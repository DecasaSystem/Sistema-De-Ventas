<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Además de cubrir a alguien, uno se puede TRASLADAR de tienda.
 *
 * Los reemplazos ocupan el puesto del que no está: el pool se sigue partiendo
 * entre los mismos y lo que gana uno lo pierde el otro. Pero cuando alguien se
 * cambia de tienda no está cubriendo a nadie —Genesis se pasó a Unicentro el
 * 27 de agosto porque cerraron Circunvalar—, y ahí sí entra como una parte
 * más: desde ese día el equipo de esa tienda es uno más grande.
 *
 * Son dos cosas distintas y por eso se escriben distinto, en vez de dejarlo a
 * que `reemplaza_a_id` venga vacío: quien lo lea dentro de un año tiene que
 * poder saber cuál de las dos se registró, porque una diluye el pool del
 * equipo y la otra no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tienda_reemplazos', function (Blueprint $table) {
            $table->string('tipo', 12)->default('reemplazo')->after('tienda_id');
        });

        // Un traslado no cubre a nadie, así que la columna deja de ser
        // obligatoria. Las filas que ya existen son reemplazos y la conservan.
        Schema::table('tienda_reemplazos', function (Blueprint $table) {
            $table->dropForeign(['reemplaza_a_id']);
        });

        DB::statement('ALTER TABLE tienda_reemplazos MODIFY reemplaza_a_id BIGINT UNSIGNED NULL');

        Schema::table('tienda_reemplazos', function (Blueprint $table) {
            $table->foreign('reemplaza_a_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tienda_reemplazos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
