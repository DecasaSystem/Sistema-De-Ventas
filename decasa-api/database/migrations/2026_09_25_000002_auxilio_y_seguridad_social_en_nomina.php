<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El auxilio de transporte se SUMA al pago, y la seguridad social se RESTA.
 *
 * Hasta aquí el auxilio solo existía para descontarlo en los días de
 * incapacidad: la liquidación pagaba el sueldo base a secas, y a quien le
 * correspondía auxilio nunca se le sumaba. La cuenta de una quincena con el
 * mínimo es:
 *
 *   875.400 (15 días × 58.360)
 * + 124.548 (auxilio 249.095/mes, prorrateado: × 15 / 30)
 * −  70.036 (seguridad social 140.072/mes, × 15 / 30)
 *
 * Los dos valores se guardan AL MES, que es como los publica el decreto y
 * como la gente los tiene en la cabeza; el sistema los prorratea por los
 * días del ciclo. Antes el auxilio se guardaba por día (8.303), y 8.303 × 15
 * daba 124.545: tres pesos menos que la cuenta correcta.
 *
 * No todo el mundo tiene auxilio ni seguridad social (a quien gana más de
 * dos mínimos no le toca auxilio; hay gente por días sin afiliación), y el
 * mismo sueldo "Mínimo" lo comparten unos con y otros sin. Por eso se
 * activan por trabajador, no por sueldo. Nacen activados: a quien ya tenía
 * auxilio configurado se le empieza a sumar como debe ser.
 *
 * Cada pago congela lo que sumó y lo que restó, como el resto del desglose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->decimal('valor_auxilio_mes', 12, 2)->default(0)->after('horas_dia');
            $table->decimal('valor_seguridad_social_mes', 12, 2)->default(0)->after('valor_auxilio_mes');
        });

        // Lo que había por día pasa a mes. El 8.303 era el mínimo nacional
        // 2026 prorrateado a mano; al mes son 249.095, no 249.090.
        DB::table('nomina_sueldos')->update([
            'valor_auxilio_mes' => DB::raw('CASE WHEN ROUND(valor_auxilio_dia) = 8303 THEN 249095 ELSE ROUND(valor_auxilio_dia * 30) END'),
        ]);

        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->dropColumn('valor_auxilio_dia');
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('nomina_auxilio')->default(true)->after('nomina_bonificacion_id');
            $table->boolean('nomina_seguridad_social')->default(true)->after('nomina_auxilio');
        });

        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->decimal('auxilio_transporte', 12, 2)->default(0)->after('descuento_incapacidad');
            $table->decimal('valor_seguridad_social_dia', 12, 2)->default(0)->after('auxilio_transporte');
            $table->decimal('descuento_seguridad_social', 12, 2)->default(0)->after('valor_seguridad_social_dia');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->dropColumn(['auxilio_transporte', 'valor_seguridad_social_dia', 'descuento_seguridad_social']);
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['nomina_auxilio', 'nomina_seguridad_social']);
        });

        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->decimal('valor_auxilio_dia', 12, 2)->default(8303)->after('horas_dia');
        });
        DB::table('nomina_sueldos')->update(['valor_auxilio_dia' => DB::raw('ROUND(valor_auxilio_mes / 30, 2)')]);
        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->dropColumn(['valor_auxilio_mes', 'valor_seguridad_social_mes']);
        });
    }
};
