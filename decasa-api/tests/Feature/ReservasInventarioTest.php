<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Reservado" en la tarjeta de inventario es un contador (`cantidad_reservada`)
 * que no dice de quién es cada unidad. Este endpoint reconstruye la lista:
 * qué orden tiene apartada cada unidad, de qué tienda, para qué cliente y de
 * qué vendedor.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReservasInventarioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('producto_unico')->default(false);
            $t->date('devuelto_en')->nullable();
            $t->timestamps();
        });

        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        // Lo apartado de una tela o medida puntual lleva su propio contador.
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id');
            $t->string('marca')->nullable(); $t->string('marca_tela')->nullable();
            $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Norte'],
            ['id' => 2, 'nombre' => 'Sur'],
        ]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa']);
    }

    private function usuario(array $extra = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre' => 'Sup', 'email' => 'sup' . rand() . '@d.com', 'password' => 'x',
            'rol' => 'supervisor', 'created_at' => now(),
        ], $extra));
    }

    private function ordenConItem(array $ordenExtra = [], array $itemExtra = []): OrdenItem
    {
        $orden = Orden::create(array_merge([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => 'pendiente_anticipo', 'valor_total' => 100000,
        ], $ordenExtra));

        return OrdenItem::create(array_merge([
            'orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1,
            'precio_unitario' => 100000, 'es_personalizado' => false, 'producto_unico' => false,
        ], $itemExtra));
    }

    public function test_lista_las_ordenes_que_tienen_reservado_el_producto(): void
    {
        $vendedor = $this->usuario(['nombre' => 'Vendedor Uno', 'rol' => 'vendedor']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente Uno', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['vendedor_id' => $vendedor->id, 'numero_orden' => 4325]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $data = $r->json('ordenes');
        $this->assertCount(1, $data);
        $this->assertSame('#4325', $data[0]['orden_referencia']);
        $this->assertSame('Norte', $data[0]['tienda_nombre']);
        $this->assertSame('Vendedor Uno', $data[0]['vendedor_nombre']);
        $this->assertSame('Cliente Uno', $data[0]['cliente_nombre']);
        $this->assertSame(1, $data[0]['cantidad']);
    }

    public function test_no_cuenta_las_ordenes_ya_cerradas(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['estado' => 'entregado']);
        $this->ordenConItem(['estado' => 'cancelado']);
        $this->ordenConItem(['estado' => 'devuelto']);
        $this->ordenConItem(['estado' => 'en_produccion']); // esta sí sigue reservada

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertCount(1, $r->json('ordenes'));
    }

    public function test_no_cuenta_items_personalizados_ni_muebles_unicos(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem([], ['es_personalizado' => true]);
        $this->ordenConItem([], ['producto_unico' => true]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertCount(0, $r->json('ordenes'));
    }

    public function test_la_tienda_es_la_de_origen_cuando_viaja_de_otra_sede(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        // La orden es de la tienda 1, pero el producto salió reservado de la 2.
        $this->ordenConItem(['tienda_id' => 1], ['tienda_origen_id' => 2]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertSame('Sur', $r->json('ordenes')[0]['tienda_nombre']);
    }

    public function test_filtra_por_tienda_cuando_se_pide(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['tienda_id' => 1]);
        $this->ordenConItem(['tienda_id' => 2]);

        $sup = $this->usuario();

        $r1 = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=1')->assertOk();
        $this->assertCount(1, $r1->json('ordenes'));
        $this->assertSame('Norte', $r1->json('ordenes')[0]['tienda_nombre']);

        $r2 = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=2')->assertOk();
        $this->assertCount(1, $r2->json('ordenes'));
        $this->assertSame('Sur', $r2->json('ordenes')[0]['tienda_nombre']);

        $rTodas = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=todas')->assertOk();
        $this->assertCount(2, $rTodas->json('ordenes'));
    }

    public function test_un_vendedor_solo_ve_su_propia_tienda_aunque_pida_otra(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['tienda_id' => 1]);
        $this->ordenConItem(['tienda_id' => 2]);

        $vendedor = $this->usuario(['rol' => 'vendedor', 'tienda_default_id' => 1]);

        // Aunque pida explícitamente la tienda 2 (o "todas"), solo ve la suya.
        $r = $this->actingAs($vendedor)->getJson('/api/inventario/5/reservas?tienda_id=2')->assertOk();
        $this->assertCount(1, $r->json('ordenes'));
        $this->assertSame('Norte', $r->json('ordenes')[0]['tienda_nombre']);

        $rTodas = $this->actingAs($vendedor)->getJson('/api/inventario/5/reservas?tienda_id=todas')->assertOk();
        $this->assertCount(1, $rTodas->json('ordenes'));
    }

    /**
     * El caso que la gente reportaba como "dice que hay una cama apartada y al
     * entrar dice que no hay nada": el vendedor miraba el apartado de OTRA
     * tienda. El detalle sigue siendo solo el suyo —no ve las órdenes de otra
     * sede—, pero el número tiene que ser el de la tienda que preguntó, que es
     * el mismo que la tarjeta ya le muestra.
     */
    public function test_el_contador_es_el_de_la_tienda_que_se_pregunta_aunque_el_detalle_no(): void
    {
        DB::table('inventario')->insert([
            ['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0],
            ['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 3, 'cantidad_reservada' => 2],
        ]);

        $vendedor = $this->usuario(['rol' => 'vendedor', 'tienda_default_id' => 1]);

        $r = $this->actingAs($vendedor)->getJson('/api/inventario/5/reservas?tienda_id=2')->assertOk();

        $this->assertSame(2, $r->json('reservado_actual'), 'el número es el de la tienda que se preguntó');
        $this->assertCount(0, $r->json('ordenes'), 'las órdenes de otra sede no se ven');
        $this->assertTrue($r->json('detalle_limitado'), 'y la pantalla tiene cómo decirlo');
    }

    public function test_dice_en_que_tienda_esta_lo_apartado(): void
    {
        DB::table('inventario')->insert([
            ['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0],
            ['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 3, 'cantidad_reservada' => 2],
        ]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertSame([['tienda_id' => 2, 'tienda_nombre' => 'Sur', 'reservado' => 2]], $r->json('por_tienda'));
        $this->assertFalse($r->json('detalle_limitado'));
    }

    /**
     * Un borrador es un boceto de venta: no aparta nada hasta que se confirma
     * (OrdenController::store no reserva si viene como borrador). Contarlo
     * aquí inventaba una reserva que el inventario no tiene.
     */
    public function test_un_borrador_no_cuenta_como_apartado(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['estado' => 'borrador']);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertCount(0, $r->json('ordenes'));
    }

    /** Igual una cotización: solo toca inventario al convertirse en venta. */
    public function test_una_cotizacion_tampoco(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['estado' => 'cotizacion']);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertCount(0, $r->json('ordenes'));
    }

    /**
     * Lo apartado de un tapizado lleva su propio contador. Un fantasma puede
     * estar solo ahí, y mirando únicamente `inventario` la pantalla decía "no
     * hay nada apartado" con el desglose de tapizados diciendo lo contrario.
     */
    public function test_suma_aparte_lo_apartado_de_los_tapizados(): void
    {
        DB::table('producto_variantes')->insert(['id' => 9, 'producto_id' => 5, 'marca' => 'Tela A']);
        DB::table('inventario_variantes')->insert([
            'variante_id' => 9, 'tienda_id' => 1, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1,
        ]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=1')->assertOk();

        $this->assertSame(0, $r->json('reservado_actual'));
        $this->assertSame(1, $r->json('reservado_variantes'));
    }

    public function test_un_item_devuelto_para_cambiarlo_no_cuenta_aunque_la_orden_reabra(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        // El supervisor reabre una orden entregada para cambiar el producto
        // (OrdenController::cambiarProducto): el ítem queda `devuelto_en` y la
        // orden vuelve a `pendiente_anticipo` — pero ese ítem ya se liberó al
        // entregarse la primera vez, no hay que volver a contarlo.
        $item = $this->ordenConItem(['estado' => 'pendiente_anticipo'], ['devuelto_en' => now()->toDateString()]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertCount(0, $r->json('ordenes'));
    }

    public function test_devuelve_el_contador_fresco_de_reservado(): void
    {
        // El caso que confundía: la tarjeta del front decía "1 reservado"
        // (cacheado) pero el borrador que lo tenía ya se liberó. El endpoint
        // devuelve el número REAL de ahora — 0 — para que el front no grite
        // "descuadre" contra un valor viejo.
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=1')->assertOk();

        $this->assertCount(0, $r->json('ordenes'));
        $this->assertSame(0, $r->json('reservado_actual'));
    }

    public function test_el_contador_fresco_suma_todas_las_tiendas_sin_filtro(): void
    {
        DB::table('inventario')->insert([
            ['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 0, 'cantidad_reservada' => 2],
            ['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 0, 'cantidad_reservada' => 1],
        ]);

        $sup = $this->usuario();

        $todas = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();
        $this->assertSame(3, $todas->json('reservado_actual'));

        $unaTienda = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=1')->assertOk();
        $this->assertSame(2, $unaTienda->json('reservado_actual'));
    }
}
