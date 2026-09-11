<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Producir contra stock: fabricar para la Reserva de Fábrica, sin cliente ni
 * orden. Usa el mismo flujo de pasos que cualquier producción; al completar
 * el último, las unidades entran al inventario de fábrica en vez de a
 * "listo para entrega".
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ProducirParaReservaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('apto_produccion')->default(false);
            $t->boolean('no_usa_programa')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false);
            $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable();
            $t->decimal('precio_base', 12, 2)->default(0);
            $t->boolean('es_tapizado')->default(false); $t->boolean('tiene_tallas')->default(false);
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->string('marca', 100)->nullable();
            $t->string('marca_tela', 100)->nullable(); $t->string('nombre_color', 100)->nullable();
            $t->string('medida', 50)->nullable(); $t->decimal('precio_variante', 12, 2)->nullable();
            $t->string('foto_url', 500)->nullable(); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('marca', 100); $t->string('tipo', 100); $t->string('color', 100);
            $t->boolean('activo')->default(true); $t->decimal('metros_disponibles', 10, 2)->default(0);
            $t->decimal('metros_reservados', 10, 2)->default(0); $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable();
            $t->string('destino')->default('orden');
            $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedInteger('cantidad')->default(1); $t->json('specs')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamp('depositado_at')->nullable();
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable();
            $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal');
            $t->unsignedTinyInteger('orden')->default(1); $t->string('estado')->default('pendiente');
            $t->timestamp('iniciado_at')->nullable(); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->json('trabajadores')->nullable();
            $t->unsignedTinyInteger('rechazos')->default(0); $t->text('ultimo_rechazo')->nullable();
            $t->unsignedBigInteger('rechazado_por_id')->nullable(); $t->timestamp('rechazado_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1); $t->string('descripcion')->nullable();
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('asignado_por')->nullable(); $t->timestamp('asignado_at')->nullable();
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable();
            $t->string('comentario', 300)->nullable();
            $t->unsignedBigInteger('calificado_por')->nullable(); $t->timestamp('calificado_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_fijadas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Fábrica', 'es_fabrica' => true]);
        DB::table('tipos_proceso')->insert([
            ['id' => 1, 'clave' => 'ebanisteria', 'nombre' => 'Ebanistería', 'activo' => true, 'orden' => 1],
            ['id' => 2, 'clave' => 'tapizado',    'nombre' => 'Tapizado',    'activo' => true, 'orden' => 2],
            ['id' => 3, 'clave' => 'despacho',    'nombre' => 'Despacho',    'activo' => true, 'orden' => 3],
        ]);
    }

    private function jefe(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
                                'gestiona_produccion' => true, 'acceso_produccion' => true, 'created_at' => now()]);
    }

    private function trabajador(array $procesos): Usuario
    {
        $u = Usuario::create(['nombre' => 'Adrián', 'email' => uniqid() . '@d.com', 'password' => 'x', 'rol' => 'trabajador',
                              'apto_produccion' => true, 'created_at' => now()]);
        foreach ($procesos as $tipoProcesoId) {
            DB::table('proceso_trabajadores')->insert(['usuario_id' => $u->id, 'tipo_proceso_id' => $tipoProcesoId]);
        }
        return $u;
    }

    public function test_solo_quien_gestiona_produccion_puede_producir(): void
    {
        $vendedor = Usuario::create(['nombre' => 'V', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($vendedor)->postJson('/api/produccion/producir', [
            'modo' => 'catalogo', 'producto_id' => 1, 'cantidad' => 1,
            'pasos' => [['tipo_proceso' => 'ebanisteria', 'orden' => 1]],
        ])->assertStatus(403);
    }

    public function test_producir_crea_la_produccion_sin_orden_y_arranca_el_primer_paso(): void
    {
        $producto = Producto::create(['nombre' => 'Consola alistonada', 'categoria' => 'consolas', 'precio_base' => 500000]);

        $resp = $this->actingAs($this->jefe())->postJson('/api/produccion/producir', [
            'modo' => 'catalogo',
            'producto_id' => $producto->id,
            'cantidad' => 3,
            'pasos' => [
                ['tipo_proceso' => 'ebanisteria', 'orden' => 1],
                ['tipo_proceso' => 'tapizado', 'orden' => 2],
            ],
        ])->assertStatus(201)->json();

        $this->assertSame('reserva', $resp['destino']);
        $this->assertNull($resp['orden_item_id']);
        $this->assertSame('en_proceso', $resp['estado']);
        $this->assertSame(3, $resp['cantidad']);

        $prod = Produccion::find($resp['id']);
        // Sin paso de despacho: para la Reserva no hay entrega.
        $this->assertSame(2, ProduccionPaso::where('produccion_id', $prod->id)->count());
        $this->assertSame(0, ProduccionPaso::where('produccion_id', $prod->id)->where('tipo_proceso', 'despacho')->count());
        $this->assertSame('en_proceso', ProduccionPaso::where('produccion_id', $prod->id)->where('orden', 1)->value('estado'));
    }

    public function test_al_completar_todos_los_pasos_las_unidades_entran_a_la_reserva(): void
    {
        $producto = Producto::create(['nombre' => 'Consola alistonada', 'categoria' => 'consolas', 'precio_base' => 500000]);
        $ebanista = $this->trabajador([1]);
        $tapicero = $this->trabajador([2]);

        $prodId = $this->actingAs($this->jefe())->postJson('/api/produccion/producir', [
            'modo' => 'catalogo', 'producto_id' => $producto->id, 'cantidad' => 4,
            'pasos' => [
                ['tipo_proceso' => 'ebanisteria', 'orden' => 1],
                ['tipo_proceso' => 'tapizado', 'orden' => 2],
            ],
        ])->json('id');

        $pasoEbanisteria = ProduccionPaso::where('produccion_id', $prodId)->where('orden', 1)->first();
        $pasoTapizado     = ProduccionPaso::where('produccion_id', $prodId)->where('orden', 2)->first();

        $this->actingAs($ebanista)->patchJson("/api/produccion/pasos/{$pasoEbanisteria->id}/completar", [
            'trabajadores' => [['usuario_id' => $ebanista->id, 'horas' => 4]],
        ])->assertOk();

        // A media producción no hay nada en la Reserva todavía.
        $this->assertNull(Inventario::where('producto_id', $producto->id)->where('tienda_id', 1)->first());
        $this->assertSame('en_proceso', $pasoTapizado->fresh()->estado);

        $this->actingAs($tapicero)->patchJson("/api/produccion/pasos/{$pasoTapizado->id}/completar", [
            'trabajadores' => [['usuario_id' => $tapicero->id, 'horas' => 3]],
        ])->assertOk();

        $prod = Produccion::find($prodId);
        $this->assertSame('en_reserva', $prod->estado);
        $this->assertNotNull($prod->depositado_at);

        $inv = Inventario::where('producto_id', $producto->id)->where('tienda_id', 1)->first();
        $this->assertNotNull($inv);
        $this->assertSame(4, $inv->cantidad_disponible);

        $this->assertSame(1, DB::table('inventario_movimientos')
            ->where('producto_id', $producto->id)->where('tipo', 'entrada')
            ->where('motivo', "Producción interna #{$prodId}")->count());
    }

    public function test_producir_con_tela_nueva_crea_la_variante_y_deposita_el_desglose(): void
    {
        $producto = Producto::create(['nombre' => 'Sofá Mónaco', 'categoria' => 'sofas', 'precio_base' => 1500000, 'es_tapizado' => true]);
        $ebanista = $this->trabajador([1]);

        $resp = $this->actingAs($this->jefe())->postJson('/api/produccion/producir', [
            'modo' => 'catalogo', 'producto_id' => $producto->id, 'cantidad' => 2,
            'variante_nueva' => ['marca' => 'Doytex', 'marca_tela' => 'Lino', 'nombre_color' => 'Gris'],
            'pasos' => [['tipo_proceso' => 'ebanisteria', 'orden' => 1]],
        ])->assertStatus(201)->json();

        $this->assertNotNull($resp['variante_id']);
        $variante = ProductoVariante::find($resp['variante_id']);
        $this->assertSame('Lino', $variante->marca_tela);
        $this->assertSame('Gris', $variante->nombre_color);

        $paso = ProduccionPaso::where('produccion_id', $resp['id'])->first();
        $this->actingAs($ebanista)->patchJson("/api/produccion/pasos/{$paso->id}/completar", [
            'trabajadores' => [['usuario_id' => $ebanista->id, 'horas' => 2]],
        ])->assertOk();

        $this->assertSame(2, Inventario::where('producto_id', $producto->id)->where('tienda_id', 1)->value('cantidad_disponible'));
        $this->assertSame(2, DB::table('inventario_variantes')
            ->where('variante_id', $variante->id)->where('tienda_id', 1)->value('cantidad_disponible'));

        // La tela nueva queda además en el catálogo de telas.
        $this->assertSame(1, DB::table('catalogo_telas')
            ->where('marca', 'Doytex')->where('tipo', 'Lino')->where('color', 'Gris')->count());
    }

    public function test_producir_un_producto_nuevo_lo_crea_en_el_catalogo(): void
    {
        $resp = $this->actingAs($this->jefe())->postJson('/api/produccion/producir', [
            'modo' => 'nuevo',
            'nuevo' => ['nombre' => 'Butaca Roma', 'categoria' => 'sillas', 'precio_base' => 300000],
            'cantidad' => 1,
            'pasos' => [['tipo_proceso' => 'ebanisteria', 'orden' => 1]],
        ])->assertStatus(201)->json();

        $producto = Producto::find($resp['producto_id']);
        $this->assertNotNull($producto);
        $this->assertSame('Butaca Roma', $producto->nombre);
        $this->assertTrue((bool) $producto->activo);

        // El inventario base de fábrica ya existe, aunque siga en 0 hasta que
        // se depositen las unidades.
        $this->assertNotNull(Inventario::where('producto_id', $producto->id)->where('tienda_id', 1)->first());
    }

    public function test_no_se_puede_producir_solo_con_el_paso_de_despacho(): void
    {
        $producto = Producto::create(['nombre' => 'Consola', 'categoria' => 'consolas', 'precio_base' => 500000]);

        $this->actingAs($this->jefe())->postJson('/api/produccion/producir', [
            'modo' => 'catalogo', 'producto_id' => $producto->id, 'cantidad' => 1,
            'pasos' => [['tipo_proceso' => 'despacho', 'orden' => 1]],
        ])->assertStatus(422);
    }
}
