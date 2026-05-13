<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id')->constrained('produccion');
            $table->enum('tipo_proceso', ['ebanisteria', 'tapizado', 'laca']);
            $table->unsignedTinyInteger('orden');
            $table->enum('estado', ['pendiente', 'en_proceso', 'completado'])->default('pendiente');
            $table->foreignId('completado_por')->nullable()->constrained('usuarios');
            $table->timestamp('completado_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_pasos');
    }
};
