<?php

namespace Tests\Unit;

use App\Support\FestivosColombia;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Los 15 días hábiles de la Ley 1480 para responder una garantía dependen de
 * esto: un festivo mal calculado da por vencido un reclamo que está a tiempo.
 */
class FestivosColombiaTest extends TestCase
{
    public function test_los_festivos_de_2026(): void
    {
        $f = array_keys(FestivosColombia::delAnio(2026));

        foreach ([
            '2026-01-01', '2026-01-12', // Reyes, corrido al lunes
            '2026-03-23',               // San José, corrido al lunes
            '2026-04-02', '2026-04-03', // Jueves y Viernes Santo
            '2026-05-18', '2026-06-08', '2026-06-15', // Ascensión, Corpus, Sagrado Corazón
            '2026-07-20', '2026-08-07',
            '2026-10-12', '2026-11-02', '2026-11-16', // Raza, Todos los Santos, Cartagena
            '2026-12-08', '2026-12-25',
        ] as $dia) {
            $this->assertContains($dia, $f, "$dia debería ser festivo");
        }
        $this->assertCount(18, $f);
    }

    public function test_quince_dias_habiles_saltan_fines_de_semana_y_festivos(): void
    {
        // Reclamo el viernes 9 de octubre de 2026: no cuenta ese día, ni los
        // sábados, ni el 12 de octubre ni el 2 de noviembre.
        $this->assertSame('2026-11-03', FestivosColombia::sumarHabiles(Carbon::parse('2026-10-09'), 15)->toDateString());
        $this->assertFalse(FestivosColombia::esHabil(Carbon::parse('2026-10-10'))); // sábado
        $this->assertTrue(FestivosColombia::esHabil(Carbon::parse('2026-10-13')));
    }
}
