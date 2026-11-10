<?php

namespace Tests\Feature;

use App\Models\NominaPago;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Al marcar "Pagado", el costo del empleador (aportes y prestaciones) queda
 * congelado en el pago junto con el resto del desglose: si después cambian
 * los porcentajes, lo que costó esa quincena no se mueve. Y no le cambia al
 * trabajador lo que recibe.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class NominaCongelaCostoEmpleadorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 15:00:00', 'America/Bogota'));

        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('cedula')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('acceso_nomina')->default(false);
            $t->unsignedBigInteger('nomina_sueldo_id')->nullable(); $t->date('nomina_desde')->nullable();
            $t->unsignedBigInteger('nomina_bonificacion_id')->nullable();
            $t->boolean('nomina_auxilio')->default(true); $t->boolean('nomina_seguridad_social')->default(true);
            $t->string('periodicidad')->default('quincenal');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('nomina_sueldos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('valor', 12, 2); $t->string('unidad')->default('dia');
            $t->decimal('horas_dia', 5, 2)->default(8); $t->decimal('valor_auxilio_mes', 12, 2)->default(0);
            $t->decimal('valor_seguridad_social_mes', 12, 2)->default(0); $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('nomina_bonificaciones', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('periodo')->nullable(); $t->decimal('tope', 12, 2)->nullable();
            $t->boolean('tope_activo')->default(false); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_bonificacion_metas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('nomina_bonificacion_id'); $t->decimal('desde', 12, 2);
            $t->decimal('hasta', 12, 2)->nullable(); $t->decimal('monto', 12, 2); $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('nomina_pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('periodicidad');
            $t->date('fecha_inicio'); $t->date('fecha_fin'); $t->string('sueldo_nombre')->nullable();
            foreach (['valor_dia', 'valor_hora', 'horas_dia', 'dias', 'subtotal', 'descuento_faltas', 'valor_auxilio_dia',
                      'descuento_incapacidad', 'auxilio_transporte', 'valor_seguridad_social_dia',
                      'descuento_seguridad_social', 'total_ajustes', 'produccion_total', 'bonificacion', 'total', 'adelantos'] as $c) {
                $t->decimal($c, 12, 2)->default(0);
            }
            $t->string('bonificacion_nombre')->nullable(); $t->string('bonificacion_detalle')->nullable();
            $t->decimal('costo_empleador', 12, 2)->nullable(); $t->json('costo_empleador_detalle')->nullable();
            $t->text('observaciones')->nullable(); $t->timestamp('pagado_at')->nullable(); $t->timestamps();
            $t->unique(['usuario_id', 'fecha_inicio']);
        });
        foreach (['nomina_ausencias', 'nomina_ajustes', 'nomina_producciones'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
                $t->date('fecha'); $t->string('tipo')->nullable(); $t->decimal('horas', 5, 2)->default(0);
                $t->string('motivo')->nullable(); $t->string('nombre')->nullable(); $t->decimal('monto', 12, 2)->default(0);
                $t->string('concepto')->nullable(); $t->decimal('valor_unitario', 12, 2)->default(0);
                $t->decimal('cantidad', 12, 2)->default(0); $t->decimal('total', 12, 2)->default(0);
                $t->timestamps();
            });
        }
        Schema::create('nomina_prestamos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('motivo')->nullable(); $t->decimal('monto', 12, 2);
            $t->integer('cuotas')->default(1); $t->decimal('valor_cuota', 12, 2)->default(0); $t->date('fecha')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_prestamo_cuotas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('prestamo_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
            $t->decimal('monto', 12, 2); $t->date('fecha')->nullable();
            $t->string('tipo', 20)->nullable(); $t->string('nota', 160)->nullable(); $t->unsignedBigInteger('registrado_por')->nullable(); $t->timestamps();
        });
        Schema::create('nomina_adelantos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->date('fecha'); $t->decimal('monto', 12, 2);
            $t->string('nota', 160)->nullable(); $t->unsignedBigInteger('nomina_pago_id')->nullable();
            $t->unsignedBigInteger('resto_de_id')->nullable(); $t->unsignedBigInteger('liquidacion_id')->nullable();
            $t->unsignedBigInteger('registrado_por')->nullable(); $t->timestamps();
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor'); $t->timestamp('updated_at')->nullable();
        });

        DB::table('nomina_sueldos')->insert([
            'id' => 1, 'nombre' => 'Mínimo', 'valor' => 58360, 'unidad' => 'dia', 'horas_dia' => 8,
            'valor_auxilio_mes' => 249095, 'valor_seguridad_social_mes' => 140072,
        ]);
        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
             'acceso_nomina' => true, 'nomina_sueldo_id' => null, 'nomina_desde' => null, 'created_at' => now()],
            ['id' => 2, 'nombre' => 'Lijador', 'email' => null, 'password' => null, 'rol' => 'taller',
             'acceso_nomina' => false, 'nomina_sueldo_id' => 1, 'nomina_desde' => '2026-08-01', 'created_at' => now()],
        ]);

        // Las tablas de conceptos con los de ley 2026, como en producción.
        (require database_path('migrations/2026_10_19_000001_costo_empleador_en_nomina.php'))->up();
    }

    private function concepto(string $clave): int
    {
        return (int) DB::table('nomina_conceptos_empleador')->where('clave', $clave)->value('id');
    }

    /** El pendiente de un ciclo del lijador. */
    private function pendiente(string $inicio): array
    {
        $r = $this->actingAs(Usuario::find(1))->getJson('/api/nomina/pagos/pendientes')->assertOk()->json();

        return collect($r['pendientes'])->firstWhere('fecha_inicio', $inicio);
    }

    private function linea(array $costo, string $clave): ?float
    {
        $l = collect($costo['lineas'])->firstWhere('clave', $clave);

        return $l ? (float) $l['monto'] : null;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_pago_congela_el_costo_del_empleador_sin_cambiar_lo_que_recibe(): void
    {
        $jefa = Usuario::find(1);
        // Agosto ya está pagado: se paga del más viejo al más nuevo.
        DB::table('nomina_pagos')->insert([
            'usuario_id' => 2, 'periodicidad' => 'quincenal', 'fecha_inicio' => '2026-08-16', 'fecha_fin' => '2026-08-31',
        ]);

        $r = $this->actingAs($jefa)->postJson('/api/nomina/pagos', [
            'usuario_id' => 2, 'fecha_inicio' => '2026-09-01',
        ])->assertCreated()->json();

        $this->assertEquals(929912, $r['total'], 'lo que recibe el trabajador no cambia');
        $this->assertEquals(357726, $r['costo_empleador']['total']);

        $pago = NominaPago::find($r['id']);
        $this->assertEquals(357726, (float) $pago->costo_empleador);
        $this->assertEquals(144634, $pago->costo_empleador_detalle['aportes']);

        // La ley cambia la caja a 0 % desde el 1 de septiembre: lo pagado no se mueve.
        $this->actingAs($jefa)->postJson('/api/nomina/conceptos-empleador/' . $this->concepto('caja') . '/tarifas', [
            'porcentaje' => 0, 'desde' => '2026-09-01',
        ])->assertOk();

        $historial = $this->actingAs($jefa)->getJson('/api/nomina/pagos')->assertOk()->json();
        $this->assertEquals(357726, $historial[0]['costo_empleador']['total']);
    }

    /**
     * "Pagar todos" carga a los trabajadores de una vez (antes se pasaba de
     * los 100 s y Render cortaba con 502). Si la misma persona trae dos
     * ciclos en el lote, el segundo se liquida con lo que dejó el primero:
     * la cuota del préstamo sale con el saldo que quedaba, no con el viejo.
     */
    public function test_pagar_todos_con_dos_ciclos_de_la_misma_persona_y_su_prestamo(): void
    {
        DB::table('nomina_prestamos')->insert([
            'usuario_id' => 2, 'monto' => 60000, 'cuotas' => 2, 'valor_cuota' => 50000,
            'fecha' => '2026-08-10', 'activo' => true,
        ]);
        DB::table('usuarios')->insert([
            'id' => 3, 'nombre' => 'Sin sueldo', 'rol' => 'taller', 'nomina_sueldo_id' => null, 'created_at' => now(),
        ]);

        // Desordenados a propósito: el lote los paga del más viejo al más nuevo.
        $r = $this->actingAs(Usuario::find(1))->postJson('/api/nomina/pagos/lote', ['pagos' => [
            ['usuario_id' => 2, 'fecha_inicio' => '2026-09-01'],
            ['usuario_id' => 2, 'fecha_inicio' => '2026-08-16'],
            ['usuario_id' => 3, 'fecha_inicio' => '2026-09-01'],
            ['usuario_id' => 2, 'fecha_inicio' => '2026-08-01'],
        ]])->assertCreated()->json();

        $this->assertSame(['2026-08-01', '2026-08-16', '2026-09-01'], array_column($r['pagados'], 'fecha_inicio'));
        $this->assertCount(1, $r['omitidos']);
        $this->assertSame(3, $r['omitidos'][0]['usuario_id']);
        $this->assertEquals(array_sum(array_column($r['pagados'], 'total')), $r['total']);

        $this->assertEquals([50000, 10000], DB::table('nomina_prestamo_cuotas')->orderBy('id')->pluck('monto')->map(fn ($m) => (float) $m)->all());
        $this->assertSame(3, NominaPago::count());
    }

    /**
     * Adelanto de sueldo: el 20 se le da una parte y después el resto de la
     * quincena. El pago del 30 queda completo (lo que costó) y no se le
     * entrega nada más. Si después faltó, lo adelantado de más no deja el
     * pago en negativo: pasa al siguiente ciclo.
     */
    public function test_adelanto_de_sueldo_parcial_y_completo(): void
    {
        $jefa = Usuario::find(1);
        // Agosto y la primera de septiembre ya pagadas: solo queda la del 16 al 30.
        foreach ([['2026-08-01', '2026-08-15'], ['2026-08-16', '2026-08-31'], ['2026-09-01', '2026-09-15']] as [$i, $f]) {
            DB::table('nomina_pagos')->insert(['usuario_id' => 2, 'periodicidad' => 'quincenal', 'fecha_inicio' => $i, 'fecha_fin' => $f]);
        }

        $r = $this->actingAs($jefa)->getJson('/api/nomina/adelantos?usuario_id=2')->assertOk()->json();
        $completo = $r['ciclo']['completo'];
        $this->assertEquals(929912, $completo, 'la quincena completa, aunque hoy sea el 20');
        $this->assertEquals($completo, $r['disponible']);

        // Una parte.
        $r = $this->actingAs($jefa)->postJson('/api/nomina/adelantos', ['usuario_id' => 2, 'monto' => 300000])->assertCreated()->json();
        $this->assertEquals(300000, $r['adelantado']);
        $this->assertEquals($completo - 300000, $r['disponible']);

        // No más de lo que va a ganar.
        $this->actingAs($jefa)->postJson('/api/nomina/adelantos', ['usuario_id' => 2, 'monto' => $completo])->assertStatus(422);

        // El resto: la quincena entera adelantada.
        $r = $this->actingAs($jefa)->postJson('/api/nomina/adelantos', ['usuario_id' => 2, 'monto' => $completo - 300000])->assertCreated()->json();
        $this->assertEquals(0, $r['disponible']);

        // Y después faltó un día: la quincena vale menos que lo adelantado.
        DB::table('nomina_ausencias')->insert(['usuario_id' => 2, 'fecha' => '2026-09-25', 'tipo' => 'falta', 'horas' => 8, 'created_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'America/Bogota'));
        $p = $this->pendiente('2026-09-16');
        $this->assertEquals($completo - 58360, $p['total'], 'el total sigue siendo lo que costó');
        $this->assertEquals($p['total'], $p['adelantos']);
        $this->assertEquals(0, $p['a_entregar']);

        $pago = $this->actingAs($jefa)->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-09-16'])->assertCreated()->json();
        $this->assertEquals(0, $pago['a_entregar']);
        $this->assertEquals($p['total'], NominaPago::find($pago['id'])->adelantos);

        // Lo adelantado de más (el día que faltó) queda para el siguiente pago.
        $r = $this->actingAs($jefa)->getJson('/api/nomina/adelantos?usuario_id=2')->assertOk()->json();
        $this->assertEquals(58360, $r['adelantado']);
        $resto = collect($r['adelantos'])->firstWhere('es_resto', true);
        $this->assertEquals(58360, $resto['monto']);
        $this->actingAs($jefa)->deleteJson("/api/nomina/adelantos/{$resto['id']}")->assertStatus(422);
        $this->assertEquals($completo, DB::table('nomina_adelantos')->sum('monto'), 'partir no cambia lo adelantado');

        // Deshacer el pago: todo lo adelantado vuelve a quedar sin descontar.
        $this->actingAs($jefa)->deleteJson("/api/nomina/pagos/{$pago['id']}")->assertOk();
        $this->assertEquals($completo, $this->actingAs($jefa)->getJson('/api/nomina/adelantos?usuario_id=2')->json('adelantado'));
    }

    /**
     * "Debe $200.000 que se le descuentan de a $50.000; el 10 llega y paga
     * todo": el abono baja el saldo y la nómina siguiente ya no descuenta nada.
     * Si paga solo una parte, sigue la misma cuota hasta lo que falte.
     */
    public function test_abono_al_prestamo_por_fuera_de_la_nomina(): void
    {
        $jefa = Usuario::find(1);
        $id = DB::table('nomina_prestamos')->insertGetId([
            'usuario_id' => 2, 'monto' => 200000, 'cuotas' => 4, 'valor_cuota' => 50000,
            'fecha' => '2026-07-20', 'activo' => true,
        ]);
        $this->assertEquals(50000, $this->pendiente('2026-08-01')['total_prestamos']);

        // Paga una parte: $120.000 → quedan $80.000, la cuota sigue de $50.000.
        $r = $this->actingAs($jefa)->postJson("/api/nomina/prestamos/{$id}/abonos", [
            'monto' => 120000, 'fecha' => '2026-08-10', 'nota' => 'En efectivo',
        ])->assertCreated()->json();
        $this->assertEquals(80000, $r['saldo']);
        $this->assertEquals(120000, $r['abonado_aparte']);
        $this->assertSame(0, $r['cuotas_pagadas'], 'el abono no cuenta como cuota de la nómina');
        $this->assertSame('En efectivo', $r['abonos'][0]['nota']);
        $this->assertEquals(50000, $this->pendiente('2026-08-01')['total_prestamos']);

        // No puede abonar más de lo que debe.
        $this->actingAs($jefa)->postJson("/api/nomina/prestamos/{$id}/abonos", ['monto' => 90000])->assertStatus(422);

        // Paga el resto: la nómina ya no le descuenta nada.
        $r = $this->actingAs($jefa)->postJson("/api/nomina/prestamos/{$id}/abonos", ['monto' => 80000])->assertCreated()->json();
        $this->assertTrue($r['saldado']);
        $this->assertEquals(0, $this->pendiente('2026-08-01')['total_prestamos']);

        // Se anotó mal el último: se deshace y vuelve a descontarse.
        $this->actingAs($jefa)->deleteJson("/api/nomina/prestamos/{$id}/abonos/{$r['abonos'][0]['id']}")->assertOk();
        $this->assertEquals(50000, $this->pendiente('2026-08-01')['total_prestamos']);

        // Una cuota de nómina no se borra desde aquí: se anula el pago.
        $this->actingAs($jefa)->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-08-01'])->assertCreated();
        $cuota = DB::table('nomina_prestamo_cuotas')->whereNotNull('nomina_pago_id')->value('id');
        $this->actingAs($jefa)->deleteJson("/api/nomina/prestamos/{$id}/abonos/{$cuota}")->assertStatus(422);
        $this->assertEquals(30000, $this->pendiente('2026-08-16')['total_prestamos'], 'última cuota = lo que falta');
    }

    /**
     * Pagar un ciclo más nuevo dejando uno viejo sin pagar lo escondía para
     * siempre (los pendientes arrancan después del último pago): se frena.
     */
    public function test_no_se_paga_un_ciclo_nuevo_con_uno_viejo_sin_pagar(): void
    {
        $jefa = Usuario::find(1);

        $this->actingAs($jefa)->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_inicio.0', fn ($m) => str_contains($m, 'primero se paga ese'));
        $this->assertSame(0, NominaPago::count());

        // En orden sí, y los que faltan siguen saliendo en pendientes.
        $this->actingAs($jefa)->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-08-01'])->assertCreated();
        $this->assertNotNull($this->pendiente('2026-08-16'));
        $this->assertNotNull($this->pendiente('2026-09-01'));
    }

    public function test_los_pendientes_traen_lo_que_pone_la_empresa(): void
    {
        $r = $this->actingAs(Usuario::find(1))->getJson('/api/nomina/pagos/pendientes')->assertOk()->json();

        // Tres quincenas sin cobrar desde que entró el 1 de agosto.
        $this->assertCount(3, $r['pendientes']);
        $this->assertEquals(
            array_sum(array_map(fn ($p) => $p['costo_empleador']['total'], $r['pendientes'])),
            $r['costo_empleador_total']
        );
    }

    public function test_a_un_trabajador_se_le_quita_la_pension_y_se_le_pone_la_arl_del_taller(): void
    {
        $this->actingAs(Usuario::find(1))->patchJson('/api/nomina/empleados/2', [
            'conceptos_empleador' => [
                ['concepto_id' => $this->concepto('pension'), 'aplica' => false],
                ['concepto_id' => $this->concepto('arl'), 'aplica' => true, 'porcentaje' => 2.436],
            ],
        ])->assertOk()
          ->assertJsonPath('conceptos_empleador.0.aplica', false)
          ->assertJsonPath('conceptos_empleador.0.personalizado', true);

        $c = $this->pendiente('2026-09-01')['costo_empleador'];
        $this->assertNull($this->linea($c, 'pension'));
        $this->assertSame(21325.0, $this->linea($c, 'arl'));            // 875.400 × 2,436 %
        $this->assertEquals(357726 - 105048 - 4570 + 21325, $c['total']);

        // Volver a lo de la empresa: se quita la excepción.
        $this->actingAs(Usuario::find(1))->patchJson('/api/nomina/empleados/2', [
            'conceptos_empleador' => [['concepto_id' => $this->concepto('pension'), 'aplica' => null]],
        ])->assertOk();
        $this->assertSame(105048.0, $this->linea($this->pendiente('2026-09-01')['costo_empleador'], 'pension'));
    }

    public function test_un_cambio_de_ley_rige_desde_su_fecha(): void
    {
        // La pensión sube al 13 % desde el 16 de septiembre.
        $this->actingAs(Usuario::find(1))->postJson('/api/nomina/conceptos-empleador/' . $this->concepto('pension') . '/tarifas', [
            'porcentaje' => 13, 'desde' => '2026-09-16', 'nota' => 'Reforma',
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-02 15:00:00', 'America/Bogota'));

        $antes   = $this->pendiente('2026-09-01')['costo_empleador'];
        $despues = $this->pendiente('2026-09-16')['costo_empleador'];

        $this->assertSame(105048.0, $this->linea($antes, 'pension'), 'el ciclo que cerró antes, con el 12 %');
        $this->assertSame(113802.0, $this->linea($despues, 'pension'), 'el que cierra después, con el 13 %');
    }

    public function test_si_la_ley_trae_un_concepto_nuevo_se_agrega(): void
    {
        $this->actingAs(Usuario::find(1))->postJson('/api/nomina/conceptos-empleador', [
            'nombre' => 'Fondo de solidaridad', 'grupo' => 'aportes', 'base' => 'salario',
            'porcentaje' => 1, 'desde' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('clave', 'fondo_de_solidaridad');

        $c = $this->pendiente('2026-09-01')['costo_empleador'];
        $this->assertSame(8754.0, $this->linea($c, 'fondo_de_solidaridad'));   // 875.400 × 1 %
        $this->assertEquals(357726 + 8754, $c['total']);
    }

    public function test_no_se_desactiva_un_concepto_del_que_otro_depende(): void
    {
        $this->actingAs(Usuario::find(1))
            ->patchJson('/api/nomina/conceptos-empleador/' . $this->concepto('cesantias'), ['activo' => false])
            ->assertStatus(422);
    }

    public function test_lo_que_ya_rige_no_se_borra(): void
    {
        $id = DB::table('nomina_concepto_tarifas')->where('concepto_id', $this->concepto('caja'))->value('id');

        $this->actingAs(Usuario::find(1))->deleteJson("/api/nomina/conceptos-empleador/tarifas/{$id}")->assertStatus(422);
    }

    public function test_sin_permiso_de_nomina_no_ve_ni_cambia_los_conceptos(): void
    {
        $this->actingAs(Usuario::find(2))->getJson('/api/nomina/conceptos-empleador')->assertForbidden();
        $this->actingAs(Usuario::find(2))
            ->postJson('/api/nomina/conceptos-empleador/' . $this->concepto('caja') . '/tarifas', ['porcentaje' => 0, 'desde' => '2026-09-01'])
            ->assertForbidden();
    }
}
