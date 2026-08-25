<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué otra cuenta se alterna, guardado en la cuenta y no en el aparato.
 *
 * El doble perfil vivía entero en el navegador. Uno lo activaba en el PC y en
 * el celular no existía: había que volver a configurarlo aparato por aparato,
 * y parecía que no se hubiera guardado.
 *
 * Lo que NO puede viajar es la sesión del otro perfil —eso es una contraseña
 * ajena, y sincronizarla dejaría a cualquier aparato dentro de esa cuenta sin
 * que nadie la escriba—. Lo que sí viaja es CUÁL es: así el celular ya sabe
 * con quién alternas y solo pide la contraseña una vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('perfil_alterno_id')->nullable()->after('tienda_default_id')
                ->constrained('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['perfil_alterno_id']);
            $table->dropColumn('perfil_alterno_id');
        });
    }
};
