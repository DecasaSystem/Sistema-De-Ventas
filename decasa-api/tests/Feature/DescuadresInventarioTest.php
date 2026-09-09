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
            $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
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
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
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
            ['id' => 3, 'nombre' => 'Tienda Virtual', 'es_fabrica' => false],
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

        $this->assertCount(0, $r->json('reservados'));
    }

    public function test_un_item_devuelto_para_cambiarlo_no_infla_lo_real(): void
    {
        // El supervisor cambió el producto de una orden ya entregada
        // (OrdenController::cambiarProducto): el ítem viejo queda
        // `devuelto_en` y la orden reabre a `pendiente_anticipo`, pero ya
        // liberó su reserva al entregarse la primera vez — el inventario no
        // le debe nada más.
        //
        // Se prueba junto a una reserva de verdad del MISMO producto+tienda
        // (otra orden, activa) para que el riesgo quede claro: si el ítem
        // devuelto se contara, "real" saldría en 2 aunque el contador (1, la
        // reserva de verdad) esté perfectamente bien — y "Corregir" habría
        // terminado RESERVANDO una unidad de más que sí se puede vender.
        $ordenVieja = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'pendiente_anticipo', 'valor_total' => 100]);
        OrdenItem::create([
            'orden_id' => $ordenVieja->id, 'producto_id' => 5, 'cantidad' => 1,
            'es_personalizado' => false, 'producto_unico' => false,
            'devuelto_en' => now()->toDateString(),
        ]);
        $ordenActiva = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'pendiente_anticipo', 'valor_total' => 100]);
        OrdenItem::create(['orden_id' => $ordenActiva->id, 'producto_id' => 5, 'cantidad' => 1, 'es_personalizado' => false, 'producto_unico' => false]);

        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(0, $r->json('reservados'));
    }

    public function test_reporta_un_contador_sin_ninguna_orden_que_lo_sostenga(): void
    {
        // Exactamente el caso reportado: la tarjeta dice "Reservado: 1" pero
        // no hay ninguna orden viva que lo tenga apartado.
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $data = $r->json('reservados');
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

        $this->assertCount(0, $r->json('reservados'));
    }

    public function test_un_surtido_de_fabrica_ya_respondido_no_cuenta_como_reserva_viva(): void
    {
        $surtido = Surtido::create(['estado' => 'completado', 'fuente_fabrica' => true]);
        $st = SurtidoTienda::create(['surtido_id' => $surtido->id, 'tienda_id' => 2, 'estado' => 'aceptado']);
        SurtidoItem::create(['surtido_tienda_id' => $st->id, 'producto_id' => 5, 'cantidad' => 3, 'cantidad_aceptada' => 3]);
        // El contador de fábrica quedó mal (no se liberó): esto SÍ debe salir.
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 10, 'cantidad_reservada' => 3]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(1, $r->json('reservados'));
        $this->assertSame(3, $r->json('reservados')[0]['diferencia']);
    }

    // ── Entregas que nunca descontaron inventario ──────────────────────────

    public function test_reporta_una_entrega_sin_su_movimiento_de_salida(): void
    {
        // Exactamente el caso de la orden #4308: se entregó (estado
        // "entregado"), pero nunca quedó el movimiento de salida que debía
        // descontar el inventario — ni Disponible ni Reservado bajaron.
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 3, 'estado' => 'entregado', 'valor_total' => 100, 'numero_orden' => 4308]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1, 'tienda_origen_id' => 2, 'es_personalizado' => false, 'producto_unico' => false]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $data = $r->json('entregas_sin_descontar');
        $this->assertCount(1, $data);
        $this->assertSame('Base 2K', $data[0]['producto_nombre']);
        $this->assertSame('Decasa Norte', $data[0]['tienda_nombre']);
        $this->assertSame(1, $data[0]['cantidad_pendiente']);
        $this->assertSame(1, $data[0]['disponible_actual']);
        $this->assertSame(0, $data[0]['disponible_despues']);
        $this->assertSame(['#4308'], $data[0]['ordenes']);
    }

    public function test_no_reporta_una_entrega_que_si_dejo_su_movimiento(): void
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'entregado', 'valor_total' => 100]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1, 'es_personalizado' => false, 'producto_unico' => false]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0]);
        DB::table('inventario_movimientos')->insert([
            'producto_id' => 5, 'tienda_id' => 2, 'tipo' => 'salida', 'cantidad' => 1,
            'motivo' => "Entrega orden #{$orden->id} — conductor", 'created_at' => now(),
        ]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(0, $r->json('entregas_sin_descontar'));
    }

    public function test_una_orden_cancelada_no_cuenta_como_entrega_sin_descontar(): void
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'cancelado', 'valor_total' => 100]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1, 'es_personalizado' => false, 'producto_unico' => false]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();

        $this->assertCount(0, $r->json('entregas_sin_descontar'));
    }

    public function test_corregir_entrega_baja_disponible_y_reservado_juntos(): void
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'entregado', 'valor_total' => 100, 'numero_orden' => 4308]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1, 'es_personalizado' => false, 'producto_unico' => false]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $this->actingAs($this->supervisor())
            ->postJson('/api/inventario/descuadres/corregir', ['tipo' => 'entrega', 'producto_id' => 5, 'tienda_id' => 2])
            ->assertOk()
            ->assertJson(['corregidos' => 1]);

        $inv = DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 2)->first();
        $this->assertSame(0, $inv->cantidad_disponible);

        $mov = DB::table('inventario_movimientos')->where('producto_id', 5)->where('tienda_id', 2)->first();
        $this->assertSame('salida', $mov->tipo);
        $this->assertSame(1, $mov->cantidad);
    }

    public function test_corregir_todos_arregla_reservado_y_entrega_a_la_vez(): void
    {
        // El caso mixto que de verdad importa: 20 disponibles, 6 "reservadas"
        // que en realidad ya se entregaron (el bug) y 4 reservadas de una
        // orden activa de verdad. Al corregir todo debe quedar en
        // 14 disponibles / 4 reservadas / 10 libres — nunca en 0.
        $ordenEntregada = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'entregado', 'valor_total' => 100]);
        OrdenItem::create(['orden_id' => $ordenEntregada->id, 'producto_id' => 5, 'cantidad' => 6, 'es_personalizado' => false, 'producto_unico' => false]);
        $ordenActiva = Orden::create(['cliente_id' => 1, 'tienda_id' => 2, 'estado' => 'pendiente_anticipo', 'valor_total' => 100]);
        OrdenItem::create(['orden_id' => $ordenActiva->id, 'producto_id' => 5, 'cantidad' => 4, 'es_personalizado' => false, 'producto_unico' => false]);

        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 20, 'cantidad_reservada' => 10]);

        $this->actingAs($this->supervisor())
            ->postJson('/api/inventario/descuadres/corregir', ['todos' => true])
            ->assertOk();

        $inv = DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 2)->first();
        $this->assertSame(14, $inv->cantidad_disponible);
        $this->assertSame(4, $inv->cantidad_reservada);
    }

    public function test_solo_el_supervisor_puede_verlo(): void
    {
        $vendedor = Usuario::create(['nombre' => 'V', 'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($vendedor)->getJson('/api/inventario/descuadres')->assertStatus(403);
    }

    // ── Corregir ─────────────────────────────────────────────────────────────

    public function test_corregir_uno_deja_el_contador_en_lo_real(): void
    {
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $this->actingAs($this->supervisor())
            ->postJson('/api/inventario/descuadres/corregir', ['tipo' => 'reservado', 'producto_id' => 5, 'tienda_id' => 2])
            ->assertOk()
            ->assertJson(['corregidos' => 1]);

        $inv = DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 2)->first();
        $this->assertSame(0, $inv->cantidad_reservada);

        $mov = DB::table('inventario_movimientos')->where('producto_id', 5)->where('tienda_id', 2)->first();
        $this->assertNotNull($mov);
        $this->assertSame('liberacion', $mov->tipo);
        $this->assertSame(1, $mov->cantidad);
    }

    public function test_corregir_uno_no_toca_los_demas_descuadres(): void
    {
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);
        DB::table('productos')->insert(['id' => 6, 'nombre' => 'Otro producto']);
        DB::table('inventario')->insert(['producto_id' => 6, 'tienda_id' => 2, 'cantidad_disponible' => 2, 'cantidad_reservada' => 2]);

        $this->actingAs($this->supervisor())
            ->postJson('/api/inventario/descuadres/corregir', ['tipo' => 'reservado', 'producto_id' => 5, 'tienda_id' => 2])
            ->assertOk();

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();
        $this->assertCount(1, $r->json('reservados'));
        $this->assertSame(6, $r->json('reservados')[0]['producto_id']);
    }

    public function test_corregir_todos_los_arregla_de_una(): void
    {
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);
        DB::table('productos')->insert(['id' => 6, 'nombre' => 'Otro producto']);
        DB::table('inventario')->insert(['producto_id' => 6, 'tienda_id' => 2, 'cantidad_disponible' => 2, 'cantidad_reservada' => 2]);

        $this->actingAs($this->supervisor())
            ->postJson('/api/inventario/descuadres/corregir', ['todos' => true])
            ->assertOk()
            ->assertJson(['corregidos' => 2]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/inventario/descuadres')->assertOk();
        $this->assertCount(0, $r->json('reservados'));
    }

    public function test_corregir_un_producto_ya_cuadrado_avisa_en_vez_de_fallar_en_silencio(): void
    {
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0]);

        $this->actingAs($this->supervisor())
            ->postJson('/api/inventario/descuadres/corregir', ['tipo' => 'reservado', 'producto_id' => 5, 'tienda_id' => 2])
            ->assertStatus(422);
    }

    public function test_solo_el_supervisor_puede_corregir(): void
    {
        $vendedor = Usuario::create(['nombre' => 'V', 'rol' => 'vendedor', 'created_at' => now()]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);

        $this->actingAs($vendedor)
            ->postJson('/api/inventario/descuadres/corregir', ['tipo' => 'reservado', 'producto_id' => 5, 'tienda_id' => 2])
            ->assertStatus(403);
    }
}
