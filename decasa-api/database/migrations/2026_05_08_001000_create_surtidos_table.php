<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surtidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supervisor_id');
            $table->text('notas')->nullable();
            $table->enum('estado', ['enviado', 'completado', 'rechazado_parcial'])->default('enviado');
            $table->timestamps();
            $table->foreign('supervisor_id')->references('id')->on('usuarios');
            $table->index(['supervisor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surtidos');
    }
};
