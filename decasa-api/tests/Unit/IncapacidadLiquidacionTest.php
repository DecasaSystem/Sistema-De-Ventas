<?php

namespace Tests\Unit;

use App\Models\NominaAusencia;
use App\Models\NominaSueldo;
use App\Models\Usuario;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Cómo pega una incapacidad en la liquidación de un ciclo.
 *
 * A diferencia de la falta —que pierde el día entero (`horas × valor_hora`)—
 * la incapacidad paga el día completo y solo descuenta el auxilio de
 * transporte, un valor fijo por día que sale del sueldo. Dos días de
 * incapacidad = ese valor × 2.
 *
 * Se arma el trabajador a mano, sin base de datos: lo que se comprueba es la
 * cuenta de `NominaLiquidador::liquidar()`, no la persistencia.
 */
class IncapacidadLiquidacionTest extends TestCase
{
    private Carbon $inicio;
    private Carbon $fin;
    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        // Quincena cerrada del 1 al 15 de septiembre; hoy ya pasó.
        $this->inicio = Carbon::parse('2026-09-01');
        $this->fin    = Carbon::parse('2026-09-15');
        $this->hoy    = Carbon::parse('2026-09-20');
    }

    /**
     * @param  array<int, array{fecha: string, tipo: string, horas?: float}>  $ausencias
     */
    private function trabajador(float $valorAuxilioDia, array $ausencias = [], float $seguridadSocialMes = 0): Usuario
    {
        $sueldo = new NominaSueldo([
            'nombre'            => 'Mínimo',
            'valor'             => 60000,          // $60.000 por día → $7.500/hora
            'unidad'            => 'dia',
            'horas_dia'         => 8,
            // Se guarda al mes; 8.303/día × 30 para que las cuentas de abajo
            // sigan hablando en el valor por día.
            'valor_auxilio_mes'          => $valorAuxilioDia * 30,
            'valor_seguridad_social_mes' => $seguridadSocialMes,
        ]);

        $u = new Usuario(['periodicidad' => 'quincenal']);
        $u->nomina_desde = '2026-08-01';          // entró antes del ciclo → días completos
        $u->setRelation('sueldo', $sueldo);
        $u->setRelation('bonificacion', null);
        $u->setRelation('ajustes', new Collection());
        $u->setRelation('producciones', new Collection());
        $u->setRelation('ausencias', collect(array_map(function (array $a) {
            $fila = new NominaAusencia([
                'tipo'  => $a['tipo'],
                'fecha' => $a['fecha'],
                'horas' => $a['horas'] ?? 8,
            ]);
            $fila->created_at = Carbon::parse($a['fecha'])->setTime(9, 0);

            return $fila;
        }, $ausencias)));

        return $u;
    }

    private function liquidar(Usuario $u): array
    {
        return NominaLiquidador::liquidar($u, $this->inicio, $this->fin, $this->hoy);
    }

    public function test_un_dia_de_incapacidad_solo_descuenta_el_auxilio(): void
    {
        $l = $this->liquidar($this->trabajador(8303, [
            ['fecha' => '2026-09-03', 'tipo' => 'incapacidad'],
        ]));

        $this->assertSame(900000.0, $l['subtotal']);                 // 15 días × 60.000
        $this->assertSame(8303.0,   $l['valor_auxilio_dia']);
        $this->assertSame(8303.0,   $l['descuento_incapacidad']);
        $this->assertSame(0.0,      $l['descuento_faltas']);
        $this->assertSame(124545.0, $l['auxilio_transporte']);       // 8.303 × 15 días, se SUMA
        $this->assertSame(1016242.0, $l['total']);                   // 900.000 + 124.545 − 8.303
        $this->assertCount(1, $l['incapacidades']);
        $this->assertSame(8303.0, $l['incapacidades'][0]['monto']);
    }

    public function test_dos_dias_de_incapacidad_es_el_auxilio_por_dos(): void
    {
        $l = $this->liquidar($this->trabajador(8303, [
            ['fecha' => '2026-09-03', 'tipo' => 'incapacidad'],
            ['fecha' => '2026-09-04', 'tipo' => 'incapacidad'],
        ]));

        $this->assertSame(16606.0,  $l['descuento_incapacidad']);
        $this->assertSame(1007939.0, $l['total']);                   // 900.000 + 124.545 − 16.606
    }

    public function test_falta_e_incapacidad_se_cuentan_por_separado(): void
    {
        $l = $this->liquidar($this->trabajador(8303, [
            ['fecha' => '2026-09-03', 'tipo' => 'incapacidad'],
            ['fecha' => '2026-09-05', 'tipo' => 'falta', 'horas' => 8],
        ]));

        $this->assertSame(60000.0, $l['descuento_faltas']);          // 8h × 7.500
        $this->assertSame(8303.0,  $l['descuento_incapacidad']);
        $this->assertSame(956242.0, $l['total']);                    // 900.000 + 124.545 − 60.000 − 8.303
        $this->assertCount(1, $l['faltas']);
        $this->assertCount(1, $l['incapacidades']);
    }

    public function test_sueldo_sin_auxilio_no_descuenta_nada_por_incapacidad(): void
    {
        $l = $this->liquidar($this->trabajador(0, [
            ['fecha' => '2026-09-03', 'tipo' => 'incapacidad'],
            ['fecha' => '2026-09-04', 'tipo' => 'incapacidad'],
        ]));

        $this->assertSame(0.0,      $l['descuento_incapacidad']);
        $this->assertSame(0.0,      $l['auxilio_transporte']);
        $this->assertSame(900000.0, $l['total']);                    // el día se paga completo, y sin auxilio no hay nada que sumar
    }
}
