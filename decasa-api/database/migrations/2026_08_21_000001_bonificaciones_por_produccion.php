<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonificaciones por producción.
 *
 * Al trabajador se le registra lo que va haciendo ("base cama redonda
 * $30.000", "silla blanca $10.000 × 4") y eso suma. Si en el ciclo llega
 * al tope del esquema que tenga asignado, se le paga un bono según el
 * tramo en el que haya caído: de 2.800.000 a 2.900.000 son 80.000, de ahí
 * para arriba lo que digan las metas siguientes.
 *
 * Todo se configura desde la app, que era el punto: el tope se cambia o se
 * desactiva, las metas se agregan/editan/desactivan una por una, y se
 * pueden tener varios esquemas con nombre ("Bonos del mínimo", "Bono para
 * gente cool") para asignarle a cada trabajador el que le toque — o
 * ninguno, si no aplica para bonificación.
 *
 * `nomina_producciones` sigue el mismo patrón que faltas y ajustes:
 * cuelga del trabajador y una fecha, con `nomina_pago_id` en null hasta
 * que el ciclo se cobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_bonificaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            // Lo mínimo que hay que producir para recibir algo. Se puede
            // apagar sin borrarlo, para no perder el valor configurado.
            $table->decimal('tope', 12, 2)->default(0);
            $table->boolean('tope_activo')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('nomina_bonificacion_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomina_bonificacion_id')->constrained('nomina_bonificaciones')->cascadeOnDelete();
            $table->decimal('desde', 12, 2);
            // Null = "de aquí en adelante": el último tramo no tiene techo.
            $table->decimal('hasta', 12, 2)->nullable();
            $table->decimal('monto', 12, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['nomina_bonificacion_id', 'desde']);
        });

        Schema::table('empleados', function (Blueprint $table) {
            // Null = este trabajador no aplica para bonificación.
            $table->foreignId('nomina_bonificacion_id')->nullable()->after('nomina_sueldo_id')
                ->constrained('nomina_bonificaciones')->nullOnDelete();
        });

        Schema::create('nomina_producciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignId('nomina_pago_id')->nullable()->constrained('nomina_pagos')->nullOnDelete();
            $table->date('fecha');
            $table->string('concepto', 120);
            $table->decimal('valor_unitario', 12, 2);
            $table->decimal('cantidad', 8, 2);
            // Guardado y no calculado al vuelo: es lo que se sumó de verdad.
            $table->decimal('total', 12, 2);
            $table->timestamps();
            $table->index(['empleado_id', 'fecha']);
        });

        Schema::table('nomina_pagos', function (Blueprint $table) {
            // Congelados junto con el resto del desglose al cobrar.
            $table->decimal('produccion_total', 12, 2)->default(0)->after('total_ajustes');
            $table->decimal('bonificacion', 12, 2)->default(0)->after('produccion_total');
            $table->string('bonificacion_nombre', 160)->nullable()->after('bonificacion');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->dropColumn(['produccion_total', 'bonificacion', 'bonificacion_nombre']);
        });

        Schema::dropIfExists('nomina_producciones');

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_bonificacion_id');
        });

        Schema::dropIfExists('nomina_bonificacion_metas');
        Schema::dropIfExists('nomina_bonificaciones');
    }
};
