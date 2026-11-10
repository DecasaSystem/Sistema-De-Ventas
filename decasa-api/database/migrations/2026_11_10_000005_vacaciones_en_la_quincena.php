<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las vacaciones ya se anotaban con fechas (Prestaciones → Vacaciones), pero
 * la quincena no se enteraba: "pagadas aparte" con descanso le pagaba esos
 * días dos veces, y la quincena traía el auxilio de transporte completo.
 *
 * - `nomina_pagos.dias_vacaciones`: cuántos días del ciclo estuvo de
 *   vacaciones (congelado con el pago, como el resto).
 * - `nomina_prestaciones_pagos.adelanto_id`: las vacaciones pagadas antes de
 *   salir son un adelanto de sueldo (NominaAdelanto) que la quincena descuenta.
 *
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nomina_pagos', 'dias_vacaciones')) {
            Schema::table('nomina_pagos', function (Blueprint $table) {
                $table->decimal('dias_vacaciones', 5, 2)->default(0)->after('dias');
            });
        }
        if (! Schema::hasColumn('nomina_prestaciones_pagos', 'adelanto_id')) {
            Schema::table('nomina_prestaciones_pagos', function (Blueprint $table) {
                $table->unsignedBigInteger('adelanto_id')->nullable()->after('liquidacion_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nomina_pagos', 'dias_vacaciones')) {
            Schema::table('nomina_pagos', fn (Blueprint $t) => $t->dropColumn('dias_vacaciones'));
        }
        if (Schema::hasColumn('nomina_prestaciones_pagos', 'adelanto_id')) {
            Schema::table('nomina_prestaciones_pagos', fn (Blueprint $t) => $t->dropColumn('adelanto_id'));
        }
    }
};
