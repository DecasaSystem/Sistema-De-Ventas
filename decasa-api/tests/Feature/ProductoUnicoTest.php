<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Vender un mueble que ya existe y del que solo hay uno.
 *
 * La empresa guarda modelos que salieron una sola vez. No pagan catálogo ni
 * inventario —hay uno, no se fabrica más—, pero venderlos como "producto no
 * catalogado" les creaba una producción: al taller le caía un trabajo sobre un
 * mueble que ya estaba terminado.
 *
 * Lo que se comprueba es lo único que de verdad importa: que no le llegue nada
 * al taller, que no toque inventario, y que un diseño especial normal —que sí
 * hay que fabricar— siga generando su producción como siempre.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ProductoUnicoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            // Sin firma registrada no se puede vender: la pide el controlador.
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
            // La tabla real la tiene: de ella sale si la comision es del
            // equipo o de cada quien.
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
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
        // El consecutivo de la orden sale de aquí, por grupo de tiendas.
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 15, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 1, 'firma_url' => 'https://ejemplo/firma-vendedora.png',
            'created_at' => now(),
        ]);
    }

    /** El cuerpo de una venta, con los ítems que se le pasen. */
    private function payload(array $items, array $extra = []): array
    {
        return array_merge([
            'cliente_id'     => 1,
            'tienda_id'      => 1,
            'canal'          => 'fisica',
            'anticipo_monto' => 0,
            'firma_url'      => 'https://ejemplo/firma.png',
            'items'          => $items,
        ], $extra);
    }

    public function test_un_mueble_unico_no_le_crea_trabajo_al_taller(): void
    {
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->payload([[
                'nombre_custom'    => 'Consola art déco (única)',
                'categoria_custom' => 'consolas',
                'cantidad'         => 1,
                'precio_unitario'  => 1800000,
                'producto_unico'   => true,
            ]]))
            ->assertCreated();

        $item = OrdenItem::first();

        $this->assertTrue($item->producto_unico);
        // Sigue sin tocar inventario —no hay registro suyo— pero no va al taller.
        $this->assertTrue($item->es_personalizado);
        $this->assertSame(0, Produccion::count());
        $this->assertSame('producto_unico', $item->tipo_item);
    }

    public function test_un_diseno_especial_normal_si_sigue_yendo_al_taller(): void
    {
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->payload([[
                'nombre_custom'   => 'Comedor a la medida',
                'cantidad'        => 1,
                'precio_unitario' => 3000000,
            ]]))
            ->assertCreated();

        $this->assertSame(1, Produccion::count());
        $this->assertSame('diseno_especial', OrdenItem::first()->tipo_item);
    }

    public function test_el_mueble_unico_se_puede_entregar_en_el_acto(): void
    {
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->payload([[
                'nombre_custom'   => 'Baúl antiguo (único)',
                'cantidad'        => 1,
                'precio_unitario' => 900000,
                'producto_unico'  => true,
            ]], ['entrega_inmediata' => true]))
            ->assertCreated();

        // Está hecho y está ahí: la entrega inmediata no tiene por qué caerse,
        // aunque el ítem sea de los que no salen de inventario.
        $this->assertSame('entregado', Orden::first()->estado);
        $this->assertSame(0, Produccion::count());
    }

    public function test_un_diseno_especial_sigue_bloqueando_la_entrega_en_el_acto(): void
    {
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->payload([[
                'nombre_custom'   => 'Comedor a la medida',
                'cantidad'        => 1,
                'precio_unitario' => 3000000,
            ]], ['entrega_inmediata' => true]))
            ->assertStatus(422);
    }

    public function test_sin_precio_no_queda_esperando_una_cotizacion(): void
    {
        // Un mueble que ya está hecho no tiene nada que cotizarle al taller:
        // si sale en $0 es un olvido del vendedor, no una consulta de costo.
        $this->actingAs($this->vendedor())
            ->postJson('/api/ordenes', $this->payload([[
                'nombre_custom'   => 'Mesa única',
                'cantidad'        => 1,
                'precio_unitario' => 0,
                'producto_unico'  => true,
            ]]))
            ->assertCreated();

        $this->assertNotSame('pendiente_cotizacion', Orden::first()->estado);
    }
}
