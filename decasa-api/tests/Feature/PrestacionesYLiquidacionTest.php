<?php

namespace Tests\Feature;

use App\Models\NominaPago;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EsquemaNomina;
use Tests\TestCase;

/**
 * Prima, cesantías, intereses, vacaciones y liquidación al retiro.
 *
 * Un lijador con el mínimo 2026, quincenal, desde el 1 de julio. Cada
 * quincena completa provisiona (CostoEmpleador, ver CostoEmpleadorLiquidacionTest):
 *   prima 83.296 · cesantías 83.296 · intereses 9.996 · vacaciones 36.504
 * El semestre son 12 quincenas: prima 999.552 (la fórmula de ley da 999.948;
 * la diferencia es el 8,33 % contra 1/12 exacto).
 */
class PrestacionesYLiquidacionTest extends TestCase
{
    use EsquemaNomina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEsquemaNomina();

        DB::table('nomina_sueldos')->insert([
            'id' => 1, 'nombre' => 'Mínimo', 'valor' => 58360, 'unidad' => 'dia', 'horas_dia' => 8,
            'valor_auxilio_mes' => 249095, 'valor_seguridad_social_mes' => 140072,
        ]);
        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
             'acceso_nomina' => true, 'nomina_sueldo_id' => null, 'nomina_desde' => null, 'created_at' => now()],
            ['id' => 2, 'nombre' => 'Lijador', 'email' => null, 'password' => null, 'rol' => 'taller',
             'acceso_nomina' => false, 'nomina_sueldo_id' => 1, 'nomina_desde' => '2026-07-01', 'created_at' => now()],
        ]);

        (require database_path('migrations/2026_10_19_000001_costo_empleador_en_nomina.php'))->up();
        (require database_path('migrations/2026_10_21_000001_prestaciones_y_liquidaciones.php'))->up();

        // Las 12 quincenas del segundo semestre, pagadas, con su costo congelado.
        $inicio = Carbon::parse('2026-07-01');
        for ($i = 0; $i < 12; $i++) {
            $ini = $inicio->copy()->addMonthsNoOverflow(intdiv($i, 2))->day($i % 2 ? 16 : 1);
            $fin = $i % 2 ? $ini->copy()->endOfMonth() : $ini->copy()->day(15);
            DB::table('nomina_pagos')->insert([
                'usuario_id' => 2, 'periodicidad' => 'quincenal', 'fecha_inicio' => $ini->toDateString(), 'fecha_fin' => $fin->toDateString(),
                'dias' => 15, 'valor_dia' => 58360, 'subtotal' => 875400, 'auxilio_transporte' => 124548,
                'descuento_seguridad_social' => 70036, 'total' => 929912, 'pagado_at' => $fin->copy()->addDay(),
                'costo_empleador' => 357726,
                'costo_empleador_detalle' => json_encode([
                    'lineas' => [
                        ['clave' => 'pension', 'grupo' => 'aportes', 'monto' => 105048],
                        ['clave' => 'prima', 'grupo' => 'prestaciones', 'monto' => 83296],
                        ['clave' => 'cesantias', 'grupo' => 'prestaciones', 'monto' => 83296],
                        ['clave' => 'intereses_cesantias', 'grupo' => 'prestaciones', 'monto' => 9996],
                        ['clave' => 'vacaciones', 'grupo' => 'prestaciones', 'monto' => 36504],
                    ],
                    'aportes' => 144634, 'prestaciones' => 213092, 'otros' => 0, 'total' => 357726,
                ]),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jefa(): Usuario
    {
        return Usuario::find(1);
    }

    public function test_la_prima_de_diciembre_es_lo_provisionado_y_no_se_paga_dos_veces(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-02 10:00', 'America/Bogota'));

        $r = $this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones?tipo=prima&fecha=2026-12-01')->assertOk()->json();
        $this->assertSame('2026-12-20', $r['fecha_limite']);
        $this->assertEquals(999552, $r['trabajadores'][0]['causado']);
        $this->assertEquals(999552, $r['saldo']);

        $this->actingAs($this->jefa())->postJson('/api/nomina/prestaciones/pagar', [
            'tipo' => 'prima', 'fecha' => '2026-12-01', 'usuarios' => [2], 'fecha_pago' => '2026-12-18',
        ])->assertCreated()->assertJsonPath('total', 999552);

        $this->assertEquals(0, $this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones?tipo=prima&fecha=2026-12-01')->json('saldo'));
        $this->actingAs($this->jefa())->postJson('/api/nomina/prestaciones/pagar', [
            'tipo' => 'prima', 'fecha' => '2026-12-01', 'usuarios' => [2],
        ])->assertStatus(422);
    }

    public function test_las_cesantias_se_consignan_y_los_intereses_vencen_en_enero(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-02 10:00', 'America/Bogota'));
        DB::table('usuarios')->where('id', 2)->update(['nomina_fondo_cesantias' => 'Porvenir']);

        $ces = $this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones?tipo=cesantias&fecha=2026-06-01')->assertOk()->json();
        $this->assertSame('2027-02-14', $ces['fecha_limite']);
        $this->assertEquals(999552, $ces['saldo']);

        $int = $this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones?tipo=intereses_cesantias&fecha=2026-06-01')->json();
        $this->assertSame('2027-01-31', $int['fecha_limite']);
        $this->assertEquals(12 * 9996, $int['saldo']);

        $this->actingAs($this->jefa())->postJson('/api/nomina/prestaciones/pagar', [
            'tipo' => 'cesantias', 'fecha' => '2026-06-01', 'usuarios' => [2],
        ])->assertCreated();
        $this->assertDatabaseHas('nomina_prestaciones_pagos', ['tipo' => 'cesantias', 'forma' => 'consignacion', 'destino' => 'Porvenir']);
    }

    public function test_vacaciones_en_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-02 10:00', 'America/Bogota'));
        // 180 días trabajados × 15 / 360 = 7,5 días (+ lo que lleva de enero: 2 días → 7,58).
        $v = collect($this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones/vacaciones')->assertOk()->json())->firstWhere('usuario_id', 2);
        $this->assertEqualsWithDelta(7.58, $v['dias_causados'], 0.01);

        $this->actingAs($this->jefa())->postJson('/api/nomina/prestaciones/vacaciones', [
            'usuario_id' => 2, 'dias' => 3, 'desde' => '2027-01-04', 'hasta' => '2027-01-06', 'forma' => 'con_nomina',
        ])->assertCreated()->assertJsonPath('monto', 175080);   // 3 × 58.360

        $v = collect($this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones/vacaciones')->json())->firstWhere('usuario_id', 2);
        $this->assertEqualsWithDelta(4.58, $v['dias_pendientes'], 0.01);

        $this->actingAs($this->jefa())->postJson('/api/nomina/prestaciones/vacaciones', [
            'usuario_id' => 2, 'dias' => 10, 'desde' => '2027-02-01', 'hasta' => '2027-02-12', 'forma' => 'pago',
        ])->assertStatus(422);
    }

    /**
     * Despido sin justa causa el 9 de enero de 2027 (indefinido), con la prima
     * de diciembre, las cesantías y los intereses de 2026 sin pagar:
     *
     *   salario 1–9 ene     525.240 + 74.729 aux − 42.022 seg. social = 557.947
     *   prima jul–dic       999.552   ·  prima 1–9 ene   49.977
     *   cesantías 2026      999.552   ·  cesantías ene   49.977
     *   intereses 2026      119.952   ·  intereses ene    5.997
     *   vacaciones          189 días × 15/360 = 7,88 días × 58.360 = 459.877
     *   indemnización       193 días de servicio (< 1 año): 30 días × 58.360 = 1.750.800
     */
    public function test_la_liquidacion_completa_y_se_puede_anular(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-10 10:00', 'America/Bogota'));
        $datos = ['usuario_id' => 2, 'fecha_retiro' => '2027-01-09', 'motivo' => 'despido_sin_justa_causa', 'tipo_contrato' => 'indefinido'];

        $c = $this->actingAs($this->jefa())->postJson('/api/nomina/liquidaciones/calcular', $datos)->assertOk()->json();
        $concepto = fn (string $clave, ?string $desde = null) => collect($c['conceptos'])
            ->first(fn ($x) => $x['clave'] === $clave && (! $desde || ($x['desde'] ?? null) === $desde))['monto'] ?? null;

        $this->assertEquals(557947, $concepto('salario'));
        $this->assertEquals(999552, $concepto('prima', '2026-07-01'));
        $this->assertEquals(49977, $concepto('prima', '2027-01-01'));
        $this->assertEquals(999552, $concepto('cesantias', '2026-01-01'));
        $this->assertEquals(49977, $concepto('cesantias', '2027-01-01'));
        $this->assertEquals(119952, $concepto('intereses_cesantias', '2026-01-01'));
        $this->assertEquals(5997, $concepto('intereses_cesantias', '2027-01-01'));
        $this->assertEquals(459877, $concepto('vacaciones'));
        $this->assertEquals(1750800, $concepto('indemnizacion'));
        $this->assertEquals(557947 + 999552 + 49977 + 999552 + 49977 + 119952 + 5997 + 459877 + 1750800, $c['total']);

        // Renuncia: sin indemnización.
        $r = $this->actingAs($this->jefa())->postJson('/api/nomina/liquidaciones/calcular', ['motivo' => 'renuncia'] + $datos)->json();
        $this->assertEquals($c['total'] - 1750800, $r['total']);

        // Registrarla: paga el ciclo del 1 al 9, guarda las prestaciones y la saca de nómina.
        $liq = $this->actingAs($this->jefa())->postJson('/api/nomina/liquidaciones', $datos)->assertCreated()->json();
        $pagoFinal = NominaPago::where('usuario_id', 2)->whereDate('fecha_inicio', '2027-01-01')->first();
        $this->assertSame('2027-01-09', $pagoFinal?->fecha_fin->toDateString(), 'el último ciclo se paga hasta el retiro');
        $this->assertEquals(8, DB::table('nomina_prestaciones_pagos')->where('liquidacion_id', $liq['id'])->count());
        $u = Usuario::find(2);
        $this->assertNull($u->nomina_sueldo_id);
        $this->assertSame('2027-01-09', $u->nomina_retiro->toDateString());

        // Ya no se le debe prima de diciembre.
        $this->assertEquals(0, $this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones?tipo=prima&fecha=2026-12-01')->json('saldo'));

        // Anular: vuelve a nómina con su sueldo, sin el pago del 1 al 9.
        $this->actingAs($this->jefa())->postJson("/api/nomina/liquidaciones/{$liq['id']}/anular", ['motivo' => 'Se equivocó la fecha'])->assertOk();
        $u->refresh();
        $this->assertEquals(1, $u->nomina_sueldo_id);
        $this->assertNull($u->nomina_retiro);
        $this->assertFalse(NominaPago::where('usuario_id', 2)->whereDate('fecha_inicio', '2027-01-01')->exists());
        $this->assertEquals(999552, $this->actingAs($this->jefa())->getJson('/api/nomina/prestaciones?tipo=prima&fecha=2026-12-01')->json('saldo'));
    }

    public function test_sin_permiso_de_nomina_no_ve_prestaciones_ni_liquida(): void
    {
        $lijador = Usuario::find(2);
        $this->actingAs($lijador)->getJson('/api/nomina/prestaciones?tipo=prima')->assertForbidden();
        $this->actingAs($lijador)->postJson('/api/nomina/liquidaciones/calcular', [])->assertForbidden();
    }
}
