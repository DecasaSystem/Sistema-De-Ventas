<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sobre qué ventana se mide el tope de una bonificación.
 *
 * Hasta ahora se medía siempre sobre el ciclo de pago del trabajador, y eso
 * no siempre es lo que se quiere: un tope de 2.800.000 pensado como meta
 * del mes le saldría dos veces al que cobra quincenal. Ahora se elige por
 * esquema: 'ciclo' (lo de antes) o una frecuencia fija.
 *
 * 'ciclo' de default para que ninguna bonificación ya configurada cambie de
 * comportamiento.
 *
 * Cuando la ventana no coincide con el ciclo de pago, el bono se cobra en el
 * pago donde ESA ventana cierra, y una sola vez: el bono de agosto se paga
 * con la quincena del 16 al 31, midiendo lo producido en todo agosto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_bonificaciones', function (Blueprint $table) {
            $table->enum('periodo', ['ciclo', 'diario', 'semanal', 'quincenal', '20_dias', 'mensual'])
                ->default('ciclo')->after('nombre');
        });

        Schema::table('nomina_pagos', function (Blueprint $table) {
            // Congelado con el pago: sobre qué se midió y en qué rango, para
            // que un pago viejo se explique solo aunque el esquema cambie.
            $table->string('bonificacion_detalle', 255)->nullable()->after('bonificacion_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->dropColumn('bonificacion_detalle');
        });

        Schema::table('nomina_bonificaciones', function (Blueprint $table) {
            $table->dropColumn('periodo');
        });
    }
};
