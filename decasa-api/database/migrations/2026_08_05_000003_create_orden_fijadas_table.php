<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes que cada quien fija para tenerlas de primeras en su lista.
 *
 * Es un marcador personal: solo lo ve quien lo puso. Una fila por persona y
 * orden, con índice único para que fijar dos veces no cree duplicados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_fijadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['orden_id', 'usuario_id']);
            // Se consulta siempre "lo que fijó este usuario"
            $table->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_fijadas');
    }
};
