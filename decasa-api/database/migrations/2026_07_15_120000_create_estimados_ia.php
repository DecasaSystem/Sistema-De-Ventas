<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 — bucle de aprendizaje del cotizador (ver AGENT.md).
 *
 * Cada vez que la IA cotiza un mueble personalizado se guarda aquí (input + receta + precio_ia).
 * Cuando un ebanista corrige ese precio en una consulta de costo, se escribe `precio_humano` y
 * `error_pct`. Los casos ya corregidos se convierten en ejemplos few-shot para cotizaciones
 * futuras de muebles parecidos — el cotizador aprende de las correcciones reales del taller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimados_ia', function (Blueprint $table) {
            $table->id();

            // Qué se pidió cotizar
            $table->string('input_texto', 500);          // nombre + descripción del mueble
            $table->string('categoria', 100)->nullable();
            $table->string('input_hash', 40)->index();    // sha1(categoria|input normalizado) — vínculo grueso
            $table->json('medidas')->nullable();

            // Qué respondió la IA
            $table->json('bom_json')->nullable();         // la receta que generó
            $table->unsignedBigInteger('precio_ia');      // precio_fabricacion estimado
            $table->boolean('requirio_revision')->default(false);
            $table->json('embedding')->nullable();        // para buscar casos similares

            // Qué dijo el humano (se llena cuando el ebanista corrige)
            $table->unsignedBigInteger('precio_humano')->nullable();
            $table->decimal('error_pct', 8, 2)->nullable();     // (precio_ia - precio_humano) / precio_humano
            $table->foreignId('orden_item_id')->nullable()->constrained('orden_items')->nullOnDelete();
            $table->foreignId('corregido_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('corregido_at')->nullable();

            $table->timestamps();

            $table->index(['categoria', 'precio_humano']); // filtrar corregidos por categoría
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimados_ia');
    }
};
