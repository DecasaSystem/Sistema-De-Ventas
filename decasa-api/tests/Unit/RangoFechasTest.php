<?php

namespace Tests\Unit;

use App\Services\RangoFechas;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * El filtro de tiempo de los reportes.
 *
 * Se prueba acá porque el bug que lo motivó no se veía: Reportes no entendía
 * `periodo` —solo `desde`/`hasta`— así que sus apartados devolvían siempre los
 * últimos 30 días tocara uno el botón que tocara. El número no salía mal, salía
 * plausible.
 */
class RangoFechasTest extends TestCase
{
    private function rango(array $query): array
    {
        return RangoFechas::de(new Request($query));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Un miércoles de mediados de mes, para que semana y mes no coincidan
        // con los bordes y una prueba en verde signifique algo.
        Carbon::setTestNow(Carbon::parse('2026-08-19 15:00:00', RangoFechas::TZ_NEGOCIO));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cada_boton_del_filtro_da_su_rango(): void
    {
        $this->assertSame(['2026-08-19', '2026-08-19'], $this->rango(['periodo' => 'hoy']));
        $this->assertSame(['2026-08-17', '2026-08-19'], $this->rango(['periodo' => 'semana']));
        $this->assertSame(['2026-08-01', '2026-08-19'], $this->rango(['periodo' => 'mes']));
        $this->assertSame(['2026-07-01', '2026-07-31'], $this->rango(['periodo' => 'mes_anterior']));
        $this->assertSame(['2026-01-01', '2026-08-19'], $this->rango(['periodo' => 'anio']));
    }

    public function test_un_rango_escrito_a_mano_manda(): void
    {
        $this->assertSame(
            ['2026-03-05', '2026-04-10'],
            $this->rango(['desde' => '2026-03-05', 'hasta' => '2026-04-10'])
        );
    }

    public function test_sin_filtro_es_el_mes_en_curso(): void
    {
        // Es lo que la pantalla trae seleccionado al abrirse: si el default
        // fuera otro, lo que se ve no cuadraría con el botón marcado.
        $this->assertSame(['2026-08-01', '2026-08-19'], $this->rango([]));
    }

    public function test_el_mes_anterior_de_un_31_no_se_salta_febrero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 10:00:00', RangoFechas::TZ_NEGOCIO));

        // Restar un mes al 31 de marzo cae en marzo otra vez si no se cuida el
        // desbordamiento, y "mes anterior" devolvía marzo desde marzo.
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->rango(['periodo' => 'mes_anterior']));
    }
}
