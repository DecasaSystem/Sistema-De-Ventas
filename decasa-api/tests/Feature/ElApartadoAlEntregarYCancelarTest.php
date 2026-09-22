<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Services\EntregaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El ciclo de vida del "Apartado", de punta a punta.
 *
 * La regla, en una línea: **En tienda = Apartado + Disponible**, siempre.
 *
 *   - Al vender      -> sube Apartado. "En tienda" no se mueve: la unidad
 *                       sigue ahí, solo que ya tiene dueño.
 *   - Al ENTREGAR    -> bajan los DOS. Ya no está apartada porque ya no está:
 *                       se vendió y salió de la tienda.
 *   - Al cancelar    -> baja Apartado y "En tienda" se queda: la unidad nunca
 *                       salió, vuelve a estar libre para vender.
 *   - Al revertir    -> suben los dos: la unidad volvió y sigue siendo de esa
 *                       orden.
 *
 * Y lo que NO tiene apartado no se suelta nunca: un mueble único, un producto
 * suelto sin ficha y lo ya devuelto para cambiarlo. Restarles dejaba el
 * contador en negativo, y un Apartado negativo infla el Disponible — la
 * tienda cree tener para vender una unidad que no tiene.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ElApartadoAlEntregarYCancelarTest extends TestCase
{
    private const TIENDA = 1, MESA = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_entregas')->default(true);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('canal')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamp('listo_entrega_at')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->integer('cantidad_entregada')->default(0);
            $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->date('devuelto_en')->nullable();
            $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        // El reparto por tela/medida: al bajar el stock base hay que cuadrarlo.
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id');
            $t->string('marca')->nullable(); $t->string('marca_tela')->nullable();
            $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id')->nullable();
            $t->unsignedBigInteger('opcion_id')->nullable(); $t->decimal('precio_adicional', 12, 2)->nullable();
        });
        Schema::create('inventario_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_variante_combinaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('config_id');
            $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('despachos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('conductor_id')->nullable(); $t->string('estado')->default('borrador');
            $t->string('nombre_ruta')->nullable(); $t->date('fecha_despacho')->nullable();
            $t->string('tipo')->nullable(); $t->unsignedBigInteger('entregado_por_id')->nullable();
            $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->boolean('es_directa')->default(false); $t->timestamps();
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id');
            $t->string('estado')->default('pendiente'); $t->integer('posicion')->default(1);
            $t->string('firma_omitida_motivo')->nullable(); $t->timestamp('entregado_at')->nullable();
            $t->unsignedBigInteger('entregado_por')->nullable(); $t->timestamps();
        });
        Schema::create('entrega_lineas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_item_id'); $t->unsignedBigInteger('orden_item_id');
            $t->integer('cantidad')->default(1); $t->string('resultado')->default('entregado');
            $t->timestamps();
        });
        Schema::create('devoluciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('orden_item_id')->nullable();
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->string('estado')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->string('estado')->default('pendiente');
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable();
            $t->date('fecha_real')->nullable(); $t->timestamps();
        });
        Schema::create('consultas_costo', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->string('estado')->nullable();
            $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
            $t->string('tipo')->nullable(); $t->timestamps();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('estado')->default('pendiente');
            $t->string('nombre')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo')->nullable();
            $t->string('titulo')->nullable(); $t->text('mensaje')->nullable();
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable();
            $t->timestamp('leida_at')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert(['id' => self::TIENDA, 'nombre' => 'Decasa Norte']);
        DB::table('productos')->insert(['id' => self::MESA, 'nombre' => 'Mesa']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);

        // Tres mesas en tienda, una ya apartada por la orden que se arma abajo.
        DB::table('inventario')->insert([
            'producto_id' => self::MESA, 'tienda_id' => self::TIENDA,
            'cantidad_disponible' => 3, 'cantidad_reservada' => 1,
        ]);
    }

    private function usuario(array $extra = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre' => 'Sup', 'email' => 'sup' . rand() . '@d.com', 'password' => 'x',
            'rol' => 'supervisor', 'firma_url' => 'f.png',
            'tienda_default_id' => self::TIENDA, 'created_at' => now(),
        ], $extra));
    }

    private function orden(array $extra = [], array $itemExtra = []): Orden
    {
        $orden = Orden::create(array_merge([
            'cliente_id' => 1, 'tienda_id' => self::TIENDA, 'vendedor_id' => 1,
            'estado' => 'pendiente_anticipo', 'valor_total' => 100000, 'canal' => 'fisica',
        ], $extra));

        OrdenItem::create(array_merge([
            'orden_id' => $orden->id, 'producto_id' => self::MESA, 'cantidad' => 1,
            'precio_unitario' => 100000, 'es_personalizado' => false, 'producto_unico' => false,
        ], $itemExtra));

        return $orden->fresh();
    }

    /** [en_tienda, apartado, disponible] — como se lee en la tarjeta. */
    private function tarjeta(): array
    {
        $i = DB::table('inventario')->where('producto_id', self::MESA)->where('tienda_id', self::TIENDA)->first();
        return [(int) $i->cantidad_disponible, (int) $i->cantidad_reservada,
                (int) $i->cantidad_disponible - (int) $i->cantidad_reservada];
    }

    // ── Entregar ─────────────────────────────────────────────────────────────

    public function test_al_entregar_bajan_los_dos_apartado_y_en_tienda(): void
    {
        $orden = $this->orden();
        $this->assertSame([3, 1, 2], $this->tarjeta(), 'antes: 3 en tienda, 1 apartada, 2 libres');

        EntregaService::entregarEnMostrador(
            $orden, [$orden->items->first()->id => 1], $this->usuario(), 'se la llevó',
        );

        // La mesa se vendió y salió: ya no está apartada porque ya no está.
        $this->assertSame([2, 0, 2], $this->tarjeta(), 'después: 2 en tienda, 0 apartadas, 2 libres');
    }

    public function test_la_entrega_deja_su_movimiento_de_salida(): void
    {
        $orden = $this->orden();

        EntregaService::entregarEnMostrador(
            $orden, [$orden->items->first()->id => 1], $this->usuario(), 'se la llevó',
        );

        $mov = DB::table('inventario_movimientos')->where('tipo', 'salida')->first();
        $this->assertNotNull($mov, 'sin este movimiento la auditoría no sabe que ya descontó');
        $this->assertStringStartsWith("Entrega orden #{$orden->id}", $mov->motivo);
    }

    public function test_entregada_ya_no_aparece_como_apartada_en_la_lista(): void
    {
        $orden = $this->orden();
        EntregaService::entregarEnMostrador(
            $orden, [$orden->items->first()->id => 1], $this->usuario(), 'se la llevó',
        );

        $r = $this->actingAs($this->usuario())
            ->getJson('/api/inventario/' . self::MESA . '/reservas?tienda_id=' . self::TIENDA)->assertOk();

        $this->assertCount(0, $r->json('ordenes'));
        $this->assertSame(0, $r->json('reservado_actual'), 'el número y la lista dicen lo mismo');
    }

    public function test_revertir_la_entrega_devuelve_los_dos(): void
    {
        $orden = $this->orden();
        $quien = $this->usuario();

        $entrega = EntregaService::entregarEnMostrador($orden, [$orden->items->first()->id => 1], $quien, 'se la llevó');
        $this->assertSame([2, 0, 2], $this->tarjeta());

        EntregaService::revertir($entrega->fresh(), $quien, 'se marcó por error');

        $this->assertSame([3, 1, 2], $this->tarjeta(), 'vuelve a estar, y sigue siendo de esa orden');
    }

    // ── Cancelar ─────────────────────────────────────────────────────────────

    public function test_al_cancelar_se_suelta_el_apartado_y_la_unidad_se_queda(): void
    {
        $orden = $this->orden();

        $this->actingAs($this->usuario())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertOk();

        $this->assertSame([3, 0, 3], $this->tarjeta(), 'la mesa nunca salió: queda libre para vender');
    }

    /**
     * Un mueble único no aparta nada al venderse (no está en catálogo), así
     * que cancelarlo no puede soltar nada. Antes le restaba igual y el
     * contador se iba a negativo.
     */
    public function test_cancelar_un_mueble_unico_no_toca_el_apartado(): void
    {
        $orden = $this->orden(['estado' => 'pendiente_anticipo'], ['producto_unico' => true]);

        $this->actingAs($this->usuario())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertOk();

        $this->assertSame([3, 1, 2], $this->tarjeta(), 'el apartado de la otra orden sigue intacto');
    }

    /**
     * Lo devuelto para cambiarlo por otro producto ya soltó su apartado al
     * entregarse. Restarlo otra vez al cancelar se comía el apartado de la
     * orden de otro cliente.
     */
    public function test_cancelar_con_un_item_devuelto_no_lo_suelta_dos_veces(): void
    {
        $orden = $this->orden([], ['devuelto_en' => now()->toDateString()]);

        $this->actingAs($this->usuario())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertOk();

        $this->assertSame([3, 1, 2], $this->tarjeta());
    }

    /** Una cotización todavía no aparta nada: cancelarla no suelta nada. */
    public function test_cancelar_una_cotizacion_no_toca_el_apartado(): void
    {
        $orden = $this->orden(['estado' => 'cotizacion']);

        $this->actingAs($this->usuario())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertOk();

        $this->assertSame([3, 1, 2], $this->tarjeta());
    }

    /** Y un borrador tampoco, que ya estaba cubierto: se comprueba que siga así. */
    public function test_cancelar_un_borrador_no_toca_el_apartado(): void
    {
        $orden = $this->orden(['estado' => 'borrador']);

        $this->actingAs($this->usuario())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertOk();

        $this->assertSame([3, 1, 2], $this->tarjeta());
    }

    /** El apartado nunca puede quedar en negativo: eso infla el Disponible. */
    public function test_el_apartado_nunca_queda_en_negativo(): void
    {
        foreach ([['producto_unico' => true], ['devuelto_en' => now()->toDateString()]] as $extra) {
            $orden = $this->orden([], $extra);
            $this->actingAs($this->usuario())
                ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertOk();
        }

        [, $apartado, $disponible] = $this->tarjeta();
        $this->assertGreaterThanOrEqual(0, $apartado);
        $this->assertSame(2, $disponible, 'y el Disponible sigue diciendo la verdad');
    }
}
