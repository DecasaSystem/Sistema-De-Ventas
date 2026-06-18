<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Catálogo de tipos de variante (Alerones, Color, Tipo de madera…)
        Schema::create('tipos_variante', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->boolean('afecta_precio')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique('nombre');
        });

        // Opciones de cada tipo (Con alerones, Sin alerones, Roble, Negro…)
        Schema::create('tipo_variante_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_variante_id')->constrained('tipos_variante')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['tipo_variante_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_variante_opciones');
        Schema::dropIfExists('tipos_variante');
    }
};
