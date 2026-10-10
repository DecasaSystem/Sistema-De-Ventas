<?php

namespace Tests\Unit;

use App\Models\NominaSueldo;
use App\Models\Usuario;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Lo que la empresa pone por detrás de la nómina: aportes del empleador y
 * prestaciones. No cambia lo que se le paga al trabajador; es lo que lee
 * Finanzas para el costo real.
 *
 * Quincena del mínimo 2026 (empresa exonerada, ARL riesgo 1):
 *
 *   base salarial      875.400   base prestaciones 999.948 (+ auxilio 124.548)
 *   pensión 12 %       105.048
 *   ARL 0,522 %          4.570
 *   caja 4 %            35.016   → aportes      144.634
 *   prima 8,33 %        83.296
 *   cesantías 8,33 %    83.296
 *   intereses 12 %       9.996
 *   vacaciones 4,17 %   36.504   → prestaciones 213.092
 *                                  total        357.726
 */
class CostoEmpleadorLiquidacionTest extends TestCase
{
    private function minimo(array $ficha = []): Usuario
    {
        $sueldo = new NominaSueldo([
            'nombre'                     => 'Mínimo',
            'valor'                      => 58360,
            'unidad'                     => 'dia',
            'horas_dia'                  => 8,
            'valor_auxilio_mes'          => 249095,
            'valor_seguridad_social_mes' => 140072,
        ]);

        $u = new Usuario(['periodicidad' => 'quincenal'] + $ficha);
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

    private function linea(array $costo, string $clave): ?float
    {
        $l = collect($costo['lineas'])->firstWhere('clave', $clave);

        return $l ? (float) $l['monto'] : null;
    }

    public function test_quincena_del_minimo_con_aportes_y_prestaciones(): void
    {
        $l = $this->quincena($this->minimo());
        $c = $l['costo_empleador'];

        $this->assertSame(105048.0, $this->linea($c, 'pension'));
        $this->assertSame(4570.0,   $this->linea($c, 'arl'));
        $this->assertSame(35016.0,  $this->linea($c, 'caja'));
        $this->assertNull($this->linea($c, 'salud'), 'exonerada: no paga salud, ICBF ni SENA');
        $this->assertSame(144634.0, $c['aportes']);

        $this->assertSame(83296.0, $this->linea($c, 'prima'));
        $this->assertSame(83296.0, $this->linea($c, 'cesantias'));
        $this->assertSame(9996.0,  $this->linea($c, 'intereses_cesantias'));
        $this->assertSame(36504.0, $this->linea($c, 'vacaciones'), 'el auxilio no cuenta para vacaciones');
        $this->assertSame(213092.0, $c['prestaciones']);

        $this->assertSame(357726.0, $c['total']);
    }

    public function test_no_cambia_lo_que_se_le_paga_al_trabajador(): void
    {
        // La misma cuenta de AuxilioYSeguridadSocialLiquidacionTest: 929.912.
        $this->assertSame(929912.0, $this->quincena($this->minimo())['total']);
    }

    public function test_sin_afiliacion_no_hay_aportes_pero_si_prestaciones(): void
    {
        // Quien no aporta lo suyo tampoco tiene planilla del empleador; las
        // prestaciones siguen (se apagan en su ficha, una por una).
        $c = $this->quincena($this->minimo(['nomina_seguridad_social' => false]))['costo_empleador'];

        $this->assertSame(0.0, $c['aportes']);
        $this->assertSame(213092.0, $c['prestaciones']);
    }
}
