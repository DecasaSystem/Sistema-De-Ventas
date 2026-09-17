<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\TelaReserva;
use App\Models\Usuario;
use App\Services\ConsumoTelas;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El inventario de telas se mueve solo con las ventas.
 *
 * A cada producto tapizado se le dice cuántos metros lleva. Al vender uno
 * para fabricar con una tela del catálogo, esos metros quedan apartados; al
 * terminar la pieza se descuentan; al cancelar se sueltan. Todo cuelga de
 * un interruptor que nace apagado.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ConsumoTelasTest extends TestCase
{
    private const SOFA   = 9;
    private const TELA   = 'Lafayette · Chenille · Gris';
    private const MEDIDA = 31;   // config_id de "1.60"

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('facturacion')->default(false); $t->boolean('acceso_entregas')->default(false);
            $t->boolean('notif_asignar_fecha')->default(false);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('precio_base', 12, 2)->default(0); $t->boolean('es_tapizado')->default(false);
            $t->boolean('activo')->default(true);
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
            $t->string('grupo_secuencia')->nullable(); $t->string('numero_anulado', 30)->nullable();
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
            $t->boolean('retapizar')->default(false);
            $t->boolean('es_regalo')->default(false); $t->boolean('usa_stock_tienda')->default(false);
            $t->json('specs_personalizacion')->nullable(); $t->string('boceto_url')->nullable();
            $t->json('boceto_fotos')->nullable(); $t->date('fecha_entrega_prom')->nullable();
            $t->date('devuelto_en')->nullable(); $t->text('motivo_devolucion')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
            // Lo que lleva una pieza para la Reserva, que no cuelga de una orden.
            $t->string('destino')->default('orden'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->string('variante_detalle')->nullable(); $t->unsignedInteger('cantidad')->default(1);
            $t->json('specs')->nullable(); $t->unsignedBigInteger('creado_por')->nullable();
            $t->timestamp('depositado_at')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal'); $t->unsignedTinyInteger('orden')->default(1);
            $t->string('estado')->default('pendiente'); $t->timestamp('iniciado_at')->nullable();
            $t->timestamp('completado_at')->nullable(); $t->unsignedBigInteger('completado_por')->nullable();
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable();
            $t->text('notas')->nullable(); $t->timestamps();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1); $t->string('descripcion')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(1);
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
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
        Schema::create('consultas_costo', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('asignado_a_id')->nullable();
            $t->string('estado')->default('pendiente'); $t->timestamp('respondido_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 15, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // Lo propio de esta función.
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('marca'); $t->string('tipo'); $t->string('color');
            $t->string('referencia')->nullable(); $t->string('textura')->nullable(); $t->string('foto_url')->nullable();
            $t->boolean('activo')->default(true);
            $t->decimal('metros_disponibles', 8, 2)->default(0); $t->decimal('metros_reservados', 8, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('tipos_variante', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('afecta_precio')->default(true); $t->boolean('activo')->default(true);
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id'); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id');
            $t->unsignedBigInteger('opcion_id'); $t->decimal('precio_adicional', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('producto_consumo_telas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('config_id')->nullable();
            $t->decimal('metros', 8, 2); $t->timestamps();
        });
        Schema::create('tela_reservas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->unsignedBigInteger('produccion_id')->nullable();
            $t->unsignedBigInteger('catalogo_tela_id');
            $t->decimal('metros', 8, 2); $t->string('estado', 20)->default('reservada');
            $t->string('detalle', 200)->nullable(); $t->timestamps();
        });

        $this->completarEsquemaDeEntregas();

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert([
            ['id' => self::SOFA, 'nombre' => 'SOFA ROMA', 'categoria' => 'sofas', 'precio_base' => 2000000, 'es_tapizado' => true],
            ['id' => 10,        'nombre' => 'MESA CENTRO', 'categoria' => 'mesas', 'precio_base' => 400000, 'es_tapizado' => false],
        ]);
        DB::table('catalogo_telas')->insert([
            'id' => 1, 'marca' => 'Lafayette', 'tipo' => 'Chenille', 'color' => 'Gris',
            'metros_disponibles' => 20, 'metros_reservados' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // El sofá viene en dos medidas; la de 1.60 gasta más tela.
        DB::table('tipos_variante')->insert(['id' => 3, 'nombre' => 'Medidas']);
        DB::table('tipo_variante_opciones')->insert([
            ['id' => 5, 'tipo_variante_id' => 3, 'nombre' => '1.40'],
            ['id' => 6, 'tipo_variante_id' => 3, 'nombre' => '1.60'],
        ]);
        DB::table('producto_variante_configs')->insert([
            ['id' => 30,           'producto_id' => self::SOFA, 'tipo_variante_id' => 3, 'opcion_id' => 5],
            ['id' => self::MEDIDA, 'producto_id' => self::SOFA, 'tipo_variante_id' => 3, 'opcion_id' => 6],
        ]);
        DB::table('configuracion')->insert(['clave' => ConsumoTelas::CLAVE, 'valor' => '0']);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 1, 'firma_url' => 'https://ejemplo/firma.png', 'created_at' => now(),
        ]);
    }

    private function jefe(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
            'gestiona_produccion' => true, 'acceso_produccion' => true, 'created_at' => now(),
        ]);
    }

    private function encender(): void
    {
        ConsumoTelas::definir(true);
    }

    private function consumo(float $metros, ?int $configId = null): void
    {
        DB::table('producto_consumo_telas')->insert([
            'producto_id' => self::SOFA, 'config_id' => $configId, 'metros' => $metros,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Un sofá del catálogo mandado a fabricar con la tela elegida. */
    private function itemAFabricar(int $cantidad = 1, string $tela = self::TELA, array $extra = []): array
    {
        return array_merge([
            'producto_id'           => self::SOFA,
            'cantidad'              => $cantidad,
            'precio_unitario'       => 2000000,
            'es_personalizado'      => true,
            'fabricar_pedido'       => true,
            'specs_personalizacion' => ['tela' => $tela],
        ], $extra);
    }

    private function vender(array $items, array $extra = [])
    {
        return $this->actingAs($this->vendedor())->postJson('/api/ordenes', array_merge([
            'cliente_id'     => 1,
            'tienda_id'      => 1,
            'canal'          => 'fisica',
            'anticipo_monto' => 0,
            'firma_url'      => 'https://ejemplo/firma.png',
            'items'          => $items,
        ], $extra));
    }

    private function tela(): object
    {
        return DB::table('catalogo_telas')->find(1);
    }

    // ── Interruptor ──────────────────────────────────────────────────────────

    public function test_apagado_no_toca_las_telas_aunque_el_consumo_este_cargado(): void
    {
        $this->consumo(6);

        $this->vender([$this->itemAFabricar(2)])->assertCreated();

        $this->assertSame(0, TelaReserva::count());
        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
        $this->assertEquals(20, (float) $this->tela()->metros_disponibles);
    }

    public function test_apagarlo_suelta_lo_que_estaba_apartado(): void
    {
        $this->consumo(6);
        $this->encender();
        $this->vender([$this->itemAFabricar(2)])->assertCreated();
        $this->assertEquals(12, (float) $this->tela()->metros_reservados);

        $this->actingAs($this->jefe())
            ->putJson('/api/telas/consumo/activo', ['activo' => false])
            ->assertOk()
            ->assertJsonPath('activo', false)
            ->assertJsonPath('liberadas', 1);

        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
        $this->assertSame('liberada', TelaReserva::first()->estado);

        // Y terminada la pieza después, ya no descuenta nada: no hay reserva.
        Produccion::first()->update(['estado' => 'listo']);
        $this->assertEquals(20, (float) $this->tela()->metros_disponibles);
    }

    public function test_solo_el_supervisor_enciende_y_carga_consumos(): void
    {
        $this->actingAs($this->vendedor())->putJson('/api/telas/consumo/activo', ['activo' => true])->assertForbidden();
        $this->actingAs($this->vendedor())->putJson('/api/telas/consumo', ['producto_id' => self::SOFA, 'metros' => 6])->assertForbidden();
        $this->assertFalse(ConsumoTelas::activo());
    }

    // ── Apartar al vender ────────────────────────────────────────────────────

    public function test_vender_para_fabricar_aparta_los_metros_de_la_tela(): void
    {
        $this->consumo(6);
        $this->encender();

        $this->vender([$this->itemAFabricar(2)])->assertCreated();

        $tela = $this->tela();
        $this->assertEquals(12, (float) $tela->metros_reservados);
        // Apartado, no gastado: el rollo sigue entero hasta que el taller termine.
        $this->assertEquals(20, (float) $tela->metros_disponibles);

        $reserva = TelaReserva::first();
        $this->assertSame('reservada', $reserva->estado);
        $this->assertEquals(12, (float) $reserva->metros);
        $this->assertSame(OrdenItem::first()->id, (int) $reserva->orden_item_id);
        $this->assertStringContainsString('SOFA ROMA ×2', $reserva->detalle);
    }

    public function test_si_no_alcanza_la_tela_la_venta_no_se_crea(): void
    {
        $this->consumo(6);
        $this->encender();

        $this->vender([$this->itemAFabricar(4)])   // 24 m contra 20 libres
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '«SOFA ROMA ×4» necesita 24 m de Lafayette · Chenille · Gris y solo hay 20 m libres. Elige otra tela o recarga el inventario de telas.']);

        $this->assertSame(0, Orden::count());
        $this->assertSame(0, TelaReserva::count());
        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
    }

    public function test_la_medida_manda_sobre_el_consumo_base(): void
    {
        $this->consumo(6);
        $this->consumo(8, self::MEDIDA);
        $this->encender();

        $this->vender([$this->itemAFabricar(1, self::TELA, ['combo_config_id' => self::MEDIDA])])->assertCreated();

        $this->assertEquals(8, (float) $this->tela()->metros_reservados);
    }

    public function test_sin_consumo_cargado_o_sin_tela_del_catalogo_no_aparta_nada(): void
    {
        $this->encender();

        // Producto sin metros cargados.
        $this->vender([$this->itemAFabricar(1)])->assertCreated();
        // Tela escrita a mano, que no está en el catálogo.
        $this->consumo(6);
        $this->vender([$this->itemAFabricar(1, 'Cuero sintético negro')])->assertCreated();
        // Un mueble que no es de catálogo: no hay a qué mirarle el consumo.
        $this->vender([['nombre_custom' => 'Poltrona a la medida', 'cantidad' => 1, 'precio_unitario' => 900000,
                        'specs_personalizacion' => ['tela' => self::TELA]]])->assertCreated();

        $this->assertSame(3, Orden::count());
        $this->assertSame(0, TelaReserva::count());
        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
    }

    public function test_un_borrador_no_aparta_tela(): void
    {
        $this->consumo(6);
        $this->encender();

        $this->vender([$this->itemAFabricar(1)], ['guardar_borrador' => true])->assertCreated();

        $this->assertSame('borrador', Orden::first()->estado);
        $this->assertSame(0, TelaReserva::count());
    }

    public function test_el_cambio_de_tela_a_un_mueble_de_stock_tambien_aparta(): void
    {
        DB::table('inventario')->insert(['producto_id' => self::SOFA, 'tienda_id' => 1, 'cantidad_disponible' => 1]);
        $this->consumo(6);
        $this->encender();

        $this->vender([[
            'producto_id' => self::SOFA, 'cantidad' => 1, 'precio_unitario' => 2000000,
            'retapizar' => true, 'specs_personalizacion' => ['tela' => self::TELA],
        ]])->assertCreated();

        $this->assertTrue(OrdenItem::first()->retapizar);
        $this->assertEquals(6, (float) $this->tela()->metros_reservados);
    }

    // ── Descontar y soltar ───────────────────────────────────────────────────

    public function test_al_terminar_la_pieza_se_descuenta_lo_apartado(): void
    {
        $this->consumo(6);
        $this->encender();
        $this->vender([$this->itemAFabricar(2)])->assertCreated();

        Produccion::first()->update(['estado' => 'listo', 'fecha_real' => now()->toDateString()]);

        $tela = $this->tela();
        $this->assertEquals(8, (float) $tela->metros_disponibles);
        $this->assertEquals(0, (float) $tela->metros_reservados);
        $this->assertSame('consumida', TelaReserva::first()->estado);

        // Marcarla entregada después no la descuenta dos veces.
        Produccion::first()->update(['estado' => 'entregado']);
        $this->assertEquals(8, (float) $this->tela()->metros_disponibles);
    }

    public function test_cancelar_la_pieza_suelta_la_tela(): void
    {
        $this->consumo(6);
        $this->encender();
        $this->vender([$this->itemAFabricar(2)])->assertCreated();

        Produccion::first()->update(['estado' => 'cancelado']);

        $tela = $this->tela();
        $this->assertEquals(20, (float) $tela->metros_disponibles);
        $this->assertEquals(0, (float) $tela->metros_reservados);
        $this->assertSame('liberada', TelaReserva::first()->estado);
    }

    public function test_cancelar_la_orden_suelta_la_tela(): void
    {
        $this->consumo(6);
        $this->encender();
        $this->vender([$this->itemAFabricar(2)])->assertCreated();

        $this->actingAs($this->jefe())
            ->patchJson('/api/ordenes/' . Orden::first()->id . '/estado', ['estado' => 'cancelado'])
            ->assertOk();

        $this->assertSame('cancelado', Produccion::first()->estado);
        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
        $this->assertSame('liberada', TelaReserva::first()->estado);
    }

    public function test_quitar_el_item_al_editar_suelta_la_tela(): void
    {
        $this->consumo(6);
        $this->encender();
        $this->vender([$this->itemAFabricar(2), ['producto_id' => 10, 'cantidad' => 1, 'precio_unitario' => 400000, 'es_personalizado' => true]])
            ->assertCreated();
        $this->assertEquals(12, (float) $this->tela()->metros_reservados);

        $sofa = OrdenItem::where('producto_id', self::SOFA)->first();
        $this->actingAs($this->jefe())
            ->patchJson('/api/ordenes/' . Orden::first()->id, ['items_eliminar' => [$sofa->id]])
            ->assertOk();

        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
        $this->assertSame('liberada', TelaReserva::first()->estado);
    }

    public function test_cambiarle_la_tela_al_editar_mueve_la_reserva(): void
    {
        DB::table('catalogo_telas')->insert([
            'id' => 2, 'marca' => 'Lafayette', 'tipo' => 'Chenille', 'color' => 'Azul',
            'metros_disponibles' => 5, 'metros_reservados' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->consumo(6);
        $this->encender();
        $this->vender([$this->itemAFabricar(1)])->assertCreated();

        $item = OrdenItem::first();
        $this->actingAs($this->jefe())
            ->patchJson('/api/ordenes/' . Orden::first()->id, [
                'items' => [['id' => $item->id, 'specs_personalizacion' => ['tela' => 'Lafayette · Chenille · Azul']]],
            ])
            ->assertOk();

        // La gris queda libre; la azul apartada, aunque le falte tela: al
        // editar no se tumba la orden, se ve en Telas que falta comprar.
        $this->assertEquals(0,  (float) DB::table('catalogo_telas')->find(1)->metros_reservados);
        $this->assertEquals(6,  (float) DB::table('catalogo_telas')->find(2)->metros_reservados);
        $this->assertSame(['liberada', 'reservada'], TelaReserva::orderBy('id')->pluck('estado')->all());
    }

    // ── Producir para la Reserva (sin orden) ─────────────────────────────────

    /** El sofá ya tiene registrada esta tela como variante. */
    private function varianteGris(): int
    {
        DB::table('producto_variantes')->insert([
            'id' => 40, 'producto_id' => self::SOFA, 'marca' => 'Lafayette', 'marca_tela' => 'Chenille', 'nombre_color' => 'Gris',
        ]);
        return 40;
    }

    private function producir(int $cantidad, array $extra = [])
    {
        return $this->actingAs($this->jefe())->postJson('/api/produccion/producir', array_merge([
            'modo'        => 'catalogo',
            'producto_id' => self::SOFA,
            'cantidad'    => $cantidad,
            'variante_id' => $this->varianteGris(),
        ], $extra));
    }

    public function test_producir_para_la_reserva_aparta_la_tela_de_la_variante(): void
    {
        $this->consumo(6);
        $this->encender();

        $this->producir(2)->assertCreated();

        $p = Produccion::first();
        $this->assertSame('reserva', $p->destino);
        $this->assertEquals(12, (float) $this->tela()->metros_reservados);

        $reserva = TelaReserva::first();
        $this->assertSame($p->id, (int) $reserva->produccion_id);
        $this->assertNull($reserva->orden_item_id);
        $this->assertStringContainsString('(Reserva)', $reserva->detalle);

        // Terminada la pieza, se descuenta; y al depositarla en la Reserva no
        // se descuenta otra vez.
        $p->update(['estado' => 'listo', 'fecha_real' => now()->toDateString()]);
        $this->assertEquals(8, (float) $this->tela()->metros_disponibles);
        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
        $p->update(['estado' => 'en_reserva']);
        $this->assertEquals(8, (float) $this->tela()->metros_disponibles);
    }

    public function test_producir_sin_tela_suficiente_no_se_crea(): void
    {
        $this->consumo(6);
        $this->encender();

        $resp = $this->producir(4)->assertStatus(422);   // 24 m contra 20 libres
        $this->assertStringContainsString('necesita 24 m de Lafayette · Chenille · Gris y solo hay 20 m libres', $resp->json('message'));

        $this->assertSame(0, Produccion::count());
        $this->assertSame(0, TelaReserva::count());
    }

    public function test_cancelar_la_pieza_de_reserva_suelta_la_tela(): void
    {
        $this->consumo(6);
        $this->encender();
        $this->producir(1)->assertCreated();
        $this->assertEquals(6, (float) $this->tela()->metros_reservados);

        Produccion::first()->update(['estado' => 'cancelado']);

        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
        $this->assertEquals(20, (float) $this->tela()->metros_disponibles);
        $this->assertSame('liberada', TelaReserva::first()->estado);
    }

    public function test_apagado_producir_no_toca_las_telas(): void
    {
        $this->consumo(6);

        $this->producir(2)->assertCreated();

        $this->assertSame(0, TelaReserva::count());
        $this->assertEquals(0, (float) $this->tela()->metros_reservados);
    }

    // ── El panel de Telas ────────────────────────────────────────────────────

    public function test_el_panel_lista_los_tapizados_con_sus_medidas(): void
    {
        $this->consumo(6);
        $this->consumo(8, self::MEDIDA);

        $resp = $this->actingAs($this->jefe())->getJson('/api/telas/consumo')->assertOk();

        $resp->assertJsonPath('activo', false)
             ->assertJsonCount(1, 'productos')          // la mesa no es tapizada
             ->assertJsonPath('productos.0.nombre', 'SOFA ROMA')
             ->assertJsonPath('productos.0.metros', 6.0)
             ->assertJsonCount(2, 'productos.0.medidas')
             ->assertJsonPath('productos.0.medidas.0.nombre', '1.40')
             ->assertJsonPath('productos.0.medidas.0.metros', null)
             ->assertJsonPath('productos.0.medidas.1.nombre', '1.60')
             ->assertJsonPath('productos.0.medidas.1.metros', 8.0);
    }

    public function test_guardar_metros_crea_actualiza_y_borra(): void
    {
        $jefe = $this->jefe();

        $this->actingAs($jefe)->putJson('/api/telas/consumo', ['producto_id' => self::SOFA, 'metros' => 6.5])
            ->assertOk()->assertJsonPath('metros', 6.5);
        $this->actingAs($jefe)->putJson('/api/telas/consumo', ['producto_id' => self::SOFA, 'metros' => 7])
            ->assertOk();
        $this->assertSame(7.0, ConsumoTelas::consumoDe(self::SOFA));

        $this->actingAs($jefe)->putJson('/api/telas/consumo', ['producto_id' => self::SOFA, 'config_id' => self::MEDIDA, 'metros' => 9])
            ->assertOk();
        $this->assertSame(9.0, ConsumoTelas::consumoDe(self::SOFA, self::MEDIDA));
        // La otra medida no tiene metros propios: usa la base.
        $this->assertSame(7.0, ConsumoTelas::consumoDe(self::SOFA, 30));

        // Vacío borra.
        $this->actingAs($jefe)->putJson('/api/telas/consumo', ['producto_id' => self::SOFA, 'metros' => null])
            ->assertOk()->assertJsonPath('metros', null);
        $this->assertNull(ConsumoTelas::consumoDe(self::SOFA));
        // Y una medida de otro producto no se acepta.
        $this->actingAs($jefe)->putJson('/api/telas/consumo', ['producto_id' => 10, 'config_id' => self::MEDIDA, 'metros' => 2])
            ->assertStatus(422);
    }

    public function test_validar_dice_cuanta_tela_necesita_el_producto(): void
    {
        $this->consumo(6);
        $this->encender();

        $this->actingAs($this->vendedor())
            ->getJson('/api/inventario-telas/validar?marca=Lafayette&tipo=Chenille&color=Gris&producto_id=' . self::SOFA . '&cantidad=4')
            ->assertOk()
            ->assertJsonPath('metros', 20.0)
            ->assertJsonPath('metros_necesarios', 24.0)
            ->assertJsonPath('suficiente', false);

        // Sin producto, la respuesta de siempre.
        $this->actingAs($this->vendedor())
            ->getJson('/api/inventario-telas/validar?marca=Lafayette&tipo=Chenille&color=Gris')
            ->assertOk()
            ->assertJsonPath('metros_necesarios', null)
            ->assertJsonPath('suficiente', true);
    }
}
