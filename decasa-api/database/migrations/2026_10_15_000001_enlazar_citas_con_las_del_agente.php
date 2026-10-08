<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cita del módulo Citas sabe cuál es su cita en el agente de WhatsApp/Instagram.
 *
 * Hay dos tablas de citas y cada una tiene su dueño: `citas_agentes` es del bot
 * (la crea él, sin migración de aquí) y `citas` es de este sistema. Hasta hoy se
 * cruzaban por el texto del día ("Jueves 9 de octubre"): cuando el cliente
 * cancelaba con el bot había que adivinar cuál cita del módulo era. Ahora el
 * agente manda el id de su cita (`datos_cita.cita_agente_id`) y se guarda aquí.
 *
 * Sin llave foránea a propósito: la otra tabla es del agente y puede no existir
 * en esta base (desarrollo, pruebas). Las citas viejas quedan en null y se
 * siguen cruzando por el día.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('citas', 'cita_agente_id')) return;

        Schema::table('citas', function (Blueprint $table) {
            $table->unsignedBigInteger('cita_agente_id')->nullable()->after('conversacion_wa_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('citas', 'cita_agente_id')) return;

        Schema::table('citas', function (Blueprint $table) {
            $table->dropIndex(['cita_agente_id']);
            $table->dropColumn('cita_agente_id');
        });
    }
};
