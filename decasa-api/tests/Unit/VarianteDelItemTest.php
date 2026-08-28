<?php

namespace Tests\Unit;

use App\Models\OrdenItem;
use Tests\TestCase;

/**
 * Qué variante se vendió.
 *
 * El bug que motivó esto: al vender una CAMA MIAMI el vendedor elegía "1.60",
 * lo veía en el carrito y ahí moría. Ni la orden ni el PDF podían decir cuál
 * era, porque el texto nunca salía de la pantalla: de 149 ítems vendidos,
 * ninguno tenía la medida guardada.
 */
class VarianteDelItemTest extends TestCase
{
    public function test_lo_elegido_es_lo_que_se_muestra(): void
    {
        $item = new OrdenItem(['variante_detalle' => '1.60']);

        $this->assertSame('1.60', $item->variante_texto);
    }

    /** Dos variantes a la vez (medida y color) no caben en la casilla del id. */
    public function test_varias_opciones_caben_porque_es_texto(): void
    {
        $item = new OrdenItem(['variante_detalle' => '1.60 · Natural']);

        $this->assertSame('1.60 · Natural', $item->variante_texto);
    }

    /** Un ítem sin variante no inventa una. */
    public function test_sin_variante_no_dice_nada(): void
    {
        $this->assertNull((new OrdenItem())->variante_texto);
        $this->assertNull((new OrdenItem(['variante_detalle' => '']))->variante_texto);
    }

    public function test_el_texto_que_manda_la_pantalla_se_respeta(): void
    {
        $this->assertSame('1.40', OrdenItem::detalleDeVariante('1.40'));
        $this->assertSame('1.40', OrdenItem::detalleDeVariante('  1.40  '));
    }

    public function test_sin_nada_que_guardar_queda_en_nulo(): void
    {
        $this->assertNull(OrdenItem::detalleDeVariante(null));
        $this->assertNull(OrdenItem::detalleDeVariante(''));
    }

    /** La columna es de 200: un texto largo se recorta en vez de reventar. */
    public function test_un_texto_larguisimo_no_revienta_la_columna(): void
    {
        $largo = str_repeat('A', 300);

        $this->assertSame(200, mb_strlen(OrdenItem::detalleDeVariante($largo)));
    }
}
