<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La mayoría del taller cobra quincenal, pero no todos: hace falta poder
 * elegir diario, semanal, cada 20 días o mensual por trabajador (no por
 * sueldo del catálogo — dos personas con el mismo sueldo pueden cobrar con
 * distinta frecuencia).
 *
 * Default 'quincenal' en ambas tablas para que nada existente cambie de
 * comportamiento: los empleados y períodos ya creados son, de hecho,
 * quincenales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->enum('periodicidad', ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])
                ->default('quincenal')->after('horas_dia');
        });

        Schema::table('nomina_periodos', function (Blueprint $table) {
            $table->enum('periodicidad', ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])
                ->default('quincenal')->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_periodos', function (Blueprint $table) {
            $table->dropColumn('periodicidad');
        });
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('periodicidad');
        });
    }
};
