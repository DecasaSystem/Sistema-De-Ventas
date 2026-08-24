<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ver el taller y mandar en el taller son dos cosas distintas.
 *
 * `acceso_produccion` daba las dos: quien podía mirar el tablero podía también
 * arrancar producciones, armar el flujo de pasos y cambiarle el estado a una
 * pieza. Eso deja sin término medio: o no ves nada, o puedes mover el taller
 * entero. Y lo normal es querer que un operario mire en qué va todo sin poder
 * tocarlo.
 *
 * A partir de aquí:
 *  - `acceso_produccion`   -> ver el tablero y en qué va cada pieza.
 *  - `gestiona_produccion` -> además arrancar procesos y mover producciones.
 *
 * Quien hoy tiene acceso conserva las dos, para que nadie amanezca sin poder
 * hacer lo que venía haciendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('gestiona_produccion')->default(false)->after('acceso_produccion');
        });

        DB::table('usuarios')->where('acceso_produccion', true)
            ->update(['gestiona_produccion' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('gestiona_produccion'));
    }
};
