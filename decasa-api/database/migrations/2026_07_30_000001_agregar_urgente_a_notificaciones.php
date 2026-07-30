<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una notificación urgente se ve distinta y no se pierde entre las demás.
 * Hoy la usa el aviso a facturación de que cambió la plata de una orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->boolean('urgente')->default(false)->after('leida');
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropColumn('urgente');
        });
    }
};
