<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes');
            $table->foreignId('vendedor_id')->constrained('usuarios');
            $table->enum('tipo', ['anticipo', 'abono', 'saldo_final']);
            $table->decimal('monto', 12, 2);
            $table->enum('metodo', ['efectivo', 'transferencia', 'tarjeta', 'otro'])->nullable();
            $table->string('referencia', 100)->nullable();
            $table->text('notas')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('orden_id', 'idx_pagos_orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
