<?php

namespace Tests\Feature;

use App\Models\OrdenItem;
use App\Models\Promocion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Promociones por tienda (dueño, 2026-10-15): "busco el producto, lo pongo en
 * promoción, escojo las tiendas, cuánto y de qué fecha a qué fecha, para no
 * tener que cambiarle el valor a cada rato". Y la variante también cuenta: la
 * cama Bali puede estar normal y solo la de 1.40 en promoción.
 *
 * Las tablas de promociones salen de su migración real; lo demás se monta a
 * mano como en ProductoUnicoTest.
 */
class PromocionesPorTiendaTest extends TestCase
{
    private Usuario $supervisora;

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
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable();
            $t->decimal('precio_base', 15, 2)->default(0); $t->boolean('personalizable')->default(false);
            $t->boolean('es_tapizado')->default(false); $t->unsignedInteger('piezas_por_juego')->nullable();
            $t->decimal('precio_pieza', 15, 2)->nullable(); $t->string('foto_url')->nullable();
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tipos_variante', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('afecta_precio')->default(true); $t->timestamps();
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id'); $t->string('nombre');
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id');
            $t->unsignedBigInteger('opcion_id'); $t->decimal('precio_adicional', 15, 2)->default(0);
            $t->unsignedInteger('piezas_por_juego')->nullable(); $t->timestamps();
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->string('marca')->nullable();
            $t->string('marca_tela')->nullable(); $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
            $t->decimal('precio_variante', 15, 2)->nullable(); $t->string('foto_url')->nullable();
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('canal')->nullable();
            $t->string('tipo')->default('venta'); $t->string('estado')->default('pendiente_anticipo');
            $t->timestamp('listo_entrega_at')->nullable(); $t->boolean('entrega_inmediata')->default(false);
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
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
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
        $this->completarEsquemaDeEntregas();

