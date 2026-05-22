<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas_proceso', function (Blueprint $table) {
            $table->id();
            $table->string('proceso');         // tapizado, esqueleteria_silla, laca, etc.
            $table->string('descripcion');     // descripción legible
            $table->string('unidad');          // pieza, m2, ml, puesto
            $table->decimal('tarifa', 12, 2);  // precio por unidad en COP
            $table->string('aplica_a')->nullable(); // sillas, sofas, camas, comedores, general
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas_proceso');
    }
};
