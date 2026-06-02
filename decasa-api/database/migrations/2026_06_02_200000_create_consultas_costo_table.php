<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas_costo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('asignado_a_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('solicitado_por_id')->constrained('usuarios')->cascadeOnDelete();
            $table->enum('estado', ['pendiente', 'respondida'])->default('pendiente');
            $table->text('notas_adicionales')->nullable();
            $table->timestamp('respondido_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consulta_costo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas_costo')->cascadeOnDelete();
            $table->foreignId('orden_item_id')->constrained('orden_items')->cascadeOnDelete();
            $table->decimal('precio_base', 12, 2)->nullable();
            $table->unsignedSmallInteger('margen_ganancia_pct')->default(0);
            $table->decimal('precio_final', 12, 2)->nullable();
            $table->enum('estado', ['pendiente', 'calculado'])->default('pendiente');
            $table->timestamps();
        });

        Schema::create('consulta_costo_desglose', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_item_id')->constrained('consulta_costo_items')->cascadeOnDelete();
            $table->enum('tipo', ['material', 'carpintero', 'tapicero', 'laquero']);
            $table->string('nombre');
            $table->decimal('cantidad', 10, 3)->default(1);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_costo_desglose');
        Schema::dropIfExists('consulta_costo_items');
        Schema::dropIfExists('consultas_costo');
    }
};
