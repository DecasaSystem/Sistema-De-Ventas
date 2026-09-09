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

        $data = $r->json();
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

        $this->assertCount(1, $r->json());
    }

    public function test_no_cuenta_items_personalizados_ni_muebles_unicos(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem([], ['es_personalizado' => true]);
        $this->ordenConItem([], ['producto_unico' => true]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertCount(0, $r->json());
    }

    public function test_la_tienda_es_la_de_origen_cuando_viaja_de_otra_sede(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        // La orden es de la tienda 1, pero el producto salió reservado de la 2.
        $this->ordenConItem(['tienda_id' => 1], ['tienda_origen_id' => 2]);

        $sup = $this->usuario();
        $r = $this->actingAs($sup)->getJson('/api/inventario/5/reservas')->assertOk();

        $this->assertSame('Sur', $r->json()[0]['tienda_nombre']);
    }

    public function test_filtra_por_tienda_cuando_se_pide(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['tienda_id' => 1]);
        $this->ordenConItem(['tienda_id' => 2]);

        $sup = $this->usuario();

        $r1 = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=1')->assertOk();
        $this->assertCount(1, $r1->json());
        $this->assertSame('Norte', $r1->json()[0]['tienda_nombre']);

        $r2 = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=2')->assertOk();
        $this->assertCount(1, $r2->json());
        $this->assertSame('Sur', $r2->json()[0]['tienda_nombre']);

        $rTodas = $this->actingAs($sup)->getJson('/api/inventario/5/reservas?tienda_id=todas')->assertOk();
        $this->assertCount(2, $rTodas->json());
    }

    public function test_un_vendedor_solo_ve_su_propia_tienda_aunque_pida_otra(): void
    {
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        $this->ordenConItem(['tienda_id' => 1]);
        $this->ordenConItem(['tienda_id' => 2]);

        $vendedor = $this->usuario(['rol' => 'vendedor', 'tienda_default_id' => 1]);

        // Aunque pida explícitamente la tienda 2 (o "todas"), solo ve la suya.
        $r = $this->actingAs($vendedor)->getJson('/api/inventario/5/reservas?tienda_id=2')->assertOk();
        $this->assertCount(1, $r->json());
        $this->assertSame('Norte', $r->json()[0]['tienda_nombre']);

        $rTodas = $this->actingAs($vendedor)->getJson('/api/inventario/5/reservas?tienda_id=todas')->assertOk();
        $this->assertCount(1, $rTodas->json());
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

        $this->assertCount(0, $r->json());
    }
}
