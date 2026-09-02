<?php

namespace Tests\Feature;

use App\Models\Devolucion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cambiar un producto que ya se entregó.
 *
 * La señora recibió la mesa, la devolvió a los dos días y quiere otra que
 * cuesta más. Lo que ya pagó tiene que quedarle a favor: si esto falla, o se
 * le cobra dos veces o se le regala plata, y ninguna de las dos se nota hasta
 * que alguien cuadra la caja.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CambioProductoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false); $t->boolean('ve_todas_ordenes')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->decimal('descuento_total', 12, 2)->default(0);
            $t->decimal('descuento_condicionado', 12, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 8, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            // La tabla real siempre la tiene, y de ella sale si una orden es
            // restauracion: sin la columna, cualquier cosa que toque comisiones
            // revienta aqui y no en el codigo que se esta probando.
            $t->boolean('es_restauracion')->default(false);
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(false);
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable();
            $t->text('motivo_devolucion')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('tipo')->nullable();
            $t->decimal('monto', 12, 2); $t->string('metodo')->nullable(); $t->string('referencia')->nullable();
            $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('orden_mensajes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->text('mensaje'); $t->string('imagen_url')->nullable(); $t->json('mencionados')->nullable(); $t->timestamps();
        });
        Schema::create('devoluciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('orden_item_id');
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->unsignedInteger('cantidad')->default(1);
            $t->text('motivo'); $t->string('foto_url')->nullable(); $t->date('fecha');
            $t->unsignedBigInteger('reportado_por_id')->nullable(); $t->string('estado')->default('pendiente');
            $t->unsignedBigInteger('decidido_por_id')->nullable(); $t->timestamp('decidido_at')->nullable();
            $t->text('notas_decision')->nullable(); $t->decimal('monto_devuelto', 12, 2)->nullable();
            $t->unsignedBigInteger('caja_movimiento_id')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id'); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2); $t->date('fecha_venta'); $t->date('fecha_disponible');
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
    }

    private function jefe(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    /** Orden entregada: mesa de $800.000 y silla de $200.000, pagada completa. */
    private function ordenEntregada(): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert([
            ['id' => 5, 'nombre' => 'Mesa de comedor'],
            ['id' => 6, 'nombre' => 'Silla'],
        ]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 2, 'cantidad_reservada' => 0]);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'entregado', 'valor_total' => 1000000,
                                'numero_orden' => 4300]);

        $mesa  = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => 1,
                                    'cantidad' => 1, 'precio_unitario' => 800000]);
        $silla = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 6, 'tienda_origen_id' => 1,
                                    'cantidad' => 1, 'precio_unitario' => 200000]);

        // La señora ya pagó todo.
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 1000000, 'metodo' => 'efectivo',
                                    'tipo' => 'anticipo', 'created_at' => now(), 'updated_at' => now()]);

        return [$orden, $mesa, $silla];
    }

    public function test_lo_pagado_queda_a_favor_del_cliente(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $r = $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id,
            'motivo'        => 'No le gustó el color, quiere otra más grande',
        ])->assertOk();

        // La mesa deja de cobrarse: la orden pasa a valer solo la silla.
        $this->assertEquals(200000, $r->json('valor_total'));
        // Pero la plata que pagó sigue ahí, entera.
        $this->assertEquals(1000000, $r->json('total_pagado'));
        // Así que le quedan $800.000 a favor para el producto nuevo.
        $this->assertEquals(-800000, $r->json('saldo_pendiente'));
    }

    public function test_la_orden_vuelve_a_estar_abierta_y_editable(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'La quiere más grande',
        ])->assertOk();

        // 'pendiente_anticipo' es uno de los estados que sí deja editar, que es
        // como se le agrega el reemplazo.
        $this->assertSame('pendiente_anticipo', $orden->fresh()->estado);
    }

    public function test_el_producto_devuelto_queda_marcado_y_no_se_borra(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'No le gustó el color',
        ])->assertOk();

        $mesa->refresh();
        // Se queda en la orden: borrarlo dejaría diciendo que solo se vendió lo
        // nuevo, y lo que pasó de verdad es lo que hay que poder mirar después.
        $this->assertNotNull($mesa->devuelto_en);
        $this->assertSame('No le gustó el color', $mesa->motivo_devolucion);
        $this->assertSame(2, $orden->items()->count());

        // Y queda la devolución, ya resuelta: se cambia por otro producto.
        $dev = Devolucion::first();
        $this->assertSame('cambio', $dev->estado);
        $this->assertSame($mesa->id, $dev->orden_item_id);
    }

    public function test_lo_devuelto_vuelve_al_inventario(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'La quiere más grande',
        ])->assertOk();

        // Estaba buena, así que se puede volver a vender.
        $this->assertSame(3, (int) DB::table('inventario')->where('producto_id', 5)->value('cantidad_disponible'));
        $this->assertSame('entrada', DB::table('inventario_movimientos')->value('tipo'));
    }

    public function test_si_llego_danada_no_vuelve_al_inventario(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'Llegó con la pata partida',
            'vuelve_al_stock' => false,
        ])->assertOk();

        // Ponerla otra vez a la venta sería vender un producto roto.
        $this->assertSame(2, (int) DB::table('inventario')->where('producto_id', 5)->value('cantidad_disponible'));
    }

    public function test_se_puede_devolver_el_unico_producto_de_la_orden(): void
    {
        // Es el caso más común y el que motivó todo esto: una orden de un solo
        // mueble que el cliente cambia por otro. La orden queda un momento sin
        // nada y con toda la plata a favor, hasta que se le pone el reemplazo.
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa de comedor']);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0]);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'entregado', 'valor_total' => 800000, 'numero_orden' => 4301]);
        $mesa  = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => 1,
                                    'cantidad' => 1, 'precio_unitario' => 800000]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 800000, 'metodo' => 'efectivo',
                                    'created_at' => now(), 'updated_at' => now()]);

        $r = $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'La quiere más grande',
        ])->assertOk();

        // Toda la plata queda a favor para el mueble nuevo.
        $this->assertEquals(0, $r->json('valor_total'));
        $this->assertEquals(-800000, $r->json('saldo_pendiente'));
        // Y se avisa que quedó vacía, para que no se deje colgada.
        $this->assertTrue($r->json('quedo_vacia'));
        $this->assertSame('pendiente_anticipo', $orden->fresh()->estado);
    }

    public function test_solo_sirve_para_ordenes_entregadas(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();
        $orden->update(['estado' => 'en_produccion']);

        $this->actingAs($this->jefe())->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'Cambio',
        ])->assertStatus(422);
    }

    public function test_lo_decide_un_supervisor(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();
        $vendedor = Usuario::create(['nombre' => 'Vendedor', 'email' => 'v@d.com', 'password' => 'x',
                                     'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($vendedor)->postJson("/api/ordenes/{$orden->id}/cambiar-producto", [
            'orden_item_id' => $mesa->id, 'motivo' => 'Cambio',
        ])->assertStatus(403);
    }
}
