<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EsquemaNomina;
use Tests\TestCase;

/**
 * Quincenas y meses con la convención de 30 días (docs/analisis-nomina.md, A3):
 * la segunda quincena de febrero paga 15 días, no 13, y febrero entero 30,
 * no 28. El 31 no suma.
 */
class NominaDiasDeFebreroTest extends TestCase
{
    use EsquemaNomina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEsquemaNomina();
        DB::table('nomina_sueldos')->insert(['id' => 1, 'nombre' => 'Prueba', 'valor' => 50000, 'unidad' => 'dia',
            'horas_dia' => 8, 'valor_auxilio_mes' => 0, 'valor_seguridad_social_mes' => 0, 'activo' => true]);
        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Ana', 'nomina_sueldo_id' => 1, 'nomina_desde' => '2026-01-01',
            'periodicidad' => 'quincenal', 'created_at' => '2026-01-01 12:00:00', 'activo' => true]);
    }

    private function dias(string $periodicidad, string $inicio, string $fin, string $hoy): int
    {
        DB::table('usuarios')->where('id', 1)->update(['periodicidad' => $periodicidad]);
        $u = Usuario::with(NominaLiquidador::relaciones())->find(1);

        return NominaLiquidador::liquidar($u, CicloNomina::fecha($inicio), CicloNomina::fecha($fin), CicloNomina::fecha($hoy))['dias'];
    }

    public function test_la_segunda_quincena_de_febrero_paga_15(): void
    {
        $this->assertSame(15, $this->dias('quincenal', '2027-02-16', '2027-02-28', '2027-03-02'));
        // Bisiesto: del 16 al 29.
        $this->assertSame(15, $this->dias('quincenal', '2028-02-16', '2028-02-29', '2028-03-02'));
    }

    public function test_febrero_entero_paga_30(): void
    {
        $this->assertSame(30, $this->dias('mensual', '2027-02-01', '2027-02-28', '2027-03-02'));
    }

    public function test_el_31_no_suma(): void
    {
        $this->assertSame(15, $this->dias('quincenal', '2027-01-16', '2027-01-31', '2027-02-02'));
        $this->assertSame(30, $this->dias('mensual', '2027-01-01', '2027-01-31', '2027-02-02'));
    }

    public function test_el_ciclo_en_curso_cuenta_hasta_hoy(): void
    {
        // 28 de febrero de un bisiesto todavía no es fin de mes: 16 a 28 = 13.
        $this->assertSame(13, $this->dias('quincenal', '2028-02-16', '2028-02-29', '2028-02-28'));
        $this->assertSame(5, $this->dias('quincenal', '2027-02-16', '2027-02-28', '2027-02-20'));
    }

    public function test_quien_entra_a_mitad_de_quincena_cuenta_con_meses_de_30(): void
    {
        DB::table('usuarios')->where('id', 1)->update(['nomina_desde' => '2027-01-20']);
        // 20 al 30 de enero (el 31 no suma): 11.
        $this->assertSame(11, $this->dias('quincenal', '2027-01-16', '2027-01-31', '2027-02-02'));
    }

    public function test_semanal_sigue_con_dias_corridos(): void
    {
        // Semana que cruza de febrero a marzo: 7 días, como siempre.
        $this->assertSame(7, $this->dias('semanal', '2027-02-22', '2027-02-28', '2027-03-02'));
    }

    public function test_el_pago_de_febrero_sale_con_15_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-01 15:00:00', 'America/Bogota'));
        DB::table('usuarios')->where('id', 1)->update(['nomina_desde' => '2027-02-16']);

        $p = NominaLiquidador::pendientes(CicloNomina::hoy())[0];
        $this->assertSame('2027-02-16', $p['fecha_inicio']);
        $this->assertSame(15, $p['dias']);
        $this->assertEquals(15 * 50000, $p['total']);
    }
}
