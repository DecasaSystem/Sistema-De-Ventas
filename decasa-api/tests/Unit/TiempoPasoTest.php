<?php

namespace Tests\Unit;

use App\Http\Controllers\ProduccionController;
use App\Models\PasoTrabajador;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cuánto se demoró un paso, escrito en horas o en días.
 *
 * El taller cuenta por días ("se demoró tres días") pero el dato se guarda en
 * horas, que es lo que se compara entre un trabajador y otro. La conversión se
 * hace en el servidor a propósito: así no depende de la versión de la app que
 * tenga abierta quien cierra el paso.
 */
class TiempoPasoTest extends TestCase
{
    /** `horasDe` es privado: lo que importa es el número que termina guardado. */
    private function horas(array $trabajador): ?float
    {
        $metodo = new ReflectionMethod(ProduccionController::class, 'horasDe');
        $metodo->setAccessible(true);

        return $metodo->invoke(new ProduccionController(), $trabajador);
    }

    public function test_los_dias_se_guardan_en_horas(): void
    {
        $this->assertSame(24.0, $this->horas(['tiempo' => 3, 'unidad' => 'dia']));
        $this->assertSame(4.0,  $this->horas(['tiempo' => 0.5, 'unidad' => 'dia']));
    }

    public function test_las_horas_se_guardan_tal_cual(): void
    {
        $this->assertSame(6.0, $this->horas(['tiempo' => 6, 'unidad' => 'hora']));
        // Sin unidad son horas: es lo que manda la app abierta desde antes.
        $this->assertSame(6.0, $this->horas(['tiempo' => 6]));
    }

    /** Una pantalla vieja sigue mandando `horas` y no puede quedarse sin cerrar. */
    public function test_la_forma_vieja_sigue_sirviendo(): void
    {
        $this->assertSame(6.0, $this->horas(['horas' => 6]));
    }

    public function test_sin_tiempo_no_se_inventa_ninguno(): void
    {
        $this->assertNull($this->horas([]));
        $this->assertNull($this->horas(['tiempo' => null]));
        $this->assertNull($this->horas(['tiempo' => '']));
    }

    /**
     * Un paso de más de un mes de trabajo de una sola persona es un dedo que se
     * resbaló: casi siempre, días escritos en la casilla de horas.
     */
    public function test_un_tiempo_absurdo_no_pasa(): void
    {
        $this->expectException(HttpException::class);
        $this->horas(['tiempo' => 70, 'unidad' => 'dia']);   // 560 horas
    }

    public function test_el_dia_de_taller_vale_ocho_horas(): void
    {
        $this->assertSame(8, PasoTrabajador::HORAS_POR_DIA);
        $this->assertSame(16.0, PasoTrabajador::aHoras(2, 'dia'));
        $this->assertSame(2.0,  PasoTrabajador::aHoras(2, 'hora'));
    }
}
