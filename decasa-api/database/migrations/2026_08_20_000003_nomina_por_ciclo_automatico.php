<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina por ciclo automático: se acabaron los períodos que alguien tenía
 * que crear a mano.
 *
 * Cada trabajador ya dice con qué frecuencia cobra, así que el ciclo en el
 * que está se saca del calendario (CicloNomina) — no hace falta guardarlo.
 * Lo único que se guarda es el pago: cuando se le cobra a alguien, se
 * materializa una fila en `nomina_pagos` con el desglose congelado, y las
 * faltas y ajustes de ese rango quedan enganchados ahí.
 *
 * Faltas y ajustes ahora son simétricos: los dos cuelgan del trabajador y
 * una fecha, con `nomina_pago_id` en null mientras no se hayan cobrado. Una
 * falta anotada para el mes que viene simplemente cae en un ciclo futuro y
 * se descuenta sola cuando ese ciclo se pague.
 *
 * El valor de un trabajador sale siempre del catálogo de sueldos: se van
 * `valor`/`unidad`/`horas_dia`/`valor_label` sueltos en `empleados`, que
 * duplicaban lo que el sueldo ya dice con nombre propio.
 *
 * Sin datos que traducir: 0 períodos, 0 items, 0 ajustes y 0 sueldos en la
 * base (verificado), y ningún empleado tenía valor propio cargado.
 */
return new class extends Migration
{
    private const FRECUENCIAS = ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'];

    public function up(): void
    {
        // El pago de un ciclo. Se crea recién al cobrar, con todo el
        // desglose congelado: subir el sueldo del catálogo mañana no puede
        // mover un pago que ya se hizo.
        Schema::create('nomina_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->enum('periodicidad', self::FRECUENCIAS);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('sueldo_nombre', 60);
            $table->decimal('valor_dia', 12, 2);
            $table->decimal('valor_hora', 12, 2);
            $table->decimal('horas_dia', 4, 2);
            // Admite fracción: quien entró a mitad de ciclo cobra proporcional.
            $table->decimal('dias', 5, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('descuento_faltas', 12, 2)->default(0);
            $table->decimal('total_ajustes', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->text('observaciones')->nullable();
            $table->timestamp('pagado_at');
            $table->timestamps();
            // Un mismo ciclo no se paga dos veces, ni por doble clic ni por
            // dos personas cobrando al tiempo desde dos teléfonos.
            $table->unique(['empleado_id', 'fecha_inicio']);
            $table->index(['empleado_id', 'fecha_fin']);
        });

        // Las faltas ya no cuelgan del item de un período, sino del pago.
        Schema::table('nomina_ausencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_item_id');
        });
        Schema::table('nomina_ausencias', function (Blueprint $table) {
            $table->foreignId('nomina_pago_id')->nullable()->after('empleado_id')
                ->constrained('nomina_pagos')->nullOnDelete();
        });

        // Los ajustes pasan al mismo patrón que las faltas: trabajador +
        // fecha, para poder anotar un bono antes de que exista el pago.
        Schema::table('nomina_ajustes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_item_id');
        });
        Schema::table('nomina_ajustes', function (Blueprint $table) {
            $table->foreignId('empleado_id')->after('id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignId('nomina_pago_id')->nullable()->after('empleado_id')
                ->constrained('nomina_pagos')->nullOnDelete();
            $table->date('fecha')->after('nomina_pago_id');
        });

        Schema::dropIfExists('nomina_items');
        Schema::dropIfExists('nomina_periodos');

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['valor_label', 'valor', 'unidad', 'horas_dia']);
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('valor_label', 60)->nullable()->after('nomina_sueldo_id');
            $table->decimal('valor', 12, 2)->nullable()->after('valor_label');
            $table->enum('unidad', ['dia', 'hora'])->default('dia')->after('valor');
            $table->decimal('horas_dia', 4, 2)->default(8)->after('unidad');
        });

        Schema::create('nomina_periodos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->enum('periodicidad', self::FRECUENCIAS)->default('quincenal');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedInteger('dias_periodo');
            $table->timestamp('pagado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nomina_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomina_periodo_id')->constrained('nomina_periodos')->cascadeOnDelete();
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->string('valor_label', 60);
            $table->decimal('valor_dia', 12, 2);
            $table->decimal('horas_dia', 4, 2)->default(8);
            $table->decimal('dias_trabajados', 5, 2);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::table('nomina_ajustes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_pago_id');
            $table->dropConstrainedForeignId('empleado_id');
            $table->dropColumn('fecha');
        });
        Schema::table('nomina_ajustes', function (Blueprint $table) {
            $table->foreignId('nomina_item_id')->after('id')->constrained('nomina_items')->cascadeOnDelete();
        });

        Schema::table('nomina_ausencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_pago_id');
        });
        Schema::table('nomina_ausencias', function (Blueprint $table) {
            $table->foreignId('nomina_item_id')->nullable()->after('empleado_id')
                ->constrained('nomina_items')->nullOnDelete();
        });

        Schema::dropIfExists('nomina_pagos');
    }
};
