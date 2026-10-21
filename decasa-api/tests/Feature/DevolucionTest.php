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
            // La tabla real la tiene: de ella sale si la comision es del
            // equipo o de cada quien.
            $t->boolean('comisiones_compartidas')->default(false);
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
            // La tabla real siempre la tiene, y de ella sale si una orden es
            // restauracion: sin la columna, cualquier cosa que toque comisiones
            // revienta aqui y no en el codigo que se esta probando.
            $t->boolean('es_restauracion')->default(false);
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

        $this->completarEsquemaDeEntregas();
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

    // ── Cambiar: por otro igual, o por otro producto ─────────────────────────

    public function test_cambiar_por_otro_igual_aparta_otra_unidad_y_la_danada_es_merma(): void
    {
        [$orden, , $mesas] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $mesas);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'cambio_mismo'])
            ->assertOk()->assertJsonPath('estado', 'cambio_mismo');

        // Había 5 disponibles con 2 apartadas: la rota sale (4) y se aparta
        // otra para esta orden (las 2 apartadas siguen siendo 2).
        $inv = DB::table('inventario')->where('producto_id', 7)->first();
        $this->assertSame(4, (int) $inv->cantidad_disponible);
        $this->assertSame(2, (int) $inv->cantidad_reservada);

        // La orden no cambia de valor ni de renglones.
        $orden->refresh();
        $this->assertSame(2600000.0, (float) $orden->valor_total);
        $this->assertSame(2, (int) $mesas->fresh()->cantidad);
        $this->assertNotSame('devuelto', $orden->estado);
    }

    public function test_cambiar_por_otro_igual_sin_stock_no_se_puede(): void
    {
        [$orden, , $mesas] = $this->ordenConTresCosas();
        DB::table('inventario')->where('producto_id', 7)->update(['cantidad_disponible' => 2, 'cantidad_reservada' => 2]);
        $devolucion = $this->devolver($orden, $mesas);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'cambio_mismo'])
            ->assertStatus(422);

        $this->assertSame('pendiente', $devolucion->fresh()->estado);
    }

    public function test_cambiar_por_otro_igual_lo_fabricado_se_hace_de_nuevo(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $cama);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'cambio_mismo'])
            ->assertOk();

        $produccion = Produccion::where('orden_item_id', $cama->id)->first();
        $this->assertSame('pendiente', $produccion->estado);
        $this->assertStringContainsString('Se hace de nuevo', $produccion->motivo_retraso);
        $this->assertSame('en_produccion', $orden->fresh()->estado);
    }

    public function test_cambiar_por_otro_producto_deja_de_cobrarlo_y_abre_la_orden(): void
    {
        [$orden, $cama] = $this->ordenConTresCosas();
        // Se dañó en la bodega, antes de salir: el taller la tenía lista.
        Produccion::where('orden_item_id', $cama->id)->update(['estado' => 'listo']);
        $devolucion = $this->devolver($orden, $cama);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'cambio_otro'])
            ->assertOk()->assertJsonPath('estado', 'cambio');

        $orden->refresh();
        // La cama ($2M) deja de cobrarse: quedan las dos mesas.
        $this->assertNotNull($cama->fresh()->devuelto_en);
        $this->assertSame(600000.0, (float) $orden->valor_total);
        // Abierta para que el vendedor agregue el producto nuevo.
        $this->assertSame('pendiente_anticipo', $orden->estado);
        // Y el taller deja de trabajar en la vieja.
        $this->assertSame('cancelado', Produccion::where('orden_item_id', $cama->id)->first()->estado);
    }

    public function test_cambiar_por_otro_producto_una_de_dos_solo_quita_esa(): void
    {
        [$orden, , $mesas] = $this->ordenConTresCosas();
        $devolucion = $this->devolver($orden, $mesas, 1);

        $this->actingAs($this->jefa())
            ->postJson("/api/devoluciones/{$devolucion->id}/decidir", ['decision' => 'cambio_otro'])
            ->assertOk();

        // Vuelve una mesa: el renglón queda con una, y la rota es merma.
        $this->assertSame(1, (int) $mesas->fresh()->cantidad);
        $this->assertNull($mesas->fresh()->devuelto_en);
        $this->assertSame(2300000.0, (float) $orden->fresh()->valor_total);
        $inv = DB::table('inventario')->where('producto_id', 7)->first();
        $this->assertSame(4, (int) $inv->cantidad_disponible);
        $this->assertSame(1, (int) $inv->cantidad_reservada);
    }

    public function test_el_vendedor_registra_el_dano_con_lo_que_prefiere_el_cliente(): void
    {
        [$orden, , $mesas] = $this->ordenConTresCosas();
        $vendedor = Usuario::create(['nombre' => 'Vende', 'email' => 'v@d.com', 'password' => 'x',
                                     'rol' => 'vendedor', 'created_at' => now()]);
        $orden->update(['estado' => 'pendiente_anticipo', 'vendedor_id' => $vendedor->id]);

        $this->actingAs($vendedor)->postJson('/api/devoluciones', [
            'orden_item_id' => $mesas->id, 'cantidad' => 1, 'motivo' => 'Se rayó en la tienda',
            'fecha' => '2026-09-10', 'preferencia_cliente' => 'cambiar_otro',
        ])->assertStatus(201)->assertJsonPath('preferencia_cliente', 'cambiar_otro');

        // Queda esperando decisión, y la orden lo dice.
        $this->assertSame('devuelto', $orden->fresh()->estado);
    }
    // ── Caso real #1231: lo que volvió en el camión y dejó la orden trabada ──

    /**
     * Cama ($5.780.000) y bolsa de obsequio entregadas; los 2 tomacorrientes
     * ($165.000 c/u) volvieron en el camión porque no eran los que iban.
     * Pagada completa. Los tomas siguen apartados en la tienda (2 de 2).
     */
    private function orden1231(): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Unicentro Pereira']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'INOVAR', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert([['id' => 4, 'nombre' => 'CAMA FIGY'], ['id' => 385, 'nombre' => 'Toma corriente cama']]);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'devuelto',
                                'valor_total' => 6110000, 'numero_orden' => 1231]);
        $cama  = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 4, 'cantidad' => 1, 'cantidad_entregada' => 1,
                                    'precio_unitario' => 5780000, 'es_personalizado' => false]);
        $tomas = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 385, 'cantidad' => 2, 'cantidad_entregada' => 0,
                                    'precio_unitario' => 165000, 'es_personalizado' => false]);
        DB::table('inventario')->insert(['producto_id' => 385, 'tienda_id' => 1, 'cantidad_disponible' => 2, 'cantidad_reservada' => 2]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 6110000, 'metodo' => 'tarjeta', 'tipo' => 'anticipo', 'created_at' => now()]);

        $dev = Devolucion::create(['orden_id' => $orden->id, 'orden_item_id' => $tomas->id, 'cantidad' => 2,
                                   'motivo' => 'nocheros por toma corriente', 'fecha' => '2026-09-23', 'estado' => 'pendiente',
                                   'despacho_item_id' => 82]);

        return [$orden, $cama, $tomas, $dev];
    }

    private function invTomas(): array
    {
        $f = DB::table('inventario')->where('producto_id', 385)->first();

        return [(int) $f->cantidad_disponible, (int) $f->cantidad_reservada];
    }

    public function test_1231_otra_igual_falla_porque_las_unicas_son_las_apartadas(): void
    {
        [, , , $dev] = $this->orden1231();

        // Lo que pasaba: pide OTRAS dos libres y no las hay.
        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$dev->id}/decidir", ['decision' => 'cambio_mismo'])
            ->assertStatus(422);
    }

    public function test_1231_se_le_vuelve_a_llevar_sin_mover_inventario(): void
    {
        [$orden, , $tomas, $dev] = $this->orden1231();

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$dev->id}/decidir", ['decision' => 'reentregar'])
            ->assertOk()->assertJsonPath('estado', 'reentrega');

        // Sale de "devuelto": lista para llevarle los tomas.
        $this->assertSame('listo_entrega', $orden->fresh()->estado);
        // Siguen apartados para ella y por entregar; el valor no cambia.
        $this->assertSame([2, 2], $this->invTomas());
        $this->assertSame(2, $tomas->fresh()->pendienteEntregar());
        $this->assertEquals(6110000, (float) $orden->fresh()->valor_total);
    }

    public function test_1231_se_queda_sin_los_tomas_y_la_orden_se_cierra(): void
    {
        [$orden, , $tomas, $dev] = $this->orden1231();

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$dev->id}/decidir",
            ['decision' => 'quitar', 'vuelve_al_stock' => true])
            ->assertOk()->assertJsonPath('estado', 'quitada');

        $orden->refresh();
        // Ya tiene todo lo demás en la casa: queda entregada.
        $this->assertSame('entregado', $orden->estado);
        // Los tomas dejan de cobrarse; lo pagado queda a favor.
        $this->assertNotNull($tomas->fresh()->devuelto_en);
        $this->assertEquals(5780000, (float) $orden->valor_total);
        $this->assertEquals(-330000, $orden->saldoPendiente());
        // Estaban buenos: se sueltan para venderlos (nunca salieron de la tienda).
        $this->assertSame([2, 0], $this->invTomas());
    }

    public function test_1231_si_estaban_danados_salen_como_merma(): void
    {
        [, , , $dev] = $this->orden1231();

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$dev->id}/decidir",
            ['decision' => 'quitar', 'vuelve_al_stock' => false])->assertOk();

        $this->assertSame([0, 0], $this->invTomas());
    }

    public function test_no_se_vuelve_a_llevar_lo_que_el_cliente_ya_tiene(): void
    {
        [, , $tomas, $dev] = $this->orden1231();
        $tomas->update(['cantidad_entregada' => 2]);

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$dev->id}/decidir", ['decision' => 'reentregar'])
            ->assertStatus(422);
    }

    public function test_con_otra_devolucion_sin_decidir_la_orden_sigue_devuelta(): void
    {
        [$orden, $cama, , $dev] = $this->orden1231();
        Devolucion::create(['orden_id' => $orden->id, 'orden_item_id' => $cama->id, 'cantidad' => 1,
                            'motivo' => 'Rayada', 'fecha' => '2026-09-23', 'estado' => 'pendiente']);

        $this->actingAs($this->jefa())->postJson("/api/devoluciones/{$dev->id}/decidir", ['decision' => 'reentregar'])->assertOk();

        $this->assertSame('devuelto', $orden->fresh()->estado);
    }
    /**
     * `referencia` no es columna de `ordenes`: se arma en el modelo. Pedirla en
     * un select revienta en MySQL ("Unknown column") y la lista salía vacía en
     * la orden y en Producción: nadie veía la devolución de la #1231. SQLite
     * lo deja pasar (la toma como texto), así que se mira la consulta misma.
     */
    public function test_la_lista_no_pide_columnas_que_no_existen(): void
    {
        [$orden, , , $dev] = $this->orden1231();
        $consultas = [];
        DB::listen(function ($q) use (&$consultas) { $consultas[] = $q->sql; });

        $this->actingAs($this->jefa())->getJson("/api/devoluciones?orden_id={$orden->id}")->assertOk()
            ->assertJsonPath('0.id', $dev->id)
            ->assertJsonPath('0.orden_referencia', '#1231');
        $this->actingAs($this->jefa())->getJson('/api/devoluciones?estado=pendiente')->assertOk()->assertJsonCount(1);

        foreach ($consultas as $sql) {
            $this->assertStringNotContainsString('"referencia"', $sql, "Pide una columna que no existe: {$sql}");
        }
    }
}
