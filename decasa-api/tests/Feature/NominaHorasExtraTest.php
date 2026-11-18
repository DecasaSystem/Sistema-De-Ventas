<?php

namespace Tests\Feature;

use App\Models\NominaHoraExtra;
use App\Models\NominaPago;
use App\Models\NominaTipoHoraExtra;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\CostoEmpleador;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EsquemaNomina;
use Tests\TestCase;

/**
 * Horas extra y recargos (2026-11-18): se anotan horas con su tipo, el valor
 * sale del valor hora × factor del tipo ese día, se suman al ciclo, se
 * congelan al pagar y cuentan como salario para el costo del empleador.
 */
class NominaHorasExtraTest extends TestCase
{
    use EsquemaNomina;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'America/Bogota'));
        $this->montarEsquemaNomina();
        (require database_path('migrations/2026_11_19_000001_horas_extra_en_nomina.php'))->up();

        // 80.000 al día, 8 horas: 10.000 la hora. Sin auxilio ni seguridad social.
        DB::table('nomina_sueldos')->insert(['id' => 1, 'nombre' => 'Prueba', 'valor' => 80000, 'unidad' => 'dia',
            'horas_dia' => 8, 'valor_auxilio_mes' => 0, 'valor_seguridad_social_mes' => 0, 'activo' => true]);
        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
             'acceso_nomina' => true, 'nomina_sueldo_id' => null, 'nomina_desde' => null, 'periodicidad' => 'quincenal',
             'created_at' => now()],
            ['id' => 2, 'nombre' => 'Lijador', 'email' => null, 'password' => null, 'rol' => 'taller',
             'acceso_nomina' => false, 'nomina_sueldo_id' => 1, 'nomina_desde' => '2026-11-01', 'periodicidad' => 'quincenal',
             'created_at' => now()],
        ]);
        CostoEmpleador::olvidarCache();
    }

    private function jefa(): Usuario
    {
        return Usuario::find(1);
    }

    private function tipo(string $clave): int
    {
        return NominaTipoHoraExtra::where('clave', $clave)->value('id');
    }

    private function anotar(string $clave, string $fecha, float $horas)
    {
        return $this->actingAs($this->jefa())->postJson('/api/nomina/horas-extra', [
            'usuario_id' => 2, 'tipo_id' => $this->tipo($clave), 'fecha' => $fecha, 'horas' => $horas,
        ]);
    }

    private function primerPendiente(): array
    {
        return NominaLiquidador::pendientes(CicloNomina::hoy())[0];
    }

    public function test_la_extra_diurna_suma_al_ciclo_y_se_congela_al_pagar(): void
    {
        $r = $this->anotar('extra_diurna', '2026-11-05', 2)->assertCreated();
        // 2 h × 10.000 × 1,25
        $this->assertEquals(25000, $r->json('monto'));
        $this->assertNull($r->json('aviso'));

        $p = $this->primerPendiente();
        $this->assertEquals(25000, $p['horas_extra']);
        $this->assertEquals(15 * 80000 + 25000, $p['total']);
        // Es salario: entra a la base del costo del empleador.
        $this->assertEquals(15 * 80000 + 25000, $p['costo_empleador']['base_salarial']);

        $this->actingAs($this->jefa())->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('horas_extra', 25000)
            ->assertJsonPath('horas_extra_detalle.0.porcentaje', 25)
            ->assertJsonPath('horas_extra_detalle.0.tipo', 'Extra diurna');

        $pago = NominaPago::first();
        $this->assertEquals($pago->id, NominaHoraExtra::first()->nomina_pago_id);
    }

    public function test_el_recargo_solo_suma_el_porcentaje_y_el_dominical_sube_por_ley(): void
    {
        // Noviembre 2026: dominical al 90 % (Ley 2466, desde jul-2026). 1 h × 10.000 × 0,90.
        $this->anotar('recargo_dominical', '2026-11-08', 1)->assertCreated()->assertJsonPath('monto', 9000);
        // Nocturno: 1 h × 10.000 × 0,35.
        $this->anotar('recargo_nocturno', '2026-11-09', 1)->assertCreated()->assertJsonPath('monto', 3500);

        $this->assertEquals(12500, $this->primerPendiente()['horas_extra']);
    }

    public function test_un_cambio_de_porcentaje_rige_desde_su_fecha(): void
    {
        $this->actingAs($this->jefa())->postJson('/api/nomina/horas-extra/tipos/' . $this->tipo('extra_diurna') . '/tarifas', [
            'porcentaje' => 30, 'desde' => '2026-11-10',
        ])->assertCreated()->assertJsonPath('porcentaje', 30);

        $this->anotar('extra_diurna', '2026-11-05', 1); // 12.500
        $this->anotar('extra_diurna', '2026-11-12', 1); // 13.000

        $this->assertEquals(25500, $this->primerPendiente()['horas_extra']);
    }

    public function test_no_se_anota_en_una_fecha_pagada_ni_se_quita_lo_pagado(): void
    {
        $id = $this->anotar('extra_diurna', '2026-11-05', 1)->json('id');
        $this->actingAs($this->jefa())->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-11-01'])->assertCreated();

        $this->anotar('extra_diurna', '2026-11-06', 1)->assertStatus(422);
        $this->actingAs($this->jefa())->deleteJson("/api/nomina/horas-extra/{$id}")->assertStatus(422);

        // Deshacer el pago las suelta otra vez.
        $this->actingAs($this->jefa())->deleteJson('/api/nomina/pagos/' . NominaPago::first()->id)->assertOk();
        $this->assertNull(NominaHoraExtra::find($id)->nomina_pago_id);
        $this->actingAs($this->jefa())->deleteJson("/api/nomina/horas-extra/{$id}")->assertOk();
    }

    public function test_avisa_si_pasa_el_tope_de_ley_pero_guarda(): void
    {
        $this->anotar('extra_diurna', '2026-11-05', 2)->assertJsonPath('aviso', null);
        $this->anotar('extra_nocturna', '2026-11-05', 1)
            ->assertCreated()
            ->assertJsonPath('aviso', fn ($a) => str_contains($a, 'tope de 2 al día'));
        $this->assertSame(2, NominaHoraExtra::count());
    }

    public function test_se_pueden_sacar_de_la_base_salarial(): void
    {
        $this->actingAs($this->jefa())->putJson('/api/nomina/conceptos-empleador/ajustes', ['horas_extra_es_salario' => false])
            ->assertOk()->assertJsonPath('horas_extra_es_salario', false);
        CostoEmpleador::olvidarCache();
        \App\Support\Configuracion::olvidar();

        $this->anotar('extra_diurna', '2026-11-05', 2);
        $p = $this->primerPendiente();
        $this->assertEquals(15 * 80000 + 25000, $p['total']);
        $this->assertEquals(15 * 80000, $p['costo_empleador']['base_salarial']);
    }

    public function test_se_corrige_lo_anotado_mientras_no_se_pague(): void
    {
        $id = $this->anotar('extra_diurna', '2026-11-05', 2)->json('id');

        // Era nocturna y fueron 3 horas: 3 × 10.000 × 1,75.
        $this->actingAs($this->jefa())->patchJson("/api/nomina/horas-extra/{$id}", [
            'tipo_id' => $this->tipo('extra_nocturna'), 'horas' => 3,
        ])->assertOk()->assertJsonPath('monto', 52500)->assertJsonPath('tipo', 'Extra nocturna');
        $this->assertEquals(52500, $this->primerPendiente()['horas_extra']);

        // Pagada ya no se corrige.
        $this->actingAs($this->jefa())->postJson('/api/nomina/pagos', ['usuario_id' => 2, 'fecha_inicio' => '2026-11-01'])->assertCreated();
        $this->actingAs($this->jefa())->patchJson("/api/nomina/horas-extra/{$id}", ['horas' => 1])->assertStatus(422);
    }

    public function test_el_calculo_del_tipo_se_cambia_desde_nomina(): void
    {
        $this->anotar('recargo_nocturno', '2026-11-05', 2); // recargo: 2 × 10.000 × 0,35 = 7.000

        $this->actingAs($this->jefa())->patchJson('/api/nomina/horas-extra/tipos/' . $this->tipo('recargo_nocturno'), ['calculo' => 'extra'])
            ->assertOk()->assertJsonPath('calculo', 'extra');

        // Ahora es hora + 35 %: 2 × 10.000 × 1,35.
        $this->assertEquals(27000, $this->primerPendiente()['horas_extra']);
    }

    public function test_un_tipo_apagado_no_se_puede_anotar(): void
    {
        $this->actingAs($this->jefa())->patchJson('/api/nomina/horas-extra/tipos/' . $this->tipo('extra_nocturna'), ['activo' => false])->assertOk();
        $this->anotar('extra_nocturna', '2026-11-05', 1)->assertStatus(422);
        $this->actingAs($this->jefa())->getJson('/api/nomina/horas-extra/tipos')->assertOk()->assertJsonCount(5);
    }
}
