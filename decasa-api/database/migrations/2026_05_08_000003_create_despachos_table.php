<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despachos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conductor_id')->constrained('usuarios');
            $table->foreignId('supervisor_id')->constrained('usuarios');
            $table->enum('estado', ['asignado', 'en_ruta', 'completado'])->default('asignado');
            $table->text('notas')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despachos');
    }
};
