<?php

namespace Tests\Feature;

use App\Models\Devolucion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Http\Controllers\ComisionController;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El cliente ya recibió el mueble y después dice que no le sirvió.
 *
 * No se dañó (eso es garantía) ni lo devolvió en la puerta (eso lo registra
 * el conductor): días después la señora Pérez llama porque el sofá Roma no le
 * cupo en la sala, y lo cambia por otra cosa. Desde el detalle de la orden se
 * registra todo de una vez: lo que devuelve y lo que se lleva, que puede ser de
 * catálogo (de una tienda o para fabricar) o un diseño especial.
 *
 * Lo que duele si queda mal es la plata: si lo nuevo vale más, la venta tiene
 * que subir y el cliente pagar la diferencia; si vale menos, le queda a favor;
 * y lo que ya pagó no se puede perder ni cobrar dos veces.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CambioProductoConReemplazoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-21 15:00:00', 'UTC'));

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('acceso_despacho')->default(false); $t->boolean('ve_todas_ordenes')->default(true);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('es_independientes')->default(false); $t->boolean('comisiones_compartidas')->default(false); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->string('direccion')->nullable(); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('precio_base', 12, 2)->default(0); $t->boolean('activo')->default(true);
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('entregado');
            $t->timestamp('listo_entrega_at')->nullable();
            $t->decimal('valor_total', 12, 2)->default(0); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable(); $t->string('canal')->nullable();
            $t->boolean('es_compartida')->default(false); $t->string('direccion_envio')->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('es_regalo')->default(false);
            $t->boolean('fabricar_pedido')->default(false); $t->boolean('usa_stock_tienda')->default(false);
            $t->json('specs_personalizacion')->nullable(); $t->string('boceto_url')->nullable(); $t->json('boceto_fotos')->nullable();
            $t->date('fecha_entrega_prom')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->string('destino')->default('orden');
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('configuracion', function (Blueprint $t) { $t->string('clave')->primary(); $t->text('valor'); });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('orden_mensajes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->text('mensaje'); $t->string('imagen_url')->nullable(); $t->json('mencionados')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('garantias', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('orden_item_id');
            $t->unsignedInteger('cantidad')->default(1); $t->string('tipo_dano')->nullable();
            $t->text('motivo')->nullable(); $t->string('estado')->default('pendiente'); $t->timestamps();
        });

        $this->completarEsquemaDeEntregas();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El caso ──────────────────────────────────────────────────────────────

    private function jefa(): Usuario
    {
        return Usuario::create(['nombre' => 'Manuela', 'email' => 'm@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    /**
     * Sofá Roma ($1.000.000, de Norte) y dos mesas de noche ($300.000 c/u),
     * entregados y pagados completos: $1.600.000.
     *
     * Inventario: sofá Roma 0 en Norte; mesas 3 en Norte; sofá Milán 1 en
     * El Edén (y ninguno en Norte); silla 4 en Norte.
     *
     * @return array{0: Orden, 1: OrdenItem, 2: OrdenItem}
     */
    private function ordenEntregada(string $estado = 'entregado'): array
    {
        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Decasa Norte', 'es_fabrica' => false], ['id' => 2, 'nombre' => 'Decasa El Edén', 'es_fabrica' => false], ['id' => 3, 'nombre' => 'Fábrica', 'es_fabrica' => true]]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert([
            ['id' => 6,  'nombre' => 'Silla',         'precio_base' => 200000],
            ['id' => 7,  'nombre' => 'Mesa de noche', 'precio_base' => 300000],
            ['id' => 8,  'nombre' => 'Sofá Milán',    'precio_base' => 1500000],
            ['id' => 10, 'nombre' => 'Sofá Roma',     'precio_base' => 1000000],
        ]);
        DB::table('inventario')->insert([
            ['producto_id' => 10, 'tienda_id' => 1, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0],
            ['producto_id' => 7,  'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0],
            ['producto_id' => 8,  'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0],
            ['producto_id' => 6,  'tienda_id' => 1, 'cantidad_disponible' => 4, 'cantidad_reservada' => 0],
        ]);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 99, 'estado' => $estado,
                                'valor_total' => 1600000, 'serie' => 'FV', 'serie_numero' => 1300]);

        $sofa  = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 10, 'cantidad' => 1,
                                    'cantidad_entregada' => 1, 'precio_unitario' => 1000000]);
        $mesas = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 7, 'cantidad' => 2,
                                    'cantidad_entregada' => 2, 'precio_unitario' => 300000]);

        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 1600000, 'metodo' => 'efectivo',
                                    'tipo' => 'anticipo', 'created_at' => now()]);

        return [$orden, $sofa, $mesas];
    }

    private function cambiar(Usuario $quien, Orden $orden, array $datos): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($quien)->postJson("/api/ordenes/{$orden->id}/cambiar-producto", $datos);
    }

    private function inv(int $producto, int $tienda): array
    {
        $f = DB::table('inventario')->where('producto_id', $producto)->where('tienda_id', $tienda)->first();

        return [(int) $f->cantidad_disponible, (int) $f->cantidad_reservada];
    }

    // ── Del catálogo, de una tienda ──────────────────────────────────────────

    public function test_si_lo_nuevo_vale_mas_la_venta_sube_y_paga_la_diferencia(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $r = $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id,
            'motivo'        => 'No le cupo en la sala',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertOk();

        // La venta pasa de $1.600.000 a $2.100.000: debe solo la diferencia.
        $this->assertEquals(2100000, $r->json('valor_total'));
        $this->assertEquals(1600000, $r->json('total_pagado'));
        $this->assertEquals(500000, $r->json('saldo_pendiente'));
        $this->assertEquals(500000, $r->json('diferencia'));

        // El sofá Milán sale de El Edén, que es donde está, apartado para ella.
        $nuevo = OrdenItem::find($r->json('orden_item_nuevo_id'));
        $this->assertSame(8, (int) $nuevo->producto_id);
        $this->assertSame(2, (int) $nuevo->tienda_origen_id);
        $this->assertFalse($nuevo->es_personalizado);
        $this->assertSame([1, 1], $this->inv(8, 2));

        // El que devolvió estaba bueno: vuelve a la venta en Norte.
        $this->assertSame([1, 0], $this->inv(10, 1));

        // Queda lista para llevarle lo nuevo.
        $this->assertSame('listo_entrega', $orden->fresh()->estado);
    }

    public function test_si_lo_nuevo_vale_menos_le_queda_a_favor(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $r = $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id,
            'motivo'        => 'Prefiere una mesa de noche más',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 7, 'tienda_id' => 1,
                                'cantidad' => 1, 'precio_unitario' => 300000],
        ])->assertOk();

        $this->assertEquals(900000, $r->json('valor_total'));
        $this->assertEquals(-700000, $r->json('saldo_pendiente'));
        $this->assertEquals(-700000, $r->json('diferencia'));
        // Lo nuevo sale de la misma tienda de la orden: no lleva tienda de origen aparte.
        $this->assertNull(OrdenItem::find($r->json('orden_item_nuevo_id'))->tienda_origen_id);
        $this->assertSame([3, 1], $this->inv(7, 1));
    }

    public function test_lo_devuelto_queda_como_rastro_y_escrito_en_la_orden(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo en la sala',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertOk();

        // No se borra: la orden cuenta qué se vendió, qué se cambió y por qué.
        $sofa->refresh();
        $this->assertNotNull($sofa->devuelto_en);
        $this->assertSame('No le cupo en la sala', $sofa->motivo_devolucion);
        $this->assertSame(3, $orden->items()->count());

        $dev = Devolucion::first();
        $this->assertSame('cambio', $dev->estado);
        $this->assertStringContainsString('Sofá Milán', $dev->notas_decision);

        $msg = DB::table('orden_mensajes')->where('orden_id', $orden->id)->value('mensaje');
        $this->assertStringContainsString('Sofá Roma', $msg);
        $this->assertStringContainsString('Sofá Milán', $msg);
        $this->assertStringContainsString('sube $500.000', $msg);
    }

    public function test_la_comision_sigue_al_valor_nuevo(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        DB::table('comisiones')->insert([
            'orden_id' => $orden->id, 'vendedor_id' => 99, 'tienda_id' => 1, 'origen' => 'venta',
            'mes_venta' => '2026-10', 'valor_orden' => 1600000, 'fecha_venta' => '2026-10-01',
            'fecha_disponible' => '2026-11-20', 'estado' => 'pendiente',
        ]);

        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertOk();

        $this->assertEquals(
            ComisionController::valorComisionable($orden->fresh()),
            (float) DB::table('comisiones')->where('orden_id', $orden->id)->value('valor_orden'),
        );
        $this->assertNotEquals(1600000, (float) DB::table('comisiones')->value('valor_orden'));
    }

    public function test_sin_stock_en_esa_tienda_no_se_inventa_el_cambio(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        // En Norte no hay sofá Milán.
        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 1,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertStatus(422)->assertJsonFragment(['message' =>
            'En Decasa Norte no hay suficientes libres de "Sofá Milán": hay 0 y se necesitan 1. Escoge otra tienda o mándalo a fabricar.']);

        // Y no quedó nada a medias: el sofá sigue siendo de ella y la orden igual.
        $this->assertNull($sofa->fresh()->devuelto_en);
        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertEquals(1600000, (float) $orden->fresh()->valor_total);
        $this->assertSame([0, 0], $this->inv(10, 1));
        $this->assertSame(0, Devolucion::count());
    }

    // ── Del catálogo, para fabricar ──────────────────────────────────────────

    public function test_del_catalogo_para_fabricar_entra_a_produccion_sin_apartar_stock(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        $taller = Usuario::create(['nombre' => 'Don Jairo', 'email' => 'j@d.com', 'password' => 'x',
                                   'rol' => 'taller', 'gestiona_produccion' => true, 'created_at' => now()]);

        $r = $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'Lo quiere en otro color',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'fabricar' => true,
                                'cantidad' => 1, 'precio_unitario' => 1500000,
                                'fecha_entrega_prom' => '2026-11-20'],
        ])->assertOk();

        $nuevo = OrdenItem::find($r->json('orden_item_nuevo_id'));
        $this->assertTrue($nuevo->es_personalizado);
        $this->assertTrue($nuevo->fabricar_pedido);
        $this->assertSame('fabricar', $nuevo->tipo_item);

        $prod = Produccion::where('orden_item_id', $nuevo->id)->first();
        $this->assertNotNull($prod);
        $this->assertSame('pendiente', $prod->estado);
        $this->assertSame('2026-11-20', \Carbon\Carbon::parse($prod->fecha_compromiso)->toDateString());

        // No toca el sofá Milán de El Edén: se fabrica uno nuevo.
        $this->assertSame([1, 0], $this->inv(8, 2));
        $this->assertSame('en_produccion', $orden->fresh()->estado);
        $this->assertEquals(500000, $r->json('saldo_pendiente'));

        // Al taller le llega el aviso.
        $this->assertTrue(DB::table('notificaciones')->where('usuario_id', $taller->id)->exists());
    }

    // ── Diseño especial ──────────────────────────────────────────────────────

    public function test_un_diseno_especial_entra_a_produccion_con_su_ficha(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $r = $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo, quiere uno a la medida',
            'reemplazo'     => [
                'tipo' => 'diseno_especial', 'nombre' => 'Sofá en L a la medida', 'cantidad' => 1,
                'precio_unitario' => 2400000, 'ancho' => '2,40 m', 'largo' => '1,80 m', 'alto' => '0,90 m',
                'tela' => 'Lino gris', 'madera' => 'Pino', 'detalles' => 'Brazo recto, patas negras',
                'boceto_urls' => ['https://res.cloudinary.com/x/a.jpg', 'https://res.cloudinary.com/x/b.jpg'],
            ],
        ])->assertOk();

        $nuevo = OrdenItem::find($r->json('orden_item_nuevo_id'));
        $this->assertNull($nuevo->producto_id);
        $this->assertSame('diseno_especial', $nuevo->tipo_item);
        $this->assertSame('Sofá en L a la medida', $nuevo->nombre_custom);
        $this->assertSame('2,40 m × 1,80 m × 0,90 m', $nuevo->specs_personalizacion['medidas']);
        $this->assertSame('Sofá en L a la medida', $nuevo->ficha_especial['tipo_mueble']);
        $this->assertSame('Lino gris', $nuevo->ficha_especial['tela']);
        $this->assertSame('https://res.cloudinary.com/x/a.jpg', $nuevo->boceto_url);
        $this->assertCount(2, $nuevo->boceto_fotos);

        $this->assertTrue(Produccion::where('orden_item_id', $nuevo->id)->exists());
        $this->assertSame('en_produccion', $orden->fresh()->estado);
        // $600.000 de mesas + $2.400.000 del sofá en L.
        $this->assertEquals(3000000, $r->json('valor_total'));
        $this->assertEquals(1400000, $r->json('saldo_pendiente'));
    }

    public function test_un_diseno_especial_sin_precio_no_se_deja(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        // Con $0 quedaría "esperando precio" y la orden en un limbo.
        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'Lo quiere a la medida',
            'reemplazo'     => ['tipo' => 'diseno_especial', 'nombre' => 'Sofá en L', 'cantidad' => 1, 'precio_unitario' => 0],
        ])->assertStatus(422);

        $this->assertNull($sofa->fresh()->devuelto_en);
    }

    // ── Cuántas y cuándo ─────────────────────────────────────────────────────

    public function test_puede_devolver_solo_una_de_dos(): void
    {
        [$orden, , $mesas] = $this->ordenEntregada();

        $r = $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $mesas->id, 'cantidad' => 1, 'motivo' => 'Solo necesitaba una',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 6, 'tienda_id' => 1,
                                'cantidad' => 1, 'precio_unitario' => 200000],
        ])->assertOk();

        // Se queda con una mesa (entregada) y la otra se cambia por una silla.
        $mesas->refresh();
        $this->assertNull($mesas->devuelto_en);
        $this->assertSame(1, (int) $mesas->cantidad);
        $this->assertSame(1, (int) $mesas->cantidad_entregada);
        $this->assertSame(1, (int) Devolucion::first()->cantidad);

        // $1.000.000 + $300.000 + $200.000.
        $this->assertEquals(1500000, $r->json('valor_total'));
        $this->assertEquals(-100000, $r->json('saldo_pendiente'));
        // La mesa vuelve a Norte y la silla queda apartada.
        $this->assertSame([4, 0], $this->inv(7, 1));
        $this->assertSame([4, 1], $this->inv(6, 1));
    }

    public function test_no_devuelve_mas_de_lo_que_tiene_en_la_casa(): void
    {
        [$orden, , $mesas] = $this->ordenEntregada();

        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $mesas->id, 'cantidad' => 3, 'motivo' => 'No las quiere',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 6, 'tienda_id' => 1,
                                'cantidad' => 1, 'precio_unitario' => 200000],
        ])->assertStatus(422);
    }

    public function test_en_una_entrega_parcial_solo_se_cambia_lo_entregado(): void
    {
        // Le llevaron el sofá, las mesas siguen en la tienda.
        [$orden, $sofa, $mesas] = $this->ordenEntregada('listo_entrega');
        $mesas->update(['cantidad_entregada' => 0]);

        // Lo que no se le ha entregado no se "devuelve": eso se edita.
        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $mesas->id, 'motivo' => 'Ya no las quiere',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 6, 'tienda_id' => 1,
                                'cantidad' => 1, 'precio_unitario' => 200000],
        ])->assertStatus(422);

        // Sin reemplazo no se puede: la orden no está entregada y reabrirla
        // la sacaría de "lista para entrega".
        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo',
        ])->assertStatus(422);

        // Con reemplazo sí, y la orden sigue lista para entrega.
        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertOk();
        $this->assertSame('listo_entrega', $orden->fresh()->estado);
    }

    public function test_lo_que_esta_en_garantia_no_se_cambia_a_la_vez(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        DB::table('garantias')->insert(['orden_id' => $orden->id, 'orden_item_id' => $sofa->id, 'cantidad' => 1,
                                        'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()]);

        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le gustó',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertStatus(422);
    }

    public function test_sin_reemplazo_se_reabre_como_antes(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $r = $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'Todavía no sabe qué quiere',
        ])->assertOk();

        $this->assertEquals(-1000000, $r->json('saldo_pendiente'));
        $this->assertSame('pendiente_anticipo', $orden->fresh()->estado);
    }

    public function test_lo_decide_un_supervisor(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        $vendedor = Usuario::create(['nombre' => 'Gladys', 'email' => 'g@d.com', 'password' => 'x',
                                     'rol' => 'vendedor', 'created_at' => now()]);

        $this->cambiar($vendedor, $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo',
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertStatus(403);
    }
    // ── Lo devuelto: a dónde va y cuándo se recoge ───────────────────────────

    /** Cambio estándar del sofá Roma por el Milán de El Edén, con lo devuelto como diga $devuelto. */
    private function cambiarSofa(Usuario $quien, Orden $orden, OrdenItem $sofa, array $devuelto): \Illuminate\Testing\TestResponse
    {
        return $this->cambiar($quien, $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'No le cupo en la sala',
            'devuelto'      => $devuelto,
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ]);
    }

    private function despachador(): Usuario
    {
        return Usuario::create(['nombre' => 'Fabio', 'email' => 'f@d.com', 'password' => 'x',
                                'rol' => 'conductor', 'acceso_despacho' => true, 'created_at' => now()]);
    }

    public function test_ya_esta_aqui_entra_de_una_a_la_tienda_que_se_escoja(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        // Salió de Norte pero lo dejan en El Edén.
        $this->cambiarSofa($this->jefa(), $orden, $sofa, ['destino' => 'tienda', 'tienda_id' => 2, 'recogida' => 'ya'])
            ->assertOk()->assertJsonPath('por_recoger', false);

        $this->assertSame(1, (int) DB::table('inventario')->where('producto_id', 10)->where('tienda_id', 2)->value('cantidad_disponible'));
        $this->assertSame([0, 0], $this->inv(10, 1));

        $dev = Devolucion::first();
        $this->assertSame('tienda', $dev->destino);
        $this->assertSame(2, (int) $dev->tienda_destino_id);
        $this->assertNotNull($dev->recibido_at);
        $this->assertFalse($dev->porRecoger());
    }

    public function test_puede_ir_a_la_fabrica(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $this->cambiarSofa($this->jefa(), $orden, $sofa, ['destino' => 'tienda', 'tienda_id' => 3, 'recogida' => 'ya'])->assertOk();

        $this->assertSame(1, (int) DB::table('inventario')->where('producto_id', 10)->where('tienda_id', 3)->value('cantidad_disponible'));
        $this->assertStringContainsString('Volvió al inventario de Fábrica',
            DB::table('orden_mensajes')->where('orden_id', $orden->id)->value('mensaje'));
    }

    public function test_como_merma_no_vuelve_a_la_venta(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $this->cambiarSofa($this->jefa(), $orden, $sofa, ['destino' => 'merma', 'recogida' => 'ya'])->assertOk();

        $this->assertSame([0, 0], $this->inv(10, 1));
        $this->assertSame(0, DB::table('inventario_movimientos')->where('tipo', 'entrada')->count());
    }

    public function test_programada_la_orden_cambia_ya_pero_el_inventario_espera_a_que_llegue(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        $fabio = $this->despachador();

        $r = $this->cambiarSofa($this->jefa(), $orden, $sofa,
            ['destino' => 'tienda', 'tienda_id' => 1, 'recogida' => 'programada', 'fecha_recogida' => '2026-10-24'])
            ->assertOk()->assertJsonPath('por_recoger', true);

        // La orden ya cambió: el Milán está apartado y la venta subió.
        $this->assertEquals(2100000, $r->json('valor_total'));
        $this->assertNotNull($sofa->fresh()->devuelto_en);
        // Pero el Roma sigue en la casa de la señora: todavía no está a la venta.
        $this->assertSame([0, 0], $this->inv(10, 1));

        $dev = Devolucion::first();
        $this->assertTrue($dev->porRecoger());
        $this->assertSame('2026-10-24', $dev->recoger_el->toDateString());

        // A Despacho le llega el aviso, y le sale en la lista con dirección.
        $this->assertTrue(DB::table('notificaciones')->where('usuario_id', $fabio->id)->exists());
        DB::table('clientes')->where('id', 1)->update(['direccion' => 'Calle 10 # 5-20']);
        $this->actingAs($fabio)->getJson('/api/devoluciones/por-recoger')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.producto', 'Sofá Roma')
            ->assertJsonPath('0.tienda_destino', 'Decasa Norte')
            ->assertJsonPath('0.recoger_el', '2026-10-24')
            ->assertJsonPath('0.direccion', 'Calle 10 # 5-20');

        // Llegó: ahora sí entra al inventario.
        $this->actingAs($fabio)->postJson("/api/devoluciones/{$dev->id}/recibir")->assertOk()
            ->assertJsonPath('por_recoger', false);
        $this->assertSame([1, 0], $this->inv(10, 1));
        $this->actingAs($fabio)->getJson('/api/devoluciones/por-recoger')->assertJsonCount(0);

        // Y no se recibe dos veces.
        $this->actingAs($fabio)->postJson("/api/devoluciones/{$dev->id}/recibir")->assertStatus(422);
        $this->assertSame([1, 0], $this->inv(10, 1));
    }

    public function test_al_recibirlo_se_puede_cambiar_el_destino(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        $this->cambiarSofa($this->jefa(), $orden, $sofa,
            ['destino' => 'tienda', 'tienda_id' => 1, 'recogida' => 'programada', 'fecha_recogida' => '2026-10-24'])->assertOk();
        $dev = Devolucion::first();

        // Llegó rayado del trasteo: no vuelve a la venta.
        $this->actingAs($this->despachador())->postJson("/api/devoluciones/{$dev->id}/recibir", ['destino' => 'merma'])->assertOk();

        $this->assertSame([0, 0], $this->inv(10, 1));
        $this->assertSame('merma', $dev->fresh()->destino);
    }

    public function test_se_recoge_cuando_le_lleven_el_nuevo_y_se_puede_reprogramar(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        $this->cambiarSofa($this->jefa(), $orden, $sofa,
            ['destino' => 'tienda', 'tienda_id' => 3, 'recogida' => 'con_entrega'])->assertOk();

        $dev = Devolucion::first();
        $this->assertTrue($dev->recoger_con_entrega);
        $this->assertNull($dev->recoger_el);

        $this->actingAs($this->despachador())->patchJson("/api/devoluciones/{$dev->id}/recogida",
            ['recogida' => 'programada', 'fecha_recogida' => '2026-10-28'])->assertOk()
            ->assertJsonPath('recoger_el', '2026-10-28')
            ->assertJsonPath('recoger_con_entrega', false);
    }

    public function test_sin_producto_nuevo_no_se_puede_esperar_a_la_entrega(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $sofa->id, 'motivo' => 'Todavía no sabe',
            'devuelto'      => ['destino' => 'tienda', 'tienda_id' => 1, 'recogida' => 'con_entrega'],
        ])->assertStatus(422);
    }

    public function test_no_se_programa_para_un_dia_que_ya_paso(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();

        $this->cambiarSofa($this->jefa(), $orden, $sofa,
            ['destino' => 'tienda', 'tienda_id' => 1, 'recogida' => 'programada', 'fecha_recogida' => '2026-10-20'])
            ->assertStatus(422);
        $this->assertNull($sofa->fresh()->devuelto_en);
    }

    public function test_un_diseno_especial_devuelto_llega_a_la_fabrica_sin_inventario(): void
    {
        [$orden] = $this->ordenEntregada();
        $especial = OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Cama a la medida', 'cantidad' => 1,
                                       'cantidad_entregada' => 1, 'precio_unitario' => 2000000, 'es_personalizado' => true]);

        $this->cambiar($this->jefa(), $orden, [
            'orden_item_id' => $especial->id, 'motivo' => 'No le gustó',
            'devuelto'      => ['destino' => 'tienda', 'tienda_id' => 3, 'recogida' => 'ya'],
            'reemplazo'     => ['tipo' => 'catalogo', 'producto_id' => 8, 'tienda_id' => 2,
                                'cantidad' => 1, 'precio_unitario' => 1500000],
        ])->assertOk();

        // Queda dicho dónde está, pero no se inventa un producto de catálogo.
        $this->assertSame(0, DB::table('inventario_movimientos')->where('tipo', 'entrada')->count());
        $this->assertStringContainsString('Quedó en Fábrica',
            DB::table('orden_mensajes')->where('orden_id', $orden->id)->value('mensaje'));
    }

    public function test_un_vendedor_no_ve_ni_recibe_las_recogidas(): void
    {
        [$orden, $sofa] = $this->ordenEntregada();
        $this->cambiarSofa($this->jefa(), $orden, $sofa,
            ['destino' => 'tienda', 'tienda_id' => 1, 'recogida' => 'programada', 'fecha_recogida' => '2026-10-24'])->assertOk();
        $vendedor = Usuario::create(['nombre' => 'Gladys', 'email' => 'g@d.com', 'password' => 'x',
                                     'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($vendedor)->getJson('/api/devoluciones/por-recoger')->assertStatus(403);
        $this->actingAs($vendedor)->postJson('/api/devoluciones/' . Devolucion::first()->id . '/recibir')->assertStatus(403);
    }
    public function test_los_destinos_incluyen_la_fabrica_primero(): void
    {
        $this->ordenEntregada();

        $this->actingAs($this->jefa())->getJson('/api/devoluciones/destinos')->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.nombre', 'Fábrica')
            ->assertJsonPath('0.es_fabrica', true);
    }
}
