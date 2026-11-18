<?php

namespace Tests\Feature;

use App\Http\Controllers\NominaPagoController;
use App\Models\NominaPago;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EsquemaNomina;
use Tests\TestCase;

/**
 * Dos huecos de Nómina (docs/analisis-nomina.md, A1 y A2):
 *
 * - Cambiar la frecuencia de pago volvía a pagar días ya pagados (semanal →
 *   quincenal) o dejaba días sin pagar nunca (quincenal → mensual).
 * - Deshacer un pago que no era el último lo escondía para siempre.
 */
class NominaCambioDeFrecuenciaYDeshacerTest extends TestCase
{
    use EsquemaNomina;

    /** Valor por día del sueldo de prueba (sin auxilio ni seguridad social). */
    private const DIA = 50000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-02 15:00:00', 'America/Bogota'));
        $this->montarEsquemaNomina();

        DB::table('nomina_sueldos')->insert(['id' => 1, 'nombre' => 'Prueba', 'valor' => self::DIA, 'unidad' => 'dia',
            'horas_dia' => 8, 'valor_auxilio_mes' => 0, 'valor_seguridad_social_mes' => 0, 'activo' => true]);
        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Ana', 'nomina_sueldo_id' => 1, 'nomina_desde' => '2026-11-02',
            'periodicidad' => 'quincenal', 'created_at' => '2026-11-02 12:00:00', 'activo' => true]);
    }

    private function ana(): Usuario
    {
        return Usuario::with(NominaLiquidador::relaciones())->find(1);
    }

    private function pagar(string $inicio): NominaPago
    {
        return DB::transaction(fn () => app(NominaPagoController::class)->registrar($this->ana(), CicloNomina::fecha($inicio), null));
    }

    private function frecuencia(string $f): void
    {
        DB::table('usuarios')->where('id', 1)->update(['periodicidad' => $f]);
    }

    private function pendientes(): array
    {
        return array_map(fn ($p) => [$p['fecha_inicio'], $p['fecha_fin'], $p['dias']],
            NominaLiquidador::pendientes(CicloNomina::hoy()));
    }

    public function test_de_semanal_a_quincenal_no_vuelve_a_pagar_la_semana(): void
    {
        $this->frecuencia('semanal');
        $this->pagar('2026-11-02'); // semana del 2 al 8 de noviembre
        $this->frecuencia('quincenal');

        // Antes: la quincena del 1 al 15 con 14 días (2 a 15), los días 2 a 8 dos veces.
        $this->assertSame([
            ['2026-11-09', '2026-11-15', 7],
            ['2026-11-16', '2026-11-30', 15],
        ], $this->pendientes());

        $pago = $this->pagar('2026-11-09');
        $this->assertSame(7.0, (float) $pago->dias);
        $this->assertSame(7.0 * self::DIA, (float) $pago->total);
        $this->assertSame('9 al 15 de noviembre de 2026', $pago->nombreCiclo());
    }

    public function test_de_quincenal_a_mensual_paga_la_segunda_mitad_del_mes(): void
    {
        $this->pagar('2026-11-01'); // quincena del 1 al 15 (desde el 2: 14 días)
        $this->frecuencia('mensual');

        // Antes: el mes arrancaba el 1, figuraba pagado, y del 16 al 30 no salía nunca.
        $this->assertSame([['2026-11-16', '2026-11-30', 15]], $this->pendientes());
        $this->assertSame(15.0, (float) $this->pagar('2026-11-16')->dias);
    }

    public function test_en_un_mes_de_31_el_cambio_no_paga_un_dia_de_mas(): void
    {
        DB::table('usuarios')->where('id', 1)->update(['nomina_desde' => '2026-10-01']);
        $this->pagar('2026-10-01');
        $this->frecuencia('mensual');

        // 16 al 31 son 16 días corridos; con los 15 ya pagados el mes suma 30.
        $this->assertSame(['2026-10-16', '2026-10-31', 15], $this->pendientes()[0]);
    }

    public function test_el_ciclo_en_curso_no_cuenta_dias_ya_pagados(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 15:00:00', 'America/Bogota'));
        $this->frecuencia('semanal');
        $this->pagar('2026-11-02');
        $this->frecuencia('quincenal');

        $l = NominaLiquidador::cicloActual($this->ana(), CicloNomina::hoy());
        $this->assertSame('2026-11-09', $l['fecha_inicio']);
        $this->assertSame(4, $l['dias']); // 9 al 12
    }

    public function test_no_se_paga_un_ciclo_que_choca_con_un_pago_de_otra_frecuencia(): void
    {
        $this->frecuencia('semanal');
        $this->pagar('2026-11-02');
        $this->frecuencia('quincenal');

        try {
            $this->pagar('2026-11-01');
            $this->fail('Se dejó pagar la quincena completa encima de la semana ya pagada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya se le pagaron días', collect($e->errors())->flatten()->first());
        }
    }

    public function test_sin_cambio_de_frecuencia_todo_sigue_igual(): void
    {
        $this->pagar('2026-11-01');
        $this->assertSame([['2026-11-16', '2026-11-30', 15]], $this->pendientes());
        $pago = $this->pagar('2026-11-16');
        $this->assertSame('2026-11-16', $pago->fecha_inicio->toDateString());
        $this->assertSame(15.0, (float) $pago->dias);
    }

    // ── Cuotas de préstamo (A5 y A6) ─────────────────────────────────────────

    private function prestamo(float $monto, float $cuota): \App\Models\NominaPrestamo
    {
        return \App\Models\NominaPrestamo::create(['usuario_id' => 1, 'monto' => $monto, 'cuotas' => 1,
            'valor_cuota' => $cuota, 'fecha' => '2026-11-01', 'activo' => true]);
    }

    public function test_la_cuota_no_deja_el_pago_en_negativo(): void
    {
        // Quincena del 2 al 15: 14 días × 50.000 = 700.000; cuota de 1.000.000.
        $pr = $this->prestamo(1000000, 1000000);

        $pago = $this->pagar('2026-11-01');
        $this->assertSame(0.0, (float) $pago->total); // antes −300.000
        $this->assertSame(300000.0, $pr->fresh('cuotasPagadas')->saldo());

        // El resto sale en la quincena siguiente.
        $siguiente = $this->pagar('2026-11-16');
        $this->assertSame(15.0 * self::DIA - 300000, (float) $siguiente->total);
        $this->assertTrue($pr->fresh('cuotasPagadas')->saldado());
    }

    public function test_la_pantalla_avisa_cuando_la_cuota_no_alcanza(): void
    {
        $this->prestamo(1000000, 1000000);
        $p = NominaLiquidador::pendientes(CicloNomina::hoy())[0];

        $this->assertSame(700000.0, $p['total_prestamos']);
        $this->assertTrue($p['prestamos'][0]['recortada']);
        $this->assertSame(0.0, (float) $p['total']);
    }

    public function test_con_varios_ciclos_pendientes_la_cuota_no_se_repite(): void
    {
        // Debe 100.000 en una sola cuota y tiene dos quincenas sin pagar.
        $pr = $this->prestamo(100000, 100000);

        $pend = NominaLiquidador::pendientes(CicloNomina::hoy());
        $this->assertSame([100000.0, 0.0], array_column($pend, 'total_prestamos'));
        // La segunda ya no muestra el préstamo: lo saldó la primera.
        $this->assertSame([], $pend[1]['prestamos']->all());

        // Lo que se ve es lo que se paga.
        $pagos = [$this->pagar('2026-11-01'), $this->pagar('2026-11-16')];
        $this->assertSame([(float) $pend[0]['total'], (float) $pend[1]['total']],
            array_map(fn ($p) => (float) $p->total, $pagos));
        $this->assertTrue($pr->fresh('cuotasPagadas')->saldado());
    }

    public function test_la_cuota_se_reparte_entre_ciclos_con_su_saldo(): void
    {
        // 150.000 en cuotas de 100.000, tres quincenas pendientes: 100.000, 50.000, 0.
        DB::table('usuarios')->where('id', 1)->update(['nomina_desde' => '2026-10-16']);
        $this->prestamo(150000, 100000);

        $pend = NominaLiquidador::pendientes(CicloNomina::hoy());
        $this->assertSame([100000.0, 50000.0, 0.0], array_column($pend, 'total_prestamos'));
    }

    public function test_solo_se_deshace_el_ultimo_pago(): void
    {
        $viejo = $this->pagar('2026-11-01');
        $nuevo = $this->pagar('2026-11-16');
        $supervisor = Usuario::create(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_nomina' => true, 'activo' => true]);

        // El del medio no: quedaba sin pagar y sin aparecer en Pagos.
        $this->actingAs($supervisor)->deleteJson("/api/nomina/pagos/{$viejo->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'primero deshaz ese'));
        $this->assertNotNull(NominaPago::find($viejo->id));

        // Del más nuevo al más viejo, sí, y cada uno vuelve a pendientes.
        $this->actingAs($supervisor)->deleteJson("/api/nomina/pagos/{$nuevo->id}")->assertOk();
        $this->assertSame([['2026-11-16', '2026-11-30', 15]], $this->pendientes());
        $this->actingAs($supervisor)->deleteJson("/api/nomina/pagos/{$viejo->id}")->assertOk();
        $this->assertSame('2026-11-01', $this->pendientes()[0][0]);
    }
}
