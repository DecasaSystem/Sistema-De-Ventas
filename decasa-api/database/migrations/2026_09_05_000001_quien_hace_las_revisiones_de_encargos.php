<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirar los encargos y pasar revista no son la misma tarea.
 *
 * Varios necesitan poder abrir el módulo y ver quién tiene qué —para saber a
 * quién pedirle el taladro, o qué se quedó sin devolver cuando alguien se va—.
 * Pero pasar contando y decidir qué se dañó, qué se perdió y qué se le
 * descuenta es de una persona en concreto, o de dos.
 *
 * Así que `acceso_encargos` se queda con lo primero, que es solo mirar, y
 * `revisa_encargos` marca a quien de verdad hace los checks. El aviso de "hoy
 * le toca a Fulano" va únicamente a esos: si le llegara a todo el que puede
 * mirar, nadie sabría que le hablan a él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('revisa_encargos')->default(false);
        });

        // Respaldo para no dejar el módulo sin nadie: quien ya tenía el acceso
        // lo tenía cuando ese permiso incluía revisar, así que conserva lo que
        // ya podía hacer. De aquí en adelante son dos cosas separadas.
        DB::table('usuarios')->where('acceso_encargos', true)->update(['revisa_encargos' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('revisa_encargos');
        });
    }
};
