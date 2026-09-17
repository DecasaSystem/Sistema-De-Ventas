<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tela también se aparta cuando el taller produce para la Reserva.
 *
 * Las reservas de tela nacieron colgadas del ítem de una orden. Pero "Producir"
 * en Producción fabrica muebles sin orden —para dejarlos en la Reserva de
 * Fábrica— y esos gastan tela igual. Una reserva puede colgar entonces del
 * ítem de una orden o de la producción misma; una de las dos, nunca las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tela_reservas', function (Blueprint $table) {
            $table->dropForeign(['orden_item_id']);
        });

        Schema::table('tela_reservas', function (Blueprint $table) {
            $table->unsignedBigInteger('orden_item_id')->nullable()->change();
            $table->foreign('orden_item_id')->references('id')->on('orden_items')->cascadeOnDelete();

            $table->foreignId('produccion_id')->nullable()->after('orden_item_id')
                ->constrained('produccion')->cascadeOnDelete();
            $table->index(['produccion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('tela_reservas', function (Blueprint $table) {
            $table->dropForeign(['produccion_id']);
            $table->dropIndex(['produccion_id', 'estado']);
            $table->dropColumn('produccion_id');
        });
    }
};
