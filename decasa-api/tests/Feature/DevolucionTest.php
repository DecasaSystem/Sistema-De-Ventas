<?php

namespace Tests\Feature;

use App\Models\CajaMovimiento;
use App\Models\Devolucion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenMensaje;
use App\Models\Produccion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Qué se hace con lo que vuelve en el camión.
 *
 * Se prueba porque acá se mueven tres cosas que duelen si quedan mal: el estado
 * de la orden, el inventario y la plata que sale de la caja.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite
 * (hay `ALTER ... MODIFY`, sintaxis de MySQL).
 */
class DevolucionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_despacho')->default(false);
            $t->boolean('no_usa_programa')->default(false); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('pendiente_anticipo');
            $t->decimal('valor_total', 12, 2)->default(0); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('numero_orden')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->timestamps();
        });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('caja_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 12, 2); $t->string('concepto')->nullable();
            $t->text('descripcion')->nullable(); $t->string('comprobante_url')->nullable(); $t->timestamps();
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
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('devoluciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('orden_item_id');
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->unsignedInteger('cantidad')->default(1);
            $t->text('motivo'); $t->string('foto_url')->nullable(); $t->date('fecha');
            $t->unsignedBigInteger('reportado_por_id')->nullable(); $t->string('estado')->default('pendiente');
            $t->unsignedBigInteger('decidido_por_id')->nullable(); $t->timestamp('decidido_at')->nullable();
            $t->text('notas_decision')->nullable(); $t->decimal('monto_devuelto', 12, 2)->nullable();
            $t->unsignedBigInteger('caja_movimiento_id')->nullable(); $t->timestamps();
        });
    }

    private function jefa(): Usuario
    {
        return Usuario::create(['nombre' => 'Manuela', 'email' => 'm@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'gestiona_produccion' => true, 'created_at' => now()]);
    }

    /** Una orden con una cama personalizada ($2M) y dos mesas de catálogo ($300k c/u). */
    private function ordenConTresCosas(): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 7, 'nombre' => 'Mesa de noche']);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'devuelto',
                                'valor_total' => 2600000, 'serie' => 'FV2', 'serie_numero' => 100]);

        $cama = OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Cama Macarena 1.40',
                                   'cantidad' => 1, 'precio_unitario' => 2000000, 'es_personalizado' => true]);
        $mesas = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 7, 'tienda_origen_id' => 1,
                                    'cantidad' => 2, 'precio_unitario' => 300000, 'es_personalizado' => false]);

        Produccion::create(['orden_item_id' => $cama->id, 'estado' => 'entregado',
                            'fecha_inicio' => '2026-08-01', 'fecha_real' => '2026-08-20']);

        DB::table('inventario')->insert(['producto_id' => 7, 'tienda_id' => 1,
                                         'cantidad_disponible' => 5, 'cantidad_reservada' => 2]);

        return [$orden, $cama, $mesas];
    }

    private function devolver(Orden $orden, OrdenItem $item, int $cantidad = 1): Devolucion
    {
        return Devolucion::create([
            'orden_id' => $orden->id, 'orden_item_id' => $item->id, 'cantidad' => $cantidad,
            'motivo' => 'Llegó con la madera partida', 'fecha' => '2026-08-25', 'estado' => 'pendiente',
        ]);
    }

    public function test_vuelve_al_taller_y_reaparece_en_produccion(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'a_produccion'])
            ->assertOk()->assertJsonPath('estado', 'a_produccion');

        // La producción de esa pieza se reabre en vez de crearse otra: así el
        // mueble conserva su historia y el arreglo queda pegado a ella.
        $produccion = Produccion::where('orden_item_id', $cama->id)->get();
        $this->assertCount(1, $produccion);
        $this->assertSame('pendiente', $produccion->first()->estado);
        $this->assertNull($produccion->first()->fecha_real);

        // Y la orden vuelve al taller, que es donde el tablero la muestra.
        $this->assertSame('en_produccion', $orden->fresh()->estado);
        // Sin plata de por medio.
        $this->assertSame(0, CajaMovimiento::count());
    }

    public function test_un_producto_de_catalogo_devuelto_estrena_produccion(): void
    {
        [$orden, , $mesas] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $mesas);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'a_produccion'])
            ->assertOk();

        // Nunca se fabricó, así que no tenía producción: se le crea una para el
        // arreglo, que es lo que la hace aparecer en el tablero.
        $this->assertSame(1, Produccion::where('orden_item_id', $mesas->id)->count());
    }

    public function test_el_reembolso_sale_de_la_caja_de_la_tienda(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$devolucion->id}/decidir", [
            'decision' => 'reembolso', 'monto' => 2000000, 'notas' => 'No quiso esperar el arreglo',
        ])->assertOk()->assertJsonPath('estado', 'reembolsada');

        $mov = CajaMovimiento::first();
        $this->assertSame('egreso', $mov->tipo);
        $this->assertEquals(2000000, $mov->monto);
        $this->assertSame(1, (int) $mov->tienda_id);
        $this->assertStringContainsString('FV2-100', $mov->concepto);

        // Al cliente le quedaron las dos mesas, así que la orden no se cancela:
        // esa parte sí llegó a la casa.
        $this->assertSame('entregado', $orden->fresh()->estado);
    }

    public function test_si_no_queda_nada_en_la_casa_la_orden_se_cancela(): void
    {
        [$orden, $cama, $mesas] = $this->ordenConTresCosas();

        $d1 = $this->devolver($orden, $cama, 1);
        $d2 = $this->devolver($orden, $mesas, 2);

        $jefa = $this->jefa();
        $this->actingAs($jefa)->postJson("/api/devoluciones/{$d1->id}/decidir",
            ['decision' => 'reembolso', 'monto' => 2000000])->assertOk();
        // Con la primera todavía le quedaban las mesas.
        $this->assertSame('entregado', $orden->fresh()->estado);

        $this->actingAs($jefa)->postJson("/api/devoluciones/{$d2->id}/decidir",
            ['decision' => 'reembolso', 'monto' => 600000])->assertOk();

        // Ya no le quedó nada: la venta no existió.
        $this->assertSame('cancelado', $orden->fresh()->estado);
    }

    public function test_lo_devuelto_de_catalogo_no_vuelve_a_estar_a_la_venta(): void
    {
        [$orden, , $mesas] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $mesas, 2);

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$devolucion->id}/decidir",
            ['decision' => 'reembolso', 'monto' => 600000])->assertOk();

        // Las mesas volvieron rotas: salen del stock. Devolverlas a disponible
        // las pondría otra vez a la venta, y lo que hay en la bodega es justo
        // lo que el cliente no quiso.
        $inv = DB::table('inventario')->where('producto_id', 7)->first();
        $this->assertSame(3, (int) $inv->cantidad_disponible);
        $this->assertSame(0, (int) $inv->cantidad_reservada);

        $mov = DB::table('inventario_movimientos')->first();
        $this->assertSame('salida', $mov->tipo);
        $this->assertStringContainsString('Devolución dañada', $mov->motivo);
    }

    public function test_queda_escrito_en_la_orden(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir",
                ['decision' => 'a_produccion', 'notas' => 'Se cambia el espaldar'])
            ->assertOk();

        // El rastro va en el hilo de la orden, que es donde la gente ya mira.
        $mensaje = OrdenMensaje::where('orden_id', $orden->id)->latest('id')->first();
        $this->assertStringContainsString('vuelve al taller', $mensaje->mensaje);
        $this->assertStringContainsString('Se cambia el espaldar', $mensaje->mensaje);
    }

    public function test_solo_decide_quien_gestiona_produccion(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);

        $otro = Usuario::create(['nombre' => 'Vendedor', 'email' => 'v@d.com', 'password' => 'x',
                                 'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($otro)->postJson("/api/devoluciones/{$devolucion->id}/decidir",
            ['decision' => 'a_produccion'])->assertStatus(403);

        $this->assertSame('pendiente', $devolucion->fresh()->estado);
    }

    public function test_una_devolucion_no_se_resuelve_dos_veces(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);
        $jefa = $this->jefa();

        $this->actingAs($jefa)->postJson("/api/devoluciones/{$devolucion->id}/decidir",
            ['decision' => 'reembolso', 'monto' => 2000000])->assertOk();

        // Volver a decidir sacaría la plata de la caja por segunda vez.
        $this->actingAs($jefa)->postJson("/api/devoluciones/{$devolucion->id}/decidir",
            ['decision' => 'reembolso', 'monto' => 2000000])->assertStatus(422);

        $this->assertSame(1, CajaMovimiento::count());
    }

    public function test_el_reembolso_exige_decir_cuanto(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'reembolso'])
            ->assertStatus(422);
    }
}
