<?php

namespace Tests\Unit;

use App\Models\OrdenItem;
use PHPUnit\Framework\TestCase;

/**
 * Qué se le hace en la fábrica a un mueble de stock que se manda allá.
 *
 * "Modificación" es agregarle o cambiarle algo que no está roto: el comedor
 * de la tienda con cubierta de madera en vez de vidrio. Antes se metía como
 * "arreglo" y el taller lo leía como una reparación.
 */
class TrabajoFabricaTest extends TestCase
{
    public function test_la_modificacion_es_un_trabajo_propio(): void
    {
        $this->assertSame('modificacion', OrdenItem::trabajoFabrica(['trabajo' => 'modificacion']));
        $this->assertSame('Modificación', OrdenItem::TRABAJOS_FABRICA['modificacion']);
    }

    public function test_la_modificacion_pide_decir_que_se_le_cambia(): void
    {
        $this->assertNotNull(OrdenItem::faltaParaFabrica(['trabajo' => 'modificacion'], 'Comedor'));
        $this->assertNull(OrdenItem::faltaParaFabrica(
            ['trabajo' => 'modificacion', 'notas' => 'Cubierta de madera en vez de vidrio'], 'Comedor'
        ));
    }

    /** Lo que no se reconoce sigue siendo cambio de tela, como lo viejo. */
    public function test_un_trabajo_desconocido_sigue_siendo_tela(): void
    {
        $this->assertSame('tela', OrdenItem::trabajoFabrica(['trabajo' => 'otra-cosa']));
        $this->assertSame('tela', OrdenItem::trabajoFabrica(null));
    }
}
