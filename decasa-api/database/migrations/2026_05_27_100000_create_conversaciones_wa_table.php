<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversaciones_wa', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['pedido', 'cita', 'asesor', 'personalizacion', 'otro'])->default('otro');
            $table->string('telefono', 30);
            $table->string('nombre_cliente', 100)->nullable();
            $table->text('resumen');
            $table->json('historial')->nullable();
            $table->string('whatsapp_url', 255)->nullable();
            $table->enum('estado', ['pendiente', 'tomada', 'terminada'])->default('pendiente');
            $table->foreignId('tomada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('tomada_at')->nullable();
            $table->timestamp('terminada_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversaciones_wa');
    }
};
