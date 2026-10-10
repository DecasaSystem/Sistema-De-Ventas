<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la empresa paga POR DETRÁS de la nómina: aportes del empleador
 * (pensión, salud, ARL, caja de compensación, ICBF, SENA) y prestaciones
 * sociales (prima, cesantías, intereses de cesantías, vacaciones).
 *
 * No se le paga al trabajador en el ciclo —su `total` no cambia—, pero es
 * plata que sale, y sin ella el costo de la nómina se veía como lo que recibe
 * la gente (docs/plan-gestion-financiera.md §3.3). Lo lee Finanzas.
 *
 * Todo es personalizable (pedido del dueño, 2026-10-09), porque la ley cambia
 * y no a todos les toca lo mismo:
 *
 * - `nomina_conceptos_empleador`: cada concepto es una fila. Si sale un aporte
 *   nuevo se crea; si uno deja de existir se desactiva (no se borra: los pagos
 *   viejos lo nombran).
 * - `nomina_concepto_tarifas`: el porcentaje con su fecha de vigencia. Cuando
 *   cambia la ley se agrega uno "desde el 1 de enero": los ciclos anteriores se
 *   siguen calculando con el de antes, y las proyecciones con el que va a regir.
 * - `nomina_concepto_trabajador`: la excepción de cada persona — a uno no se le
 *   paga pensión, otro tiene la ARL del taller. Sin fila, rige lo de la empresa.
 *
 * Cada pago congela su costo (`nomina_pagos.costo_empleador_detalle`).
 * Solo agrega tablas y columnas: nada de lo que ya existe cambia.
 */
return new class extends Migration
{
    /** Ley 2026. `por_defecto` = a quién le aplica si no se dice otra cosa. */
    private const CONCEPTOS = [
        // clave, nombre, grupo, base, base_concepto, %, por_defecto, nota
        ['pension',             'Pensión',                'aportes',      'salario',         null,        12,    true,  null],
        ['salud',               'Salud',                  'aportes',      'salario',         null,        8.5,   false, 'Exonerada (art. 114-1 E.T.) por quien gana menos de 10 mínimos'],
        ['arl',                 'ARL',                    'aportes',      'salario',         null,        0.522, true,  'Riesgo 1; al taller se le pone su clase en la ficha'],
        ['caja',                'Caja de compensación',   'aportes',      'salario',         null,        4,     true,  null],
        ['icbf',                'ICBF',                   'aportes',      'salario',         null,        3,     false, 'Exonerada (art. 114-1 E.T.)'],
        ['sena',                'SENA',                   'aportes',      'salario',         null,        2,     false, 'Exonerada (art. 114-1 E.T.)'],
        ['prima',               'Prima de servicios',     'prestaciones', 'salario_auxilio', null,        8.33,  true,  null],
        ['cesantias',           'Cesantías',              'prestaciones', 'salario_auxilio', null,        8.33,  true,  null],
        ['intereses_cesantias', 'Intereses de cesantías', 'prestaciones', 'concepto',        'cesantias', 12,    true,  null],
        ['vacaciones',          'Vacaciones',             'prestaciones', 'salario',         null,        4.17,  true,  null],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('nomina_conceptos_empleador')) {
            Schema::create('nomina_conceptos_empleador', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 40)->unique();
                $t->string('nombre', 80);
                // aportes (a la planilla) | prestaciones (provisión) | otros
                $t->string('grupo', 20)->default('aportes');
                // salario (sueldo devengado) | salario_auxilio (+ auxilio de
                // transporte) | concepto (porcentaje de otro concepto)
                $t->string('base', 20)->default('salario');
                $t->foreignId('base_concepto_id')->nullable()->constrained('nomina_conceptos_empleador');
                $t->boolean('aplica_por_defecto')->default(true);
                $t->boolean('activo')->default(true);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->string('nota', 200)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('nomina_concepto_tarifas')) {
            Schema::create('nomina_concepto_tarifas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('concepto_id')->constrained('nomina_conceptos_empleador')->cascadeOnDelete();
                $t->decimal('porcentaje', 8, 4);
                $t->date('desde');
                $t->string('nota', 200)->nullable();
                $t->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
                $t->unique(['concepto_id', 'desde']);
            });
        }

        if (! Schema::hasTable('nomina_concepto_trabajador')) {
            Schema::create('nomina_concepto_trabajador', function (Blueprint $t) {
                $t->id();
                $t->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
                $t->foreignId('concepto_id')->constrained('nomina_conceptos_empleador')->cascadeOnDelete();
                $t->boolean('aplica')->default(true);
                // Su propio porcentaje (la ARL del taller). Vacío = el de la empresa.
                $t->decimal('porcentaje', 8, 4)->nullable();
                $t->timestamps();
                $t->unique(['usuario_id', 'concepto_id']);
            });
        }

        Schema::table('nomina_pagos', function (Blueprint $table) {
            // Congelado como el resto del desglose: si cambia la ley, lo que
            // costó este pago no se mueve.
            if (! Schema::hasColumn('nomina_pagos', 'costo_empleador')) {
                $table->decimal('costo_empleador', 12, 2)->nullable()->after('total');
            }
            if (! Schema::hasColumn('nomina_pagos', 'costo_empleador_detalle')) {
                $table->json('costo_empleador_detalle')->nullable()->after('costo_empleador');
            }
        });

        if (DB::table('nomina_conceptos_empleador')->exists()) {
            return;
        }

        $ahora = now();
        $ids = [];
        foreach (self::CONCEPTOS as $i => [$clave, $nombre, $grupo, $base, $baseConcepto, $pct, $porDefecto, $nota]) {
            $ids[$clave] = DB::table('nomina_conceptos_empleador')->insertGetId([
                'clave' => $clave, 'nombre' => $nombre, 'grupo' => $grupo, 'base' => $base,
                'base_concepto_id' => $baseConcepto ? $ids[$baseConcepto] : null,
                'aplica_por_defecto' => $porDefecto, 'activo' => true, 'orden' => $i + 1,
                'nota' => $nota, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('nomina_concepto_tarifas')->insert([
                'concepto_id' => $ids[$clave], 'porcentaje' => $pct, 'desde' => '2026-01-01',
                'nota' => 'Ley 2026', 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('nomina_pagos', function (Blueprint $table) {
            $table->dropColumn(['costo_empleador', 'costo_empleador_detalle']);
        });
        Schema::dropIfExists('nomina_concepto_trabajador');
        Schema::dropIfExists('nomina_concepto_tarifas');
        Schema::dropIfExists('nomina_conceptos_empleador');
    }
};
