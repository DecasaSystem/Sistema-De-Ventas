<?php

namespace Tests\Unit;

use App\Services\RangoFechas;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * El servidor corre en UTC: de 7 p. m. a medianoche en Colombia, now() ya
 * está en el día siguiente. Lo que se hacía en la noche quedaba con fecha de
 * mañana (despacho, pieza lista, inicio de producción) y la ruta de hoy decía
 * "aún no puedes iniciarla" (revisado 2026-11-14).
 */
class HoyEnBogotaTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_las_8_de_la_noche_sigue_siendo_hoy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 01:00:00', 'UTC')); // 14 nov, 8 p. m. en Bogotá

        $this->assertSame('2026-11-14', RangoFechas::hoyTexto());
        $this->assertSame('2026-11-14 00:00:00', RangoFechas::hoy()->format('Y-m-d H:i:s'));
    }

    public function test_el_ultimo_dia_del_mes_en_la_noche_sigue_siendo_ese_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 03:00:00', 'UTC')); // 30 nov, 10 p. m.

        $this->assertSame('2026-11', RangoFechas::mesActual());
    }

    public function test_en_el_dia_da_lo_mismo_que_antes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 15:00:00', 'UTC'));

        $this->assertSame(now()->toDateString(), RangoFechas::hoyTexto());
    }
}
