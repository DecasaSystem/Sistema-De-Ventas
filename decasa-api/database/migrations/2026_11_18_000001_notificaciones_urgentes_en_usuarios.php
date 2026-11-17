<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué avisos le llegan a cada persona como URGENTES (rojos y fijados arriba de
 * la campana hasta que los ve o los borra).
 *
 * Una lista de claves de App\Support\TiposNotificacion (grupos, más
 * "menciones": cuando lo arroban en el chat de una orden). Null = lo de
 * fábrica, que es solo "menciones". Una lista vacía = la persona quitó todo.
 * Los avisos que el código ya manda urgentes (cambios de plata, garantías por
 * decidir…) siguen urgentes siempre: esto solo agrega.
 *
 * Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'notificaciones_urgentes')) return;
        Schema::table('usuarios', function (Blueprint $table) {
            $table->json('notificaciones_urgentes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('notificaciones_urgentes');
        });
    }
};
