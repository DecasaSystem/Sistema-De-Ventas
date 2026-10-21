<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Services\EntregaService;
use App\Support\StockMedida;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Vender la medida sin saber la tela.
 *
 * Caso real (2026-10-20): una cama con 2 de 1.40 anotadas por medida, y telas
 * registradas pero sin cruzarlas con la medida. El selector solo ofrecía
 * "tela × medida" con el stock de las combinaciones (ninguna) y todo salía
 * "Sin stock"; el servidor respondía "Stock insuficiente para variante ID 36.
 * Stock libre: 0, solicitado: 1." aunque la medida sí tenía.
 *
 * Ahora la medida se revisa aparte de la tela (`StockMedida`), se puede
 * vender "1.40 · tela por definir", la tela se define después desde la orden
 * y no se despacha sin ella.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class VentaPorMedidaSinTelaTest extends TestCase
{
    private const TIENDA = 1, CAMA = 5, M140 = 11, M160 = 12, GRIS = 36, BEIGE = 37;

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
        Schema::create('tipos_variante', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('afecta_precio')->default(true); $t->timestamps();
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id'); $t->string('nombre'); $t->timestamps();
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id');
            $t->unsignedBigInteger('opcion_id'); $t->decimal('precio_adicional', 15, 2)->default(0);
            $t->unsignedInteger('piezas_por_juego')->nullable(); $t->timestamps();
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->string('marca')->nullable();
            $t->string('marca_tela')->nullable(); $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
            $t->decimal('precio_variante', 15, 2)->nullable();
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0); $t->timestamps();
        });
        Schema::create('inventario_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->timestamps();
        });
        Schema::create('inventario_variante_combinaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('config_id');
            $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) { $t->string('clave_unica')->nullable()->unique(); $t->string('forma_pago_pagada')->nullable();
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

        $this->completarEsquemaDeEntregas();

        DB::table('tiendas')->insert(['id' => self::TIENDA, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => self::CAMA, 'nombre' => 'Cama Bali', 'categoria' => 'camas']);

        // Medidas: 2 de 1.40 y ninguna de 1.60.
        DB::table('tipos_variante')->insert(['id' => 1, 'nombre' => 'Medida']);
        DB::table('tipo_variante_opciones')->insert([
            ['id' => 1, 'tipo_variante_id' => 1, 'nombre' => '1.40'],
            ['id' => 2, 'tipo_variante_id' => 1, 'nombre' => '1.60'],
        ]);
        DB::table('producto_variante_configs')->insert([
            ['id' => self::M140, 'producto_id' => self::CAMA, 'tipo_variante_id' => 1, 'opcion_id' => 1, 'precio_adicional' => 1500000],
            ['id' => self::M160, 'producto_id' => self::CAMA, 'tipo_variante_id' => 1, 'opcion_id' => 2, 'precio_adicional' => 1800000],
        ]);
        DB::table('inventario_variante_configs')->insert([
            ['config_id' => self::M140, 'tienda_id' => self::TIENDA, 'cantidad_disponible' => 2],
            ['config_id' => self::M160, 'tienda_id' => self::TIENDA, 'cantidad_disponible' => 0],
        ]);

        // Telas: una gris (la "variante 36" del caso) y una beige, sin cruzar
        // con las medidas: no hay ninguna combinación registrada.
        DB::table('producto_variantes')->insert([
            ['id' => self::GRIS,  'producto_id' => self::CAMA, 'marca_tela' => 'Lino', 'nombre_color' => 'Gris'],
            ['id' => self::BEIGE, 'producto_id' => self::CAMA, 'marca_tela' => 'Lino', 'nombre_color' => 'Beige'],
        ]);
        DB::table('inventario_variantes')->insert([
            ['variante_id' => self::GRIS,  'tienda_id' => self::TIENDA, 'cantidad_disponible' => 1],
            ['variante_id' => self::BEIGE, 'tienda_id' => self::TIENDA, 'cantidad_disponible' => 1],
        ]);

        DB::table('inventario')->insert(['producto_id' => self::CAMA, 'tienda_id' => self::TIENDA, 'cantidad_disponible' => 2]);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v' . rand() . '@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => self::TIENDA, 'firma_url' => 'https://ejemplo/firma-v.png', 'created_at' => now(),
        ]);
    }

    private function venta(array $item): array
    {
        return [
            'cliente_id'     => 1,
            'tienda_id'      => self::TIENDA,
            'canal'          => 'fisica',
            'anexo_foto_url' => 'https://ejemplo/anexo.png',
            'anticipo_monto' => 0,
            'firma_url'      => 'https://ejemplo/firma.png',
            'items'          => [array_merge([
                'producto_id'      => self::CAMA,
                'cantidad'         => 1,
                'precio_unitario'  => 1500000,
                'es_personalizado' => false,
            ], $item)],
        ];
    }

    public function test_el_selector_ofrece_la_medida_con_la_tela_por_definir(): void
    {
        $filas = $this->actingAs($this->vendedor())
            ->getJson('/api/productos/' . self::CAMA . '/variantes?tienda_id=' . self::TIENDA . '&para_venta=1')
            ->assertOk()->json();

        $sinTela = collect($filas)->where('_tela_por_definir', true)->values();
        $this->assertCount(1, $sinTela, 'solo la 1.40 tiene stock por medida');
        $this->assertSame(self::M140, $sinTela[0]['_config_id']);
        $this->assertSame(2, $sinTela[0]['stock_libre']);
        $this->assertEquals(1500000, $sinTela[0]['precio_variante'], 'el precio es el de esa medida');
    }

    public function test_sin_para_venta_inventario_ve_lo_de_siempre(): void
    {
        $filas = $this->actingAs($this->vendedor())
            ->getJson('/api/productos/' . self::CAMA . '/variantes?tienda_id=' . self::TIENDA)
            ->assertOk()->json();

        $this->assertEmpty(collect($filas)->where('_tela_por_definir', true));
    }

    public function test_se_vende_la_medida_sin_tela(): void
    {
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->venta(['combo_config_id' => self::M140, 'tela_por_definir' => true]))
            ->assertCreated();

        $item = OrdenItem::first();
        $this->assertTrue($item->tela_por_definir);
        $this->assertNull($item->variante_id);
        $this->assertSame(1, StockMedida::apartado(self::M140, self::TIENDA));
        $this->assertSame(1, StockMedida::libre(self::M140, self::TIENDA));
    }

    public function test_medida_y_tela_sin_combinacion_registrada_ya_no_da_cero(): void
    {
        // El caso del 422: tela gris + 1.40, sin combinación registrada.
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->venta(['combo_config_id' => self::M140, 'variante_id' => self::GRIS]))
            ->assertCreated();

        $this->assertSame(1, (int) DB::table('inventario_variantes')->where('variante_id', self::GRIS)->value('cantidad_reservada'));
    }

    public function test_la_ultima_140_no_se_vende_dos_veces(): void
    {
        $v = $this->vendedor();
        $sinTela = ['combo_config_id' => self::M140, 'tela_por_definir' => true];

        $this->actingAs($v)->postJson('/api/ordenes', $this->venta($sinTela))->assertCreated();
        // Otra con distinto valor: la protección de doble envío no la frena.
        $this->actingAs($v)->postJson('/api/ordenes', $this->venta($sinTela + ['precio_unitario' => 1400000]))->assertCreated();

        $r = $this->actingAs($v)->postJson('/api/ordenes', $this->venta($sinTela + ['precio_unitario' => 1300000]))
            ->assertStatus(422);

        $msg = $r->json('message');
        $this->assertStringContainsString('Cama Bali', $msg);
        $this->assertStringContainsString('1.40', $msg);
        $this->assertStringContainsString('Decasa Norte', $msg);
        $this->assertStringNotContainsString('ID', $msg, 'el vendedor no sabe qué es un ID');
    }

    public function test_la_160_sin_stock_no_se_vende(): void
    {
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->venta(['combo_config_id' => self::M160, 'tela_por_definir' => true]))
            ->assertStatus(422);
    }

    public function test_si_el_producto_no_lleva_stock_por_medida_manda_el_total(): void
    {
        // Las medidas existen pero en 0: el stock se anotó solo al producto.
        DB::table('inventario_variante_configs')->update(['cantidad_disponible' => 0]);

        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->venta(['combo_config_id' => self::M160]))
            ->assertCreated();
    }

    public function test_definir_la_tela_la_aparta_y_libera_el_despacho(): void
    {
        $v = $this->vendedor();
        $this->actingAs($v)
            ->postJson('/api/ordenes', $this->venta(['combo_config_id' => self::M140, 'tela_por_definir' => true]))
            ->assertCreated();

        $orden = Orden::first();
        $item  = OrdenItem::first();

        // Sin tela no se despacha, y el motivo lo dice.
        $orden->setRelation('items', collect([$item]));
        $this->assertEmpty(EntregaService::entregablesDe($orden));
        $this->assertStringContainsString('definir la tela', EntregaService::faltaDefinirTela($orden));

        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/items/{$item->id}/definir-tela", ['variante_id' => self::BEIGE])
            ->assertOk();

        $item->refresh();
        $this->assertFalse($item->tela_por_definir);
        $this->assertSame(self::BEIGE, (int) $item->variante_id);
        $this->assertStringContainsString('1.40', $item->variante_detalle);
        $this->assertStringContainsString('Beige', $item->variante_detalle);
        $this->assertSame(1, (int) DB::table('inventario_variantes')->where('variante_id', self::BEIGE)->value('cantidad_reservada'));
    }

    public function test_no_se_define_una_tela_sin_stock(): void
    {
        $v = $this->vendedor();
        DB::table('inventario_variantes')->where('variante_id', self::BEIGE)->update(['cantidad_disponible' => 0]);

        $this->actingAs($v)
            ->postJson('/api/ordenes', $this->venta(['combo_config_id' => self::M140, 'tela_por_definir' => true]))
            ->assertCreated();
        $orden = Orden::first();
        $item  = OrdenItem::first();

        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/items/{$item->id}/definir-tela", ['variante_id' => self::BEIGE])
            ->assertStatus(422);

        $this->assertTrue($item->fresh()->tela_por_definir);
    }
}
