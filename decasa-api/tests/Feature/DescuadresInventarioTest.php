<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Surtido;
use App\Models\SurtidoItem;
use App\Models\SurtidoTienda;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Descuadres" es la auditoría completa: para cada producto+tienda con algo
 * reservado, ¿el contador coincide con lo que de verdad hay comprometido
 * (órdenes activas, más lo que un surtido de fábrica todavía sin responder
 * tiene retenido)? Si no coincide, sale en la lista — es la forma de ver, sin
 * adivinar, si un descuadre puntual (como el de `ReservasInventarioTest`) es
 * un caso aislado o algo que ya viene pasando en más productos.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class DescuadresInventarioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->default('vendedor');
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->unsignedInteger('numero_orden')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('producto_unico')->default(false);
            $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('surtidos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->string('estado')->default('enviado'); $t->boolean('fuente_fabrica')->default(false);
            $t->timestamps();
        });
        Schema::create('surtido_tiendas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('vendedor_validador_id')->nullable(); $t->string('estado')->default('pendiente');
        });
        Schema::create('surtido_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_tienda_id'); $t->unsignedBigInteger('producto_id');
            $t->integer('cantidad'); $t->integer('cantidad_aceptada')->nullable();
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Bodega Fábrica', 'es_fabrica' => true],
            ['id' => 2, 'nombre' => 'Decasa Norte', 'es_fabrica' => false],
        ]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Base 2K']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Sup', 'rol' => 'supervisor', 'created_at' => now()]);
    }

    public function test_no_reporta_nada_si_el_contador_coincide_con_una_orden_activa(): void
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'pendiente_anticipo', 'valor_total' => 100]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1, 'es_personalizado' => false, 'producto_unico' => false]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(0, $r->json());
    }

    public function test_reporta_un_contador_sin_ninguna_orden_que_lo_sostenga(): void
    {
        // Exactamente el caso reportado: la tarjeta dice "Reservado: 1" pero
        // no hay ninguna orden viva que lo tenga apartado.
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $data = $r->json();
        $this->assertCount(1, $data);
        $this->assertSame('Base 2K', $data[0]['producto_nombre']);
        $this->assertSame('Decasa Norte', $data[0]['tienda_nombre']);
        $this->assertSame(1, $data[0]['contador']);
        $this->assertSame(0, $data[0]['real']);
        $this->assertSame(1, $data[0]['diferencia']);
    }

    public function test_no_reporta_la_reserva_de_un_surtido_de_fabrica_todavia_pendiente(): void
    {
        $surtido = Surtido::create(['estado' => 'enviado', 'fuente_fabrica' => true]);
        $st = SurtidoTienda::create(['surtido_id' => $surtido->id, 'tienda_id' => 2, 'estado' => 'pendiente']);
        SurtidoItem::create(['surtido_tienda_id' => $st->id, 'producto_id' => 5, 'cantidad' => 3]);
        // La reserva vive en la fábrica (tienda 1), no en el destino.
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 10, 'cantidad_reservada' => 3]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(0, $r->json());
    }

    public function test_un_surtido_de_fabrica_ya_respondido_no_cuenta_como_reserva_viva(): void
    {
        $surtido = Surtido::create(['estado' => 'completado', 'fuente_fabrica' => true]);
        $st = SurtidoTienda::create(['surtido_id' => $surtido->id, 'tienda_id' => 2, 'estado' => 'aceptado']);
        SurtidoItem::create(['surtido_tienda_id' => $st->id, 'producto_id' => 5, 'cantidad' => 3, 'cantidad_aceptada' => 3]);
        // El contador de fábrica quedó mal (no se liberó): esto SÍ debe salir.
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 10, 'cantidad_reservada' => 3]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(1, $r->json());
        $this->assertSame(3, $r->json()[0]['diferencia']);
    }

    public function test_solo_el_supervisor_puede_verlo(): void
    {
        $vendedor = Usuario::create(['nombre' => 'V', 'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($vendedor)->getJson('/api/inventario/descuadres')->assertStatus(403);
    }
}
