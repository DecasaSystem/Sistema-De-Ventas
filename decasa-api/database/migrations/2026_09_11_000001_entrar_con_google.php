<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué cuenta de Google entra cada quien.
 *
 * La sesión se sigue dando por el correo —que es el que ya tiene el usuario en
 * el sistema—, pero la primera vez que alguien entra con Google se anota aquí
 * el identificador de esa cuenta. Sirve para dos cosas: saber quién usa Google
 * y quién no, y que un correo reasignado a otra persona no herede la cuenta
 * ajena, porque la segunda vez tiene que coincidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('google_id', 60)->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
