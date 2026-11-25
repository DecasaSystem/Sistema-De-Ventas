<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las notas automáticas del chat de la orden ("se canceló lo que faltaba",
 * "garantía resuelta"…) se marcan aparte de lo que escribe una persona
 * (OrdenMensaje::delSistema). Solo agrega la columna; los mensajes viejos
 * quedan como estaban (no se adivina cuáles fueron automáticos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            if (! Schema::hasColumn('orden_mensajes', 'es_sistema')) {
                $table->boolean('es_sistema')->default(false)->after('mencionados');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            if (Schema::hasColumn('orden_mensajes', 'es_sistema')) {
                $table->dropColumn('es_sistema');
            }
        });
    }
};
