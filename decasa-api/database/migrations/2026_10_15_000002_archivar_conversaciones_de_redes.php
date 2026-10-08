<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Limpiar terminadas" archiva en vez de borrar.
 *
 * El botón borraba las conversaciones terminadas de Redes. Pero esas filas no
 * son solo de la bandeja: el agente de WhatsApp/Instagram las consulta para
 * saber si un asesor ya atendió al cliente en los últimos días (y retomar la
 * conversación con contexto en vez de saludar de cero), y las métricas de Redes
 * se calculan con ellas. Borrarlas dejaba al bot sin memoria y a las métricas
 * sin historia. Con `archivada_at` la bandeja deja de mostrarlas y lo demás
 * sigue igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conversaciones_wa', 'archivada_at')) return;

        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->timestamp('archivada_at')->nullable()->after('terminada_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('conversaciones_wa', 'archivada_at')) return;

        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->dropIndex(['archivada_at']);
            $table->dropColumn('archivada_at');
        });
    }
};
