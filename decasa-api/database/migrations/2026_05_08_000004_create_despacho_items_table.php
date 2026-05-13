<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despacho_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despacho_id')->constrained('despachos');
            $table->foreignId('orden_id')->constrained('ordenes');
            $table->integer('posicion');
            $table->enum('estado', ['pendiente', 'entregado'])->default('pendiente');
            $table->string('foto_producto', 500)->nullable();
            $table->string('foto_pago', 500)->nullable();
            $table->timestamp('entregado_at')->nullable();
            $table->unique(['despacho_id', 'orden_id'], 'uq_despacho_orden');
            $table->unique(['despacho_id', 'posicion'], 'uq_despacho_posicion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despacho_items');
    }
};
