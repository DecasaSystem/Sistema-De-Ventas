<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las prestaciones sociales que se PAGAN (no solo se provisionan) y la
 * liquidación de quien se va (dueño, 2026-10-10: "ponerle todo el tema de las
 * primas, las liquidaciones y todo eso").
 *
 * Lo que se debe no se guarda: sale de lo que ya provisionó cada pago de
 * nómina con los conceptos configurables (CostoEmpleador). Aquí queda solo lo
 * que se pagó, para restarlo:
 *
 * - `nomina_prestaciones_pagos`: cada prima, consignación de cesantías, pago de
 *   intereses, vacaciones e indemnización, con su periodo.
 * - `nomina_liquidaciones`: la liquidación de quien se retira, con todo el
 *   desglose congelado.
 * - En `usuarios`: fecha de retiro, tipo de contrato y fondo de cesantías.
 *
 * Solo crea tablas y agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nomina_liquidaciones')) {
            Schema::create('nomina_liquidaciones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('usuario_id')->constrained('usuarios');
                $t->date('fecha_ingreso');
                $t->date('fecha_retiro');
                // renuncia | despido_justa_causa | despido_sin_justa_causa | fin_contrato | mutuo_acuerdo
                $t->string('motivo', 30);
                $t->string('tipo_contrato', 20)->nullable();
                $t->decimal('total', 15, 2);
                $t->json('detalle');
                $t->string('estado', 10)->default('pagada');   // pagada | anulada
                $t->date('fecha_pago');
                $t->text('notas')->nullable();
                $t->foreignId('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->string('motivo_anulacion', 200)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('nomina_prestaciones_pagos')) {
            Schema::create('nomina_prestaciones_pagos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('usuario_id')->constrained('usuarios');
                // prima | cesantias | intereses_cesantias | vacaciones | indemnizacion
                $t->string('tipo', 25);
                $t->date('periodo_desde');
                $t->date('periodo_hasta');
                $t->decimal('dias', 8, 2)->nullable();          // vacaciones: días tomados
                $t->decimal('monto', 15, 2);
                // pago (al trabajador) | consignacion (al fondo de cesantías) |
                // con_nomina (vacaciones que se pagaron dentro de la nómina normal)
                $t->string('forma', 15)->default('pago');
                $t->string('destino', 120)->nullable();         // el fondo de cesantías
                $t->date('fecha_pago');
                $t->foreignId('liquidacion_id')->nullable()->constrained('nomina_liquidaciones')->nullOnDelete();
                $t->json('detalle')->nullable();
                $t->text('notas')->nullable();
                $t->string('estado', 10)->default('pagado');    // pagado | anulado
                $t->string('motivo_anulacion', 200)->nullable();
                $t->foreignId('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
                $t->index(['usuario_id', 'tipo']);
                $t->index('fecha_pago');
            });
        }

        Schema::table('usuarios', function (Blueprint $t) {
            if (! Schema::hasColumn('usuarios', 'nomina_retiro')) {
                $t->date('nomina_retiro')->nullable();
            }
            // indefinido | fijo | obra_labor | aprendizaje | servicios
            if (! Schema::hasColumn('usuarios', 'nomina_tipo_contrato')) {
                $t->string('nomina_tipo_contrato', 20)->nullable();
            }
            if (! Schema::hasColumn('usuarios', 'nomina_fondo_cesantias')) {
                $t->string('nomina_fondo_cesantias', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropColumn(['nomina_retiro', 'nomina_tipo_contrato', 'nomina_fondo_cesantias']);
        });
        Schema::dropIfExists('nomina_prestaciones_pagos');
        Schema::dropIfExists('nomina_liquidaciones');
    }
};
