<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Support\RangoDeUsuarios;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Agregar a una orden ya entregada lo que se le olvidó al vendedor (usuario,
 * 2026-11-24). Solo un super admin.
 *
 * La señora Pérez se llevó el sofá Roma y las dos mesas de noche ($1.600.000)
 * y además una silla de $200.000 que nadie puso en la orden. La orden tiene
 * que subir a $1.800.000 y seguir entregada; la silla sale del inventario de
 * Norte si salió de ahí, y la bonificación sigue el valor nuevo.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class AgregarProductoEntregadoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC'));

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('acceso_despacho')->default(false); $t->boolean('ve_todas_ordenes')->default(true);
            $t->boolean('independiente')->default(false); $t->boolean('facturacion')->default(false);
            $t->boolean('es_super_admin')->default(false);
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
            $t->date('fecha_sugerida_vendedor')->nullable();
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
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
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
        RangoDeUsuarios::olvidar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El caso ──────────────────────────────────────────────────────────────

    private function persona(string $nombre, string $rol = 'supervisor', bool $superAdmin = false): Usuario
    {
        $u = Usuario::create(['nombre' => $nombre, 'email' => strtolower($nombre) . '@d.com', 'password' => 'x',
                              'rol' => $rol, 'created_at' => now()]);
        if ($superAdmin) $u->forceFill(['es_super_admin' => true])->save();
        RangoDeUsuarios::olvidar();

        return $u->fresh();
    }

    private function superAdmin(): Usuario
    {
        return $this->persona('David', 'supervisor', true);
    }

    /**
     * Sofá Roma ($1.000.000) y dos mesas de noche ($300.000 c/u) de Norte,
     * entregados el 10 de noviembre y pagados: $1.600.000. Sillas: 4 en Norte.
     */
    private function ordenEntregada(string $estado = 'entregado'): Orden
    {
        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Decasa Norte'], ['id' => 2, 'nombre' => 'Decasa El Edén']]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert([
            ['id' => 6,  'nombre' => 'Silla',         'precio_base' => 200000],
            ['id' => 7,  'nombre' => 'Mesa de noche', 'precio_base' => 300000],
            ['id' => 10, 'nombre' => 'Sofá Roma',     'precio_base' => 1000000],
        ]);
        DB::table('inventario')->insert([
            ['producto_id' => 6, 'tienda_id' => 1, 'cantidad_disponible' => 4, 'cantidad_reservada' => 1],
            ['producto_id' => 7, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0],
        ]);

        $vendedora = $this->persona('Gladys', 'vendedor');
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $vendedora->id, 'estado' => $estado,
                                'valor_total' => 1600000, 'serie' => 'FV', 'serie_numero' => 1300, 'canal' => 'fisica']);

        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 10, 'cantidad' => 1,
                           'cantidad_entregada' => 1, 'precio_unitario' => 1000000]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 7, 'cantidad' => 2,
                           'cantidad_entregada' => 2, 'precio_unitario' => 300000]);

        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 1600000, 'metodo' => 'efectivo',
                                    'tipo' => 'anticipo', 'created_at' => now()]);

        // La entrega de verdad, la del 10 de noviembre.
        $despacho = DB::table('despachos')->insertGetId(['tipo' => 'ruta', 'estado' => 'completado', 'fecha_despacho' => '2026-11-10']);
        DB::table('despacho_items')->insert(['despacho_id' => $despacho, 'orden_id' => $orden->id,
                                             'estado' => 'entregado', 'entregado_at' => '2026-11-10 20:00:00']);

        return $orden;
    }

    private function agregar(Usuario $quien, Orden $orden, array $datos): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($quien)->postJson("/api/ordenes/{$orden->id}/agregar-entregado", $datos + [
            'cantidad' => 1, 'motivo' => 'Se le olvidó agregar la silla',
        ]);
    }

    private function inv(int $producto, int $tienda): array
    {
        $f = DB::table('inventario')->where('producto_id', $producto)->where('tienda_id', $tienda)->first();

        return [(int) $f->cantidad_disponible, (int) $f->cantidad_reservada];
    }

    // ── Pruebas ──────────────────────────────────────────────────────────────

    public function test_la_silla_entra_entregada_la_orden_sube_y_baja_el_inventario(): void
    {
        $orden = $this->ordenEntregada();

        $this->agregar($this->superAdmin(), $orden, [
            'tipo' => 'catalogo', 'producto_id' => 6, 'sale_de' => 'tienda', 'tienda_id' => 1,
            'precio_unitario' => 200000,
        ])->assertOk();

        $orden->refresh();
        $this->assertSame('entregado', $orden->estado);
        $this->assertEquals(1800000, (float) $orden->valor_total);
        $this->assertEquals(200000, $orden->saldoPendiente());

        $silla = OrdenItem::where('orden_id', $orden->id)->where('producto_id', 6)->firstOrFail();
        $this->assertSame(1, (int) $silla->cantidad_entregada);
        $this->assertFalse((bool) $silla->es_personalizado);
        $this->assertSame(0, $silla->pendienteEntregar());

        // Salió de Norte: de 4 quedan 3, y lo apartado para otra orden sigue igual.
        $this->assertSame([3, 1], $this->inv(6, 1));
        $this->assertTrue(DB::table('inventario_movimientos')->where('producto_id', 6)->where('tipo', 'salida')->exists());

        // La entrega nueva queda con la fecha de la entrega de verdad: el día
        // que mira Facturación no se mueve.
        $nueva = DB::table('despacho_items')->where('orden_id', $orden->id)->orderByDesc('id')->first();
        $this->assertStringStartsWith('2026-11-10 20:00:00', (string) $nueva->entregado_at);
        $this->assertSame('entregado', $nueva->estado);

        // Queda en el historial.
        $cambios = collect(json_decode(DB::table('orden_ediciones')->where('orden_id', $orden->id)->value('cambios'), true));
        $this->assertTrue($cambios->contains('campo', "item_nuevo_{$silla->id}"));
        $this->assertTrue($cambios->contains('campo', 'valor_total'));
    }

    public function test_si_no_salio_del_inventario_no_toca_stock(): void
    {
        $orden = $this->ordenEntregada();

        $this->agregar($this->superAdmin(), $orden, [
            'tipo' => 'catalogo', 'producto_id' => 6, 'sale_de' => 'ninguna', 'precio_unitario' => 200000,
        ])->assertOk();

        $this->assertSame([4, 1], $this->inv(6, 1));
        $silla = OrdenItem::where('orden_id', $orden->id)->where('producto_id', 6)->firstOrFail();
        $this->assertTrue((bool) $silla->producto_unico);
        $this->assertSame(1, (int) $silla->cantidad_entregada);
        $this->assertFalse($silla->vaAlTaller());
        $this->assertSame(0, DB::table('produccion')->count());
        $this->assertSame('entregado', $orden->fresh()->estado);
    }

    public function test_algo_fuera_del_catalogo(): void
    {
        $orden = $this->ordenEntregada();

        $this->agregar($this->superAdmin(), $orden, [
            'tipo' => 'otro', 'nombre' => 'Cojín decorativo', 'precio_unitario' => 80000, 'cantidad' => 2,
        ])->assertOk();

        $cojin = OrdenItem::where('orden_id', $orden->id)->where('nombre_custom', 'Cojín decorativo')->firstOrFail();
        $this->assertSame('producto_unico', $cojin->tipo_item);
        $this->assertSame(2, (int) $cojin->cantidad_entregada);
        $this->assertEquals(1760000, (float) $orden->fresh()->valor_total);
        $this->assertSame('entregado', $orden->fresh()->estado);
    }

    public function test_la_bonificacion_sin_pagar_sigue_el_valor_nuevo(): void
    {
        $orden = $this->ordenEntregada();
        DB::table('comisiones')->insert([
            'orden_id' => $orden->id, 'vendedor_id' => $orden->vendedor_id, 'tienda_id' => 1, 'origen' => 'venta',
            'mes_venta' => '2026-11', 'valor_orden' => 1600000, 'fecha_venta' => '2026-11-05',
            'fecha_disponible' => '2026-12-20', 'estado' => 'pendiente',
        ]);

        $this->agregar($this->superAdmin(), $orden, [
            'tipo' => 'catalogo', 'producto_id' => 6, 'sale_de' => 'tienda', 'tienda_id' => 1, 'precio_unitario' => 200000,
        ])->assertOk();

        $this->assertEquals(1800000, (float) DB::table('comisiones')->where('orden_id', $orden->id)->sum('valor_orden'));
    }

    public function test_solo_el_super_admin(): void
    {
        $orden = $this->ordenEntregada();

        $this->agregar($this->persona('Manuela'), $orden, [
            'tipo' => 'catalogo', 'producto_id' => 6, 'sale_de' => 'tienda', 'tienda_id' => 1, 'precio_unitario' => 200000,
        ])->assertForbidden();

        $this->assertEquals(1600000, (float) $orden->fresh()->valor_total);
        $this->assertSame(2, OrdenItem::where('orden_id', $orden->id)->count());
    }

    public function test_solo_ordenes_entregadas(): void
    {
        $orden = $this->ordenEntregada('en_produccion');

        $this->agregar($this->superAdmin(), $orden, [
            'tipo' => 'catalogo', 'producto_id' => 6, 'sale_de' => 'tienda', 'tienda_id' => 1, 'precio_unitario' => 200000,
        ])->assertStatus(422);

        $this->assertSame(2, OrdenItem::where('orden_id', $orden->id)->count());
    }

    public function test_sin_stock_libre_no_hace_nada(): void
    {
        $orden = $this->ordenEntregada();

        // En Norte hay 4 sillas y 1 apartada: 3 libres.
        $this->agregar($this->superAdmin(), $orden, [
            'tipo' => 'catalogo', 'producto_id' => 6, 'sale_de' => 'tienda', 'tienda_id' => 1,
            'precio_unitario' => 200000, 'cantidad' => 4,
        ])->assertStatus(422);

        $this->assertSame([4, 1], $this->inv(6, 1));
        $this->assertSame(2, OrdenItem::where('orden_id', $orden->id)->count());
        $this->assertEquals(1600000, (float) $orden->fresh()->valor_total);
    }

    public function test_editar_orden_sigue_sin_dejar_agregar_a_una_entregada(): void
    {
        $orden = $this->ordenEntregada();

        $this->actingAs($this->superAdmin())->patchJson("/api/ordenes/{$orden->id}", [
            'items_nuevos' => [['producto_id' => 6, 'cantidad' => 1, 'precio_unitario' => 200000]],
        ])->assertStatus(422);
    }
}