        (require database_path('migrations/2026_10_23_000001_promociones_por_tienda.php'))->up();

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Unicentro'],
            ['id' => 2, 'nombre' => 'Decasa El Edén'],
            ['id' => 3, 'nombre' => 'Decasa Circunvalar'],
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        // Cama Bali: $1.500.000; la de 1.40 vale $1.800.000 y la de 1.00 $1.200.000.
        DB::table('productos')->insert(['id' => 10, 'nombre' => 'CAMA BALI', 'categoria' => 'camas', 'precio_base' => 1500000]);
        DB::table('tipos_variante')->insert(['id' => 1, 'nombre' => 'Cama Bali medidas']);
        DB::table('tipo_variante_opciones')->insert([
            ['id' => 1, 'tipo_variante_id' => 1, 'nombre' => '1.40'],
            ['id' => 2, 'tipo_variante_id' => 1, 'nombre' => '1.00'],
        ]);
        DB::table('producto_variante_configs')->insert([
            ['id' => 100, 'producto_id' => 10, 'tipo_variante_id' => 1, 'opcion_id' => 1, 'precio_adicional' => 1800000],
            ['id' => 101, 'producto_id' => 10, 'tipo_variante_id' => 1, 'opcion_id' => 2, 'precio_adicional' => 1200000],
        ]);
        // Un comedor sin versiones.
        DB::table('productos')->insert(['id' => 20, 'nombre' => 'COMEDOR ROMA', 'categoria' => 'comedores', 'precio_base' => 2000000]);

        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00', 'America/Bogota'));
        $this->supervisora = Usuario::forceCreate(['nombre' => 'Marta', 'rol' => 'supervisor', 'activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vendedora(int $tienda = 1): Usuario
    {
        return Usuario::forceCreate([
            'nombre' => 'Vendedora ' . $tienda, 'rol' => 'vendedor', 'activo' => true,
            'tienda_default_id' => $tienda, 'firma_url' => 'https://ejemplo/firma.png',
        ]);
    }

    private function crear(array $datos)
    {
        return $this->actingAs($this->supervisora)->postJson('/api/promociones', array_merge([
            'producto_id'  => 10,
            'config_id'    => 100,
            'tipo'         => 'porcentaje',
            'valor'        => 15,
            'fecha_inicio' => '2026-10-01',
            'fecha_fin'    => '2026-10-31',
            'tiendas'      => [1, 2],
        ], $datos));
    }

    public function test_la_de_140_en_dos_tiendas_corre_solo_ahi_y_solo_esas_fechas(): void
    {
        $r = $this->crear(['nombre' => 'Feria de octubre'])->assertCreated();
        $r->assertJsonPath('version', '1.40')
          ->assertJsonPath('precio_lista', 1800000)
          ->assertJsonPath('precio_promo', 1530000)
          ->assertJsonPath('estado', 'vigente');

        $vend = $this->vendedora();
        $this->actingAs($vend)->getJson('/api/promociones/vigentes?tienda_id=1')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.config_id', 100)->assertJsonPath('0.tipo', 'porcentaje');
        $this->actingAs($vend)->getJson('/api/promociones/vigentes?tienda_id=3')
            ->assertOk()->assertJsonCount(0);

        // El 31 a las 8 p. m. de Colombia (1 a. m. del 1.º en UTC) todavía corre.
        Carbon::setTestNow(Carbon::parse('2026-10-31 20:00', 'America/Bogota'));
        $this->actingAs($vend)->getJson('/api/promociones/vigentes?tienda_id=1')->assertJsonCount(1);
        // El 1.º de noviembre ya no: nadie tuvo que volver a subir el precio.
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00', 'America/Bogota'));
        $this->actingAs($vend)->getJson('/api/promociones/vigentes?tienda_id=1')->assertJsonCount(0);
    }

    public function test_el_precio_de_lista_no_se_toca(): void
    {
        $this->crear([])->assertCreated();
        $this->assertSame(1800000.0, (float) DB::table('producto_variante_configs')->where('id', 100)->value('precio_adicional'));
        $this->assertSame(1500000.0, (float) DB::table('productos')->where('id', 10)->value('precio_base'));
    }

    public function test_solo_un_supervisor_las_programa(): void
    {
        $this->actingAs($this->vendedora())->postJson('/api/promociones', [
            'producto_id' => 10, 'tipo' => 'porcentaje', 'valor' => 10,
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31', 'tiendas' => [1],
        ])->assertForbidden();
        $this->actingAs($this->vendedora())->getJson('/api/promociones')->assertForbidden();
    }

    public function test_dos_para_lo_mismo_en_la_misma_tienda_y_fechas_que_se_cruzan_no(): void
    {
        $this->crear([])->assertCreated();
        $this->crear(['fecha_inicio' => '2026-10-20', 'fecha_fin' => '2026-11-10', 'tiendas' => [2, 3]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'El Edén'));

        // En otra tienda, o después, sí.
        $this->crear(['tiendas' => [3]])->assertCreated();
        $this->crear(['fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30'])->assertCreated();
        // Y una del producto entero junto con la de la medida también (gana la de la medida).
        $this->crear(['config_id' => null, 'valor' => 10])->assertCreated();
    }

    public function test_apagada_no_corre_y_encenderla_vuelve_a_mirar_los_cruces(): void
    {
        $id = $this->crear([])->assertCreated()->json('id');
        $this->actingAs($this->supervisora)->patchJson("/api/promociones/{$id}", ['activa' => false])
            ->assertOk()->assertJsonPath('estado', 'apagada');
        $this->actingAs($this->vendedora())->getJson('/api/promociones/vigentes?tienda_id=1')->assertJsonCount(0);

        // Mientras estaba apagada se programó otra igual: no se pueden prender las dos.
        $this->crear(['valor' => 20])->assertCreated();
        $this->actingAs($this->supervisora)->patchJson("/api/promociones/{$id}", ['activa' => true])->assertStatus(422);
    }

    public function test_rebajas_que_no_tienen_sentido_se_frenan(): void
    {
        // Precio final para "todas las medidas": dejaría la de 1.00 y la de 1.40 iguales.
        $this->crear(['config_id' => null, 'tipo' => 'precio', 'valor' => 1000000])->assertStatus(422);
        // Precio de promoción mayor que el de lista.
        $this->crear(['tipo' => 'precio', 'valor' => 1900000])->assertStatus(422);
        // Rebaja más grande que el precio.
        $this->crear(['tipo' => 'monto', 'valor' => 1800000])->assertStatus(422);
        $this->crear(['tipo' => 'porcentaje', 'valor' => 100])->assertStatus(422);
        // Opción de otro producto.
        $this->crear(['producto_id' => 20])->assertStatus(422);
        // Sin tiendas o con la fecha final antes de la inicial.
        $this->crear(['tiendas' => []])->assertStatus(422);
        $this->crear(['fecha_inicio' => '2026-10-20', 'fecha_fin' => '2026-10-10'])->assertStatus(422);
        // Ya vencida al crearla.
        $this->crear(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30'])->assertStatus(422);

        // Un producto sin versiones sí puede quedar en un precio final.
        $this->crear(['producto_id' => 20, 'config_id' => null, 'tipo' => 'precio', 'valor' => 1700000])
            ->assertCreated()->assertJsonPath('precio_promo', 1700000);
    }

    public function test_la_orden_deja_escrito_con_que_promocion_se_vendio(): void
    {
        $promo = $this->crear([])->assertCreated()->json('id');

        $this->actingAs($this->vendedora())->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url'  => 'https://ejemplo/firma.png',
            'items' => [[
                'producto_id' => 10, 'combo_config_id' => 100, 'variante_detalle' => '1.40',
                'cantidad' => 1, 'precio_unitario' => 1530000,
                'es_personalizado' => true, 'fabricar_pedido' => true,
                'promocion_id' => $promo,
            ]],
        ])->assertCreated();

        $item = OrdenItem::first();
        $this->assertSame($promo, (int) $item->promocion_id);
        // El precio es el que mandó la pantalla, ya rebajado: no se rebaja otra vez.
        $this->assertSame(1530000.0, (float) $item->precio_unitario);

        // Ya se vendió con ella: no se borra, se apaga.
        $this->actingAs($this->supervisora)->deleteJson("/api/promociones/{$promo}")->assertStatus(422);
        $this->actingAs($this->supervisora)->getJson('/api/promociones')->assertJsonPath('0.usos', 1);
    }

    public function test_una_promocion_de_otra_tienda_o_de_otra_medida_no_queda_escrita(): void
    {
        $promo = $this->crear(['tiendas' => [2]])->assertCreated()->json('id');
        $linea = fn (array $extra) => array_merge([
            'producto_id' => 10, 'cantidad' => 1, 'precio_unitario' => 1530000,
            'es_personalizado' => true, 'fabricar_pedido' => true, 'promocion_id' => $promo,
        ], $extra);

        $this->actingAs($this->vendedora())->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url'  => 'https://ejemplo/firma.png',
            'items' => [$linea(['combo_config_id' => 100])],
        ])->assertCreated();
        $this->assertNull(OrdenItem::first()->promocion_id, 'La tienda 1 no tiene esa promoción');

        $this->actingAs($this->vendedora(2))->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 2, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url'  => 'https://ejemplo/firma.png',
            'items' => [$linea(['combo_config_id' => 101])],
        ])->assertCreated();
        $this->assertNull(OrdenItem::latest('id')->first()->promocion_id, 'Es la de 1.40, no la de 1.00');
    }

    public function test_se_borra_si_nadie_la_ha_usado(): void
    {
        $id = $this->crear([])->assertCreated()->json('id');
        $this->actingAs($this->supervisora)->deleteJson("/api/promociones/{$id}")->assertOk();
        $this->assertSame(0, Promocion::count());
        $this->assertSame(0, DB::table('promocion_tienda')->count());
    }
}
