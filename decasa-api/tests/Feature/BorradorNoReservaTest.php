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
 * Regla nueva: un borrador NO reserva stock. Es un boceto de venta, no una
 * venta. La reserva se hace toda junta cuando se confirma
 * (`completar-borrador`) — igual que una cotización solo toca inventario al
 * convertirse.
 *
 * Antes cada borrador apartaba su mercancía, y si se borraba o se abandonaba
 * la reserva quedaba huérfana: stock que figuraba comprometido sin ninguna
 * venta detrás.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class BorradorNoReservaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('canal')->nullable();
            $t->string('tipo')->default('venta'); $t->string('estado')->default('pendiente_anticipo');
            $t->timestamp('listo_entrega_at')->nullable(); $t->boolean('entrega_inmediata')->default(false);
            $t->timestamp('confirmada_en')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 5, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->decimal('anticipo_pct', 5, 2)->default(0); $t->text('notas')->nullable();
            $t->date('fecha_sugerida_vendedor')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('factura_foto_url')->nullable(); $t->string('firma_url')->nullable();
            $t->string('anexo_foto_url')->nullable();
            $t->string('departamento_envio')->nullable(); $t->string('ciudad_envio')->nullable();
            $t->string('direccion_envio')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('serie')->nullable(); $t->string('motivo_serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('grupo_secuencia')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->string('variante_detalle')->nullable(); $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 15, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('es_regalo')->default(false); $t->boolean('usa_stock_tienda')->default(false);
            $t->json('specs_personalizacion')->nullable(); $t->string('boceto_url')->nullable();
            $t->json('boceto_fotos')->nullable(); $t->date('fecha_entrega_prom')->nullable();
            $t->date('devuelto_en')->nullable(); $t->text('motivo_devolucion')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(1);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
            $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
            $t->boolean('es_covendedor')->default(false); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 15, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->json('cambios')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('consultas_costo', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->timestamps();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa', 'categoria' => 'comedores']);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0]);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 1, 'firma_url' => 'https://ejemplo/firma-v.png', 'created_at' => now(),
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'cliente_id'     => 1,
            'tienda_id'      => 1,
            'canal'          => 'fisica',
            'anticipo_monto' => 0,
            'firma_url'      => 'https://ejemplo/firma.png',
            'items'          => [[
                'producto_id'     => 5,
                'cantidad'        => 1,
                'precio_unitario' => 100000,
                'es_personalizado' => false,
            ]],
        ], $extra);
    }

    private function reservado(): int
    {
        return (int) DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 1)->value('cantidad_reservada');
    }

    public function test_un_borrador_no_reserva_stock(): void
    {
        $v = $this->vendedor();

        $this->actingAs($v)->postJson('/api/ordenes', $this->payload(['guardar_borrador' => true]))
            ->assertCreated();

        $this->assertSame('borrador', Orden::first()->estado);
        $this->assertSame(0, $this->reservado(), 'un borrador NO debe reservar');
    }

    public function test_una_venta_normal_si_reserva(): void
    {
        $v = $this->vendedor();

        $this->actingAs($v)->postJson('/api/ordenes', $this->payload())->assertCreated();

        $this->assertSame(1, $this->reservado(), 'una venta normal sí reserva');
    }

    public function test_al_confirmar_el_borrador_se_reserva(): void
    {
        $v = $this->vendedor();

        $r = $this->actingAs($v)->postJson('/api/ordenes', $this->payload(['guardar_borrador' => true]))->assertCreated();
        $ordenId = $r->json('id');
        $this->assertSame(0, $this->reservado());

        $this->actingAs($v)->postJson("/api/ordenes/{$ordenId}/completar-borrador", [
            'firma_url'          => 'https://ejemplo/firma-cliente.png',
            'anticipo_monto'     => 0,
            'factura_foto_url'   => 'https://ejemplo/factura.png',
            'anexo_foto_url'     => 'https://ejemplo/anexo.png',
            'departamento_envio' => 'Risaralda',
            'ciudad_envio'       => 'Pereira',
            'direccion_envio'    => 'Calle 1 # 2-3',
        ])->assertOk();

        $this->assertSame('pendiente_anticipo', Orden::first()->estado);
        $this->assertSame(1, $this->reservado(), 'al confirmar el borrador SÍ se reserva');
    }

    public function test_borrar_un_borrador_no_toca_el_stock(): void
    {
        $v = $this->vendedor();

        $r = $this->actingAs($v)->postJson('/api/ordenes', $this->payload(['guardar_borrador' => true]))->assertCreated();
        $ordenId = $r->json('id');

        $this->actingAs($v)->deleteJson("/api/ordenes/{$ordenId}")->assertOk();

        $this->assertSame(0, $this->reservado());
        $this->assertSame(0, DB::table('inventario_movimientos')->where('tipo', 'liberacion')->count());
    }

    public function test_la_migracion_suelta_lo_que_los_borradores_viejos_tenian_apartado(): void
    {
        // Simula el estado ANTES del cambio: un borrador con su reserva puesta.
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'borrador', 'valor_total' => 100000]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 2, 'es_personalizado' => false, 'producto_unico' => false, 'es_restauracion' => false]);
        DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 1)->update(['cantidad_reservada' => 2]);

        // Y una orden normal que NO se debe tocar.
        $normal = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'pendiente_anticipo', 'valor_total' => 100000]);
        OrdenItem::create(['orden_id' => $normal->id, 'producto_id' => 5, 'cantidad' => 1, 'es_personalizado' => false, 'producto_unico' => false, 'es_restauracion' => false]);
        DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 1)->increment('cantidad_reservada', 1); // total 3

        (require base_path('database/migrations/2026_09_20_000002_borradores_no_reservan_stock.php'))->up();

        // Bajó 2 (lo del borrador), quedó 1 (lo de la orden normal).
        $this->assertSame(1, $this->reservado());
        $this->assertSame(1, DB::table('inventario_movimientos')->where('tipo', 'liberacion')
            ->where('motivo', 'like', 'Borrador%')->count());
    }
}
