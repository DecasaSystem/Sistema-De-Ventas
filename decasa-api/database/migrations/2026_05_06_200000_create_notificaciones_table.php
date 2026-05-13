<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50);        // venta_nueva | entregado | retrasado | por_vencer
            $table->string('titulo', 200);
            $table->string('mensaje', 500);
            $table->boolean('leida')->default(false);
            $table->json('datos')->nullable();  // {orden_id, produccion_id, tienda, ...}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
