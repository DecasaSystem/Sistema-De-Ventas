<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat de una orden, para resolver dudas entre el vendedor y los supervisores.
 *
 * La conversación queda guardada con la orden: al mes, cuando alguien pregunte
 * por qué se hizo algo, la respuesta está ahí y no en un WhatsApp perdido.
 *
 * `mencionados` guarda a quién se le preguntó directamente — son los únicos que
 * reciben notificación. Los demás supervisores ven el hilo y responden si
 * quieren, pero no se les molesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->text('mensaje');
            $table->json('mencionados')->nullable();
            $table->timestamps();

            // El chat se lee siempre entero y en orden
            $table->index(['orden_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_mensajes');
    }
};
