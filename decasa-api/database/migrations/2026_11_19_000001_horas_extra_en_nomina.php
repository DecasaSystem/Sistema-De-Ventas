<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horas extra y recargos en la nómina (pedido del usuario, 2026-11-18).
 *
 * Antes solo había una pastilla "Hora extra" en Ajustes que ponía el nombre:
 * el valor se escribía a mano y no contaba como salario para aportes ni
 * prestaciones. Ahora se anotan horas con su tipo y el sistema saca el valor
 * del valor hora del trabajador.
 *
 * - `nomina_tipos_hora_extra`: cada tipo es una fila (extra diurna, recargo
 *   nocturno…). `calculo` = 'extra' (se paga la hora más el %: hora × 1,25) o
 *   'recargo' (la hora ya va en el sueldo; solo se suma el %: hora × 0,35).
 * - `nomina_tipo_hora_extra_tarifas`: el porcentaje con su fecha "desde", como
 *   los del costo del empleador: un cambio de ley no mueve lo ya pagado.
 * - `nomina_horas_extra`: lo que se anota (fecha, tipo, horas). Cae en el
 *   ciclo de esa fecha y se engancha al pago, como las faltas.
 * - `nomina_pagos.horas_extra(_detalle)`: lo que se pagó, congelado.
 *
 * Los porcentajes de arranque son los de ley (CST arts. 168 y 179; Ley 2466 de
 * 2025 sube el dominical por etapas): **revisarlos con el contador**.
 * Solo crea tablas y agrega columnas.
 */
return new class extends Migration
{
    /** clave, nombre, calculo, [desde => %] */
    private const TIPOS = [
        ['extra_diurna',            'Extra diurna',             'extra',   ['2025-01-01' => 25]],
        ['extra_nocturna',          'Extra nocturna',           'extra',   ['2025-01-01' => 75]],
        ['recargo_nocturno',        'Recargo nocturno',         'recargo', ['2025-01-01' => 35]],
        ['recargo_dominical',       'Dominical o festivo',      'recargo', ['2025-01-01' => 75, '2025-07-01' => 80, '2026-07-01' => 90, '2027-07-01' => 100]],
        ['extra_dominical_diurna',  'Extra dominical diurna',   'extra',   ['2025-01-01' => 100, '2025-07-01' => 105, '2026-07-01' => 115, '2027-07-01' => 125]],
        ['extra_dominical_nocturna', 'Extra dominical nocturna', 'extra',  ['2025-01-01' => 150, '2025-07-01' => 155, '2026-07-01' => 165, '2027-07-01' => 175]],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('nomina_tipos_hora_extra')) {
            Schema::create('nomina_tipos_hora_extra', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 40)->unique();
                $t->string('nombre', 80);
                $t->string('calculo', 10)->default('extra'); // extra | recargo
                $t->boolean('activo')->default(true);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('nomina_tipo_hora_extra_tarifas')) {
            Schema::create('nomina_tipo_hora_extra_tarifas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tipo_id')->constrained('nomina_tipos_hora_extra')->cascadeOnDelete();
                $t->decimal('porcentaje', 8, 4);
                $t->date('desde');
                $t->timestamps();
                $t->unique(['tipo_id', 'desde']);
            });
        }

        if (! Schema::hasTable('nomina_horas_extra')) {
            Schema::create('nomina_horas_extra', function (Blueprint $t) {
                $t->id();
                $t->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
                $t->foreignId('tipo_id')->constrained('nomina_tipos_hora_extra');
                $t->foreignId('nomina_pago_id')->nullable()->constrained('nomina_pagos')->nullOnDelete();
                $t->date('fecha');
                $t->decimal('horas', 6, 2);
                $t->string('nota', 160)->nullable();
                $t->foreignId('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
                $t->index(['usuario_id', 'fecha']);
            });
        }

        Schema::table('nomina_pagos', function (Blueprint $t) {
            if (! Schema::hasColumn('nomina_pagos', 'horas_extra')) {
                $t->decimal('horas_extra', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('nomina_pagos', 'horas_extra_detalle')) {
                $t->json('horas_extra_detalle')->nullable();
            }
        });

        if (DB::table('nomina_tipos_hora_extra')->exists()) {
            return;
        }
        $ahora = now();
        foreach (self::TIPOS as $i => [$clave, $nombre, $calculo, $tarifas]) {
            $id = DB::table('nomina_tipos_hora_extra')->insertGetId([
                'clave' => $clave, 'nombre' => $nombre, 'calculo' => $calculo, 'activo' => true,
                'orden' => $i + 1, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            foreach ($tarifas as $desde => $pct) {
                DB::table('nomina_tipo_hora_extra_tarifas')->insert([
                    'tipo_id' => $id, 'porcentaje' => $pct, 'desde' => $desde,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('nomina_pagos', function (Blueprint $t) {
            foreach (['horas_extra', 'horas_extra_detalle'] as $c) {
                if (Schema::hasColumn('nomina_pagos', $c)) $t->dropColumn($c);
            }
        });
        Schema::dropIfExists('nomina_horas_extra');
        Schema::dropIfExists('nomina_tipo_hora_extra_tarifas');
        Schema::dropIfExists('nomina_tipos_hora_extra');
    }
};
