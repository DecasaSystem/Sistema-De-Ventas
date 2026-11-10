<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adelantos de sueldo: "le pago la quincena (o una parte) antes del 15".
 *
 * No se guardan como ajuste negativo (el atajo "Anticipo" de Novedades): un
 * ajuste baja lo que la nómina COSTÓ, y Finanzas veía la quincena más barata.
 * Un adelanto es parte del mismo sueldo entregada antes: el pago guarda su
 * total completo y aparte cuánto ya se había adelantado (`nomina_pagos.adelantos`).
 * Solo crea una tabla y agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nomina_adelantos')) {
            Schema::create('nomina_adelantos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
                $table->date('fecha');
                $table->decimal('monto', 12, 2);
                $table->string('nota', 160)->nullable();
                // El pago de nómina que lo descontó; null mientras no se ha cobrado.
                $table->foreignId('nomina_pago_id')->nullable()->constrained('nomina_pagos')->nullOnDelete();
                // Lo que quedó de un adelanto más grande que el pago que lo
                // descontó: la parte que pasa al siguiente pago.
                $table->unsignedBigInteger('resto_de_id')->nullable();
                // La liquidación al retiro que lo descontó.
                $table->unsignedBigInteger('liquidacion_id')->nullable();
                $table->unsignedBigInteger('registrado_por')->nullable();
                $table->timestamps();

                $table->index(['usuario_id', 'nomina_pago_id']);
            });
        }

        if (! Schema::hasColumn('nomina_pagos', 'adelantos')) {
            Schema::table('nomina_pagos', function (Blueprint $table) {
                // Lo que ya se le había entregado antes de este pago. Lo que se
                // entrega al pagar es `total - adelantos`.
                $table->decimal('adelantos', 12, 2)->default(0)->after('total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nomina_pagos', 'adelantos')) {
            Schema::table('nomina_pagos', fn (Blueprint $t) => $t->dropColumn('adelantos'));
        }
        Schema::dropIfExists('nomina_adelantos');
    }
};
