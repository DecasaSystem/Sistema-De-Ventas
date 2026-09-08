<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incapacidad médica en Nómina.
 *
 * Es una ausencia como la falta —cuelga del trabajador y una fecha, cae en
 * el ciclo que la contenga, se bloquea si ese ciclo ya se pagó— pero el
 * dinero se cuenta distinto: el día se paga completo y solo se descuenta el
 * auxilio de transporte, un valor fijo por día que el trabajador no
 * devengó porque no se transportó.
 *
 * Por eso se reusa `nomina_ausencias` con una columna `tipo` en vez de una
 * tabla nueva: todo el calendario y el "no anotar en fecha ya pagada" ya
 * está resuelto y es idéntico.
 *
 * `valor_auxilio_dia` vive en el sueldo (default 8.303 para todos, editable):
 * es un valor nacional que cambia por decreto cada año, y ponerlo en el
 * catálogo de sueldos permite además dejarlo en 0 para quien no tiene
 * derecho a auxilio (más de 2 salarios mínimos).
 *
 * El pago congela `descuento_incapacidad` y `valor_auxilio_dia`, igual que
 * ya congela el resto del desglose: cambiar el auxilio el año que viene no
 * mueve un pago ya hecho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_ausencias', function (Blueprint $table) {
            $table->enum('tipo', ['falta', 'incapacidad'])->default('falta')->after('nomina_pago_id');
        });

        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->decimal('valor_auxilio_dia', 12, 2)->default(8303)->after('horas_dia');
        });

        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->decimal('valor_auxilio_dia', 12, 2)->default(0)->after('descuento_faltas');
            $table->decimal('descuento_incapacidad', 12, 2)->default(0)->after('valor_auxilio_dia');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->dropColumn(['valor_auxilio_dia', 'descuento_incapacidad']);
        });

        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->dropColumn('valor_auxilio_dia');
        });

        Schema::table('nomina_ausencias', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
