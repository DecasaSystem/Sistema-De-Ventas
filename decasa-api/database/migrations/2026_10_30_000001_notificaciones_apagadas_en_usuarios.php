<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué grupos de avisos tiene apagados cada persona (Trabajadores → Notificaciones).
 *
 * Una lista de claves de App\Support\TiposNotificacion, por ejemplo
 * ["cambios_dinero","atrasados"]. Vacía o null = le llega todo, que es como
 * estaba: nadie deja de recibir nada al subir esto.
 *
 * Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'notificaciones_apagadas')) return;
        Schema::table('usuarios', function (Blueprint $table) {
            $table->json('notificaciones_apagadas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('notificaciones_apagadas');
        });
    }
};
