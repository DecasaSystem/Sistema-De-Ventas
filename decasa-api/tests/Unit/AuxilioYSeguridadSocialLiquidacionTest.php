<?php

namespace Tests\Unit;

use App\Models\NominaSueldo;
use App\Models\Usuario;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * El auxilio de transporte se suma y la seguridad social se resta, los dos
 * prorrateados del valor mensual por los días del ciclo.
 *
 * La cuenta de referencia es la quincena del mínimo 2026:
 *
 *   875.400 (15 × 58.360) + 124.548 (249.095 × 15/30) − 70.036 (140.072 × 15/30)
 *   = 929.912
 *
 * Y quien no tiene auxilio ni seguridad social cobra el sueldo a secas,
 * aunque comparta el mismo sueldo del catálogo: eso se apaga en su ficha.
 */
class AuxilioYSeguridadSocialLiquidacionTest extends TestCase
{
    private const AUXILIO_MES   = 249095;
    private const SEGURIDAD_MES = 140072;

    private function minimo(bool $auxilio = true, bool $seguridad = true, string $periodicidad = 'quincenal'): Usuario
    {
        $sueldo = new NominaSueldo([
            'nombre'                     => 'Mínimo',
            'valor'                      => 58360,
            'unidad'                     => 'dia',
            'horas_dia'                  => 8,
            'valor_auxilio_mes'          => self::AUXILIO_MES,
            'valor_seguridad_social_mes' => self::SEGURIDAD_MES,
        ]);

        $u = new Usuario([
            'periodicidad'            => $periodicidad,
            'nomina_auxilio'          => $auxilio,
            'nomina_seguridad_social' => $seguridad,
        ]);
        $u->nomina_desde = '2026-08-01';
        $u->setRelation('sueldo', $sueldo);
        $u->setRelation('bonificacion', null);
        $u->setRelation('ajustes', new Collection());
        $u->setRelation('producciones', new Collection());
        $u->setRelation('ausencias', new Collection());

        return $u;
    }

    private function quincena(Usuario $u): array
    {
        return NominaLiquidador::liquidar($u, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-15'), Carbon::parse('2026-09-20'));
    }

    public function test_quincena_del_minimo_suma_auxilio_y_resta_seguridad_social(): void
    {
        $l = $this->quincena($this->minimo());

        $this->assertSame(875400.0, $l['subtotal']);
        $this->assertSame(124548.0, $l['auxilio_transporte']);          // 249.095 × 15 / 30, redondeado una vez
        $this->assertSame(70036.0,  $l['descuento_seguridad_social']);  // 140.072 × 15 / 30
        $this->assertSame(929912.0, $l['total']);
    }

    public function test_sin_auxilio_ni_seguridad_social_cobra_el_sueldo_a_secas(): void
    {
        $l = $this->quincena($this->minimo(auxilio: false, seguridad: false));

        $this->assertSame(0.0,      $l['auxilio_transporte']);
        $this->assertSame(0.0,      $l['descuento_seguridad_social']);
        $this->assertSame(0.0,      $l['valor_auxilio_dia']);             // y una incapacidad no le descontaría nada
        $this->assertSame(875400.0, $l['total']);
    }

    public function test_se_pueden_activar_por_separado(): void
    {
        $l = $this->quincena($this->minimo(auxilio: true, seguridad: false));

        $this->assertSame(124548.0, $l['auxilio_transporte']);
        $this->assertSame(0.0,      $l['descuento_seguridad_social']);
        $this->assertSame(999948.0, $l['total']);                         // 875.400 + 124.548
    }

    public function test_el_mes_completo_paga_el_auxilio_entero(): void
    {
        $u = $this->minimo(periodicidad: 'mensual');
        $l = NominaLiquidador::liquidar($u, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), Carbon::parse('2026-10-02'));

        $this->assertSame(30,          $l['dias']);
        $this->assertSame(249095.0,    $l['auxilio_transporte']);
        $this->assertSame(140072.0,    $l['descuento_seguridad_social']);
    }
}
