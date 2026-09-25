<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un supervisor borra una venta (se subió dos veces, era de prueba…): se va
 * con lo que cuelga de ella, queda constancia, y con el número se escoge
 * entre dejar el hueco o correr las siguientes.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class EliminarOrdenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('tipo')->default('venta');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('grupo_secuencia', 50)->nullable();
            $t->string('numero_anulado', 30)->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->string('motivo_serie')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_personalizado')->default(true); $t->boolean('producto_unico')->default(false);
            $t->unsignedInteger('cantidad')->default(1); $t->unsignedInteger('cantidad_entregada')->default(0);
            $t->timestamp('devuelto_en')->nullable(); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 12, 2); $t->string('metodo')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('produccion_retornos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->timestamps();
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        // El inventario, para lo entregado que vuelve a la bodega.
        Schema::table('orden_items', function (Blueprint $t) {
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable(); $t->string('tipo'); $t->integer('cantidad');
            $t->string('motivo')->nullable(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });

        // Lo que mira el aviso al taller cuando se le cancela trabajo vivo.
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::table('orden_items', function (Blueprint $t) { $t->string('nombre_custom')->nullable(); });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('ordenes_eliminadas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->string('referencia')->nullable();
            $t->string('cliente_nombre')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('estado')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->decimal('pagado', 15, 2)->default(0);
            $t->string('numeracion'); $t->json('corridas')->nullable(); $t->string('motivo');
            $t->unsignedBigInteger('eliminada_por_id'); $t->json('datos')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Doña Marta', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4292]);
    }

    private function usuario(string $rol): Usuario
    {
        return Usuario::create(['nombre' => $rol, 'email' => "$rol@d.com", 'password' => 'x',
                                'rol' => $rol, 'created_at' => now()]);
    }

    private function venta(int $numero, string $estado = 'en_produccion'): Orden
    {
        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
            'estado' => $estado, 'valor_total' => 500000, 'tipo' => 'venta',
            'numero_orden' => $numero, 'grupo_secuencia' => 'armenia',
        ]);
        $item = DB::table('orden_items')->insertGetId([
            'orden_id' => $orden->id, 'cantidad' => 1,
            'cantidad_entregada' => $estado === 'entregado' ? 1 : 0,
        ]);
        $prod = DB::table('produccion')->insertGetId(['orden_item_id' => $item, 'estado' => 'en_proceso']);
        DB::table('produccion_pasos')->insert(['produccion_id' => $prod, 'estado' => 'completado']);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 250000, 'metodo' => 'efectivo']);
        DB::table('comisiones')->insert(['orden_id' => $orden->id, 'vendedor_id' => 1]);

        return $orden;
    }

    public function test_eliminar_corriendo_las_siguientes(): void
    {
        $repetida = $this->venta(4290);
        $o4291    = $this->venta(4291);
        $o4292    = $this->venta(4292);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/ordenes/{$repetida->id}", ['motivo' => 'Se subió dos veces', 'correr_numeracion' => true])
            ->assertOk()->assertJsonCount(2, 'corridas');

        $this->assertNull(Orden::find($repetida->id));
        $this->assertSame(4290, $o4291->fresh()->numero_orden);
        $this->assertSame(4291, $o4292->fresh()->numero_orden);
        $this->assertSame(4291, DB::table('orden_secuencias')->value('ultimo_numero'));

        // Se fue con lo suyo, y solo con lo suyo.
        $this->assertSame(0, DB::table('pagos')->where('orden_id', $repetida->id)->count());
        $this->assertSame(0, DB::table('comisiones')->where('orden_id', $repetida->id)->count());
        $this->assertSame(0, DB::table('orden_items')->where('orden_id', $repetida->id)->count());
        $this->assertSame(2, DB::table('produccion')->count());
        $this->assertSame(2, DB::table('pagos')->count());

        $constancia = DB::table('ordenes_eliminadas')->first();
        $this->assertSame('#4290', $constancia->referencia);
        $this->assertSame('correr', $constancia->numeracion);
        $this->assertSame('Se subió dos veces', $constancia->motivo);
        $this->assertEquals(250000, $constancia->pagado);
    }

    public function test_eliminar_dejando_el_hueco_no_mueve_a_nadie(): void
    {
        $repetida = $this->venta(4290);
        $o4291    = $this->venta(4291);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/ordenes/{$repetida->id}", ['motivo' => 'Era de prueba'])
            ->assertOk();

        $this->assertNull(Orden::find($repetida->id));
        $this->assertSame(4291, $o4291->fresh()->numero_orden);
        $this->assertSame(4292, DB::table('orden_secuencias')->value('ultimo_numero'));
        $this->assertSame('hueco', DB::table('ordenes_eliminadas')->value('numeracion'));
    }

    /** Una venta de catálogo ya entregada: 2 sillas salieron de la bodega. */
    private function entregadaDeCatalogo(int $numero): Orden
    {
        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
            'estado' => 'entregado', 'valor_total' => 800000, 'tipo' => 'venta',
            'numero_orden' => $numero, 'grupo_secuencia' => 'armenia',
        ]);
        DB::table('orden_items')->insert([
            'orden_id' => $orden->id, 'producto_id' => 7, 'es_personalizado' => false,
            'cantidad' => 2, 'cantidad_entregada' => 2,
        ]);
        DB::table('inventario')->insert(['producto_id' => 7, 'tienda_id' => 1, 'cantidad_disponible' => 5, 'cantidad_reservada' => 0]);

        return $orden;
    }

    public function test_una_entregada_se_borra_y_lo_entregado_vuelve_a_la_bodega(): void
    {
        $orden = $this->entregadaDeCatalogo(4290);

        $this->actingAs($this->usuario('supervisor'))
            ->getJson("/api/ordenes/{$orden->id}/eliminacion")
            ->assertOk()->assertJsonPath('bloqueos', [])->assertJsonPath('entregados', 2);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/ordenes/{$orden->id}", ['motivo' => 'Se subió dos veces'])
            ->assertOk();

        $this->assertNull(Orden::find($orden->id));
        $inv = DB::table('inventario')->where('producto_id', 7)->first();
        $this->assertSame(7, (int) $inv->cantidad_disponible);   // 5 + las 2 que habían salido
        $this->assertSame(0, (int) $inv->cantidad_reservada);    // libres: ya no son de nadie
        $this->assertSame(1, DB::table('inventario_movimientos')->where('tipo', 'entrada')->count());
    }

    public function test_una_entregada_se_borra_sin_tocar_la_bodega_si_asi_se_pide(): void
    {
        $orden = $this->entregadaDeCatalogo(4290);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/ordenes/{$orden->id}", ['motivo' => 'Venta real, mal registrada', 'devolver_entregado' => false])
            ->assertOk();

        $this->assertNull(Orden::find($orden->id));
        $this->assertSame(5, (int) DB::table('inventario')->where('producto_id', 7)->value('cantidad_disponible'));
    }

    public function test_el_historial_guarda_la_orden_como_estaba(): void
    {
        $orden = $this->venta(4290);
        $sup   = $this->usuario('supervisor');

        $this->actingAs($sup)->deleteJson("/api/ordenes/{$orden->id}", ['motivo' => 'Era de prueba'])->assertOk();

        $this->actingAs($sup)->getJson('/api/ordenes-eliminadas')
            ->assertOk()
            ->assertJsonPath('data.0.referencia', '#4290')
            ->assertJsonPath('data.0.motivo', 'Era de prueba')
            ->assertJsonPath('data.0.eliminada_por', 'supervisor')
            ->assertJsonPath('data.0.cliente_nombre', 'Doña Marta')
            ->assertJsonCount(1, 'data.0.datos.items')
            ->assertJsonCount(1, 'data.0.datos.pagos')
            ->assertJsonCount(1, 'data.0.datos.comisiones');

        $this->actingAs($this->usuario('vendedor'))->getJson('/api/ordenes-eliminadas')->assertStatus(403);
    }

    public function test_no_se_borra_lo_de_comision_pagada(): void
    {
        $sup = $this->usuario('supervisor');

        $pagada = $this->venta(4291);
        DB::table('comisiones')->where('orden_id', $pagada->id)->update(['estado' => 'pagada']);
        $this->actingAs($sup)->deleteJson("/api/ordenes/{$pagada->id}", ['motivo' => 'Se subió dos veces'])
            ->assertStatus(422);
        $this->assertNotNull(Orden::find($pagada->id));
    }

    public function test_un_vendedor_no_borra_una_venta_y_sin_motivo_tampoco(): void
    {
        $orden = $this->venta(4290);

        $this->actingAs($this->usuario('vendedor'))
            ->deleteJson("/api/ordenes/{$orden->id}", ['motivo' => 'Se subió dos veces'])
            ->assertStatus(422);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/ordenes/{$orden->id}")
            ->assertStatus(422);

        $this->assertNotNull(Orden::find($orden->id));
    }

    public function test_la_vista_previa_dice_que_se_lleva_y_que_se_correria(): void
    {
        $orden = $this->venta(4290);
        $this->venta(4291);

        $this->actingAs($this->usuario('supervisor'))
            ->getJson("/api/ordenes/{$orden->id}/eliminacion")
            ->assertOk()
            ->assertJsonPath('tiene_numero', true)
            ->assertJsonCount(1, 'corridas')
            ->assertJsonPath('bloqueos', [])
            ->assertJsonPath('pagos', 1);

        $this->assertNotNull(Orden::find($orden->id));
    }
}
