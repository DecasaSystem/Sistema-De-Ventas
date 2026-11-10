<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos de Inventario que no se fabrican en el taller (pedido del usuario, 2026-11-10):
 * los importados y los que se compran hechos. No se sabe su costo de fabricación, así que en
 * Costos no quedan "en espera" de una receta: se apartan con su origen.
 *
 * Tabla aparte y no una columna en `productos`: es un dato de Costos, y `productos` la leen
 * los agentes de chat (docs/contrato-agentes.md). Solo crea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('productos_no_fabricados')) return;

        Schema::create('productos_no_fabricados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->unique()->constrained('productos')->cascadeOnDelete();
            $table->string('origen', 20);                 // importado | comprado
            $table->string('nota', 255)->nullable();      // de dónde viene, proveedor…
            $table->foreignId('marcado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_no_fabricados');
    }
};
