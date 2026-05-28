<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversacion_wa_id')->nullable()->constrained('conversaciones_wa')->nullOnDelete();
            $table->foreignId('asesor_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('tienda_id')->nullable()->constrained('tiendas')->nullOnDelete();
            $table->string('nombre_cliente', 100)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('contacto_url', 500)->nullable();
            $table->enum('fuente', ['whatsapp', 'instagram'])->default('whatsapp');
            $table->string('dia', 80);
            $table->string('hora', 20);
            $table->string('motivo', 300)->nullable();
            $table->enum('estado', ['pendiente', 'confirmada', 'completada', 'cancelada'])->default('pendiente');
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index(['asesor_id', 'estado']);
            $table->index(['tienda_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
