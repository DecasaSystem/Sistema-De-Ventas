<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes');
            $table->foreignId('producto_id')->constrained('productos');
            $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario', 12, 2);
            $table->boolean('es_personalizado')->default(false);
            $table->json('specs_personalizacion')->nullable();
            $table->date('fecha_entrega_prom')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_items');
    }
};
