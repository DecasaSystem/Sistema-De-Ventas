<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras nació abierta a cualquiera con sesión, sin ninguna bandera de por
 * medio. Pasa a ser una bandera asignable por trabajador, como acceso_surtir
 * — activable para cualquier rol, no solo supervisor, para poder dársela o
 * quitársela persona por persona sin tocarle el rol.
 *
 * A nadie se le quita nada el día que esto sale: como el módulo era de
 * todos, se deja encendida para todo usuario activo que ya podía usarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_compras')->default(false)->after('acceso_nomina');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('acceso_compras')->default(false)->after('acceso_nomina');
        });

        DB::table('usuarios')->where('activo', true)->update(['acceso_compras' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('acceso_compras');
        });
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('acceso_compras');
        });
    }
};
