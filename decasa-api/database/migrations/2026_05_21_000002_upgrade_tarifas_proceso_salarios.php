<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla de salarios por cargo — fuente de verdad para cálculo de mano de obra
        Schema::create('salarios_cargo', function (Blueprint $table) {
            $table->id();
            $table->string('cargo')->unique();          // carpintero, tapicero, lacador, costurera, pintor
            $table->string('descripcion');
            $table->decimal('salario_mensual', 12, 2);  // salario mensual en COP
            $table->unsignedTinyInteger('dias_laborales_mes')->default(26);
            $table->timestamps();
        });

        // Agregar columnas a tarifas_proceso para el modelo basado en tiempo
        Schema::table('tarifas_proceso', function (Blueprint $table) {
            $table->string('cargo')->nullable()->after('aplica_a');         // FK lógica a salarios_cargo.cargo
            $table->decimal('dias_por_unidad', 6, 3)->nullable()->after('cargo'); // días que tarda 1 operario por unidad
        });
    }

    public function down(): void
    {
        Schema::table('tarifas_proceso', function (Blueprint $table) {
            $table->dropColumn(['cargo', 'dias_por_unidad']);
        });
        Schema::dropIfExists('salarios_cargo');
    }
};
