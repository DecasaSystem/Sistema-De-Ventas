<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surtido_tiendas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('surtido_id');
            $table->unsignedBigInteger('tienda_id');
            $table->unsignedBigInteger('vendedor_validador_id');
            $table->enum('estado', ['pendiente', 'aceptado', 'rechazado'])->default('pendiente');
            $table->text('notas_vendedor')->nullable();
            $table->timestamp('respondido_at')->nullable();
            $table->foreign('surtido_id')->references('id')->on('surtidos')->onDelete('cascade');
            $table->foreign('tienda_id')->references('id')->on('tiendas');
            $table->foreign('vendedor_validador_id')->references('id')->on('usuarios');
            $table->unique(['surtido_id', 'tienda_id']);
            $table->index(['vendedor_validador_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surtido_tiendas');
    }
};
