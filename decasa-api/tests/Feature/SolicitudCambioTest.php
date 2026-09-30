<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Pago;
use App\Models\SolicitudCambio;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Los cambios de dinero de un vendedor pasan por un supervisor.
 *
 * Se prueba que el vendedor no los hace directo (y que lo demás sí), que la
 * solicitud pide motivo y soporte y avisa, y que al aprobarla el cambio se
 * aplica de verdad —por el mismo camino que una edición normal— y al
 * rechazarla no se toca nada.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 * Es el de EditarOrdenEntregadaTest, más la tabla de solicitudes.
 */
class SolicitudCambioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('facturacion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('en_produccion'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->decimal('descuento_total', 12, 2)->default(0);
            $t->decimal('descuento_condicionado', 12, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 8, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('numero_anulado')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->string('notas')->nullable(); $t->string('canal')->nullable();
            $t->string('factura_foto_url')->nullable(); $t->string('anexo_foto_url')->nullable();
            $t->string('firma_url')->nullable(); $t->decimal('anticipo_pct', 5, 2)->nullable();
            $t->string('departamento_envio')->nullable(); $t->string('ciudad_envio')->nullable();
            $t->string('direccion_envio')->nullable(); $t->date('fecha_sugerida_vendedor')->nullable();
            $t->timestamp('confirmada_en')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_restauracion')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('retapizar')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->integer('cantidad_entregada')->default(0);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(false);
            $t->boolean('es_regalo')->default(false); $t->json('specs_personalizacion')->nullable();
            $t->string('boceto_url')->nullable(); $t->json('boceto_fotos')->nullable();
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable();
            $t->text('motivo_devolucion')->nullable(); $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id'); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2); $t->date('fecha_venta'); $t->date('fecha_disponible');
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('tipo')->nullable();
            $t->decimal('monto', 12, 2); $t->string('metodo')->nullable(); $t->string('referencia')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
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
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('solicitudes_cambio', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('solicitante_id'); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->string('estado', 15)->default('pendiente'); $t->json('cambios_orden')->nullable();
            $t->json('cambio_pago')->nullable(); $t->json('resumen'); $t->text('motivo'); $t->json('soportes');
            $t->unsignedBigInteger('revisado_por_id')->nullable(); $t->timestamp('revisado_at')->nullable();
            $t->text('respuesta')->nullable(); $t->timestamps();
        });
    }

    private Usuario $jefa;
    private Usuario $paola;

    /** Una mesa de $800.000 en producción, vendida por Paola, con un anticipo en efectivo. */
    private function orden(string $estado = 'en_produccion'): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa de comedor']);

        $this->jefa  = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        $this->paola = Usuario::create(['nombre' => 'Paola', 'email' => 'p@d.com', 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $this->paola->id, 'estado' => $estado,
            'valor_total' => 800000, 'numero_orden' => 4300, 'canal' => 'fisica', 'anticipo_pct' => 50,
        ]);
        $mesa = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => 1,
                                   'cantidad' => 1, 'precio_unitario' => 800000]);
        $pago = Pago::create(['orden_id' => $orden->id, 'vendedor_id' => $this->paola->id, 'tienda_id' => 1,
                              'tipo' => 'anticipo', 'monto' => 400000, 'metodo' => 'efectivo']);

        return [$orden, $mesa, $pago];
    }

    private function pedir(Orden $orden, array $pedido, array $extra = [])
    {
        return $this->actingAs($this->paola)->postJson("/api/ordenes/{$orden->id}/solicitudes-cambio", array_merge([
            'motivo'   => 'El cliente pagó menos por la promoción del mes.',
            'soportes' => ['https://x.example/chat.jpg'],
            'supervisor_id' => $this->jefa->id,
        ], $pedido, $extra));
    }

    // ── El vendedor no cambia dinero directo ─────────────────────────────────

    public function test_el_vendedor_no_cambia_el_precio_directo(): void
    {
        [$orden, $mesa] = $this->orden();

        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", [
            'items' => [['id' => $mesa->id, 'precio_unitario' => 650000]],
        ])->assertStatus(403)->assertJsonPath('requiere_aprobacion', true);

        $this->assertEquals(800000, $mesa->fresh()->precio_unitario);
    }

    public function test_lo_que_no_es_dinero_lo_guarda_directo_aunque_viaje_el_precio_igual(): void
    {
        [$orden, $mesa] = $this->orden();

        // La pantalla manda siempre el precio y la cantidad, aunque no cambien.
        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", [
            'notas' => 'Entregar en la tarde.',
            'items' => [['id' => $mesa->id, 'precio_unitario' => 800000, 'cantidad' => 1, 'producto_id' => 5]],
        ])->assertOk();

        $this->assertSame('Entregar en la tarde.', $orden->fresh()->notas);
    }

    public function test_el_vendedor_no_cambia_el_medio_de_un_pago_directo_pero_si_la_referencia(): void
    {
        [$orden, , $pago] = $this->orden();

        $this->actingAs($this->paola)->patchJson("/api/pagos/{$pago->id}", ['monto' => 400000, 'metodo' => 'transferencia'])
            ->assertStatus(403);
        $this->actingAs($this->paola)->patchJson("/api/pagos/{$pago->id}", ['monto' => 400000, 'metodo' => 'efectivo', 'referencia' => 'Recibo 12'])
            ->assertOk();

        $this->assertSame('efectivo', $pago->fresh()->metodo);
        $this->assertSame('Recibo 12', $pago->fresh()->referencia);
    }

    public function test_en_un_borrador_el_vendedor_cambia_el_precio_directo(): void
    {
        [$orden, $mesa] = $this->orden('borrador');

        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", [
            'items' => [['id' => $mesa->id, 'precio_unitario' => 650000]],
        ])->assertOk();

        $this->assertEquals(650000, $mesa->fresh()->precio_unitario);
    }

    public function test_ponerle_precio_a_lo_que_espera_cotizacion_no_necesita_aprobacion(): void
    {
        [$orden] = $this->orden('pendiente_cotizacion');
        $sofa = OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Sofá a medida', 'es_personalizado' => true,
                                   'cantidad' => 1, 'precio_unitario' => 0]);

        $rev = $this->actingAs($this->paola)->postJson("/api/ordenes/{$orden->id}/solicitudes-cambio/revisar", [
            'cambios_orden' => ['items' => [['id' => $sofa->id, 'precio_unitario' => 2500000]]],
        ])->assertOk();

        $this->assertSame([], $rev->json('cambios'));
    }

    // ── La solicitud ─────────────────────────────────────────────────────────

    public function test_revisar_dice_que_cambia(): void
    {
        [$orden, $mesa, $pago] = $this->orden();

        $rev = $this->actingAs($this->paola)->postJson("/api/ordenes/{$orden->id}/solicitudes-cambio/revisar", [
            'cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000, 'cantidad' => 1]]],
            'pago'          => ['id' => $pago->id, 'monto' => 400000, 'metodo' => 'transferencia'],
        ])->assertOk();

        $labels = collect($rev->json('cambios'))->pluck('label')->all();
        $this->assertContains('Mesa de comedor — precio', $labels);
        $this->assertCount(2, $labels, 'la cantidad que no cambió no cuenta');
    }

    public function test_pide_motivo_y_soporte_y_avisa_a_los_supervisores(): void
    {
        [$orden, $mesa] = $this->orden();
        $pedido = ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]];

        $this->pedir($orden, $pedido, ['motivo' => ''])->assertStatus(422);
        $this->pedir($orden, $pedido, ['soportes' => []])->assertStatus(422);
        $this->pedir($orden, $pedido)->assertCreated();

        $aviso = DB::table('notificaciones')->where('usuario_id', $this->jefa->id)->where('tipo', 'solicitud_cambio')->first();
        $this->assertNotNull($aviso);
        $this->assertSame(1, (int) $aviso->urgente);
    }

    public function test_se_le_pide_a_un_supervisor_y_solo_a_el_le_llega(): void
    {
        [$orden, $mesa] = $this->orden();
        $otro = Usuario::create(['nombre' => 'Carlos', 'email' => 'c@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        $pedido = ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]];

        // Hay que elegir, y solo supervisores: ni un vendedor ni uno inactivo
        $this->pedir($orden, $pedido, ['supervisor_id' => null])->assertStatus(422)->assertJsonValidationErrors('supervisor_id');
        $this->pedir($orden, $pedido, ['supervisor_id' => $this->paola->id])->assertStatus(422)->assertJsonValidationErrors('supervisor_id');
        $inactivo = Usuario::create(['nombre' => 'Viejo', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'supervisor', 'activo' => false, 'created_at' => now()]);
        $this->pedir($orden, $pedido, ['supervisor_id' => $inactivo->id])->assertStatus(422);

        $this->pedir($orden, $pedido, ['supervisor_id' => $otro->id])
            ->assertCreated()->assertJsonPath('supervisor.nombre', 'Carlos');

        $avisos = DB::table('notificaciones')->where('tipo', 'solicitud_cambio')->get();
        $this->assertCount(1, $avisos);
        $this->assertSame($otro->id, (int) $avisos[0]->usuario_id);
        $this->assertSame('Aprobar cambio de dinero en ' . $orden->fresh()->referencia, $avisos[0]->titulo);

        // La lista para elegir: solo supervisores activos
        $this->actingAs($this->paola)->getJson('/api/solicitudes-cambio/supervisores')
            ->assertOk()->assertJsonCount(2)->assertJsonPath('0.nombre', 'Carlos')->assertJsonPath('1.nombre', 'Jefa');
    }

    public function test_una_pendiente_a_la_vez(): void
    {
        [$orden, $mesa] = $this->orden();
        $pedido = ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]];

        $this->pedir($orden, $pedido)->assertCreated();
        $this->pedir($orden, $pedido)->assertStatus(422);
    }

    public function test_sin_cambios_de_dinero_no_hay_nada_que_pedir(): void
    {
        [$orden, $mesa] = $this->orden();

        $this->pedir($orden, ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 800000]]]])
            ->assertStatus(422);
    }

    // ── Responderla ──────────────────────────────────────────────────────────

    public function test_al_aprobarla_el_cambio_se_aplica(): void
    {
        [$orden, $mesa, $pago] = $this->orden();
        $id = $this->pedir($orden, [
            'cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]],
            'pago'          => ['id' => $pago->id, 'monto' => 400000, 'metodo' => 'transferencia'],
        ])->json('id');

        $this->actingAs($this->jefa)->postJson("/api/solicitudes-cambio/{$id}/aprobar")->assertOk();

        $this->assertEquals(650000, $mesa->fresh()->precio_unitario);
        $this->assertEquals(650000, $orden->fresh()->valor_total);
        $this->assertSame('transferencia', $pago->fresh()->metodo);
        $this->assertSame('aprobada', SolicitudCambio::find($id)->estado);
        $this->assertTrue(DB::table('notificaciones')->where('usuario_id', $this->paola->id)
            ->where('tipo', 'solicitud_cambio_respuesta')->exists(), 'le avisa a quien la pidió');

        // En el historial, una sola entrada con todo: quién aprobó, quién pidió,
        // el motivo, el soporte y lo que cambió de verdad (orden y pago).
        $ediciones = \App\Models\OrdenEdicion::where('orden_id', $orden->id)->get();
        $this->assertCount(1, $ediciones, 'no quedan sueltas las ediciones a nombre del supervisor');
        $entrada = $ediciones->first()->cambios[0];
        $this->assertSame('aprobacion_dinero', $entrada['tipo']);
        $this->assertSame('Jefa', $entrada['revisado_por']);
        $this->assertSame('Paola', $entrada['solicitado_por']);
        $this->assertSame(['https://x.example/chat.jpg'], $entrada['soportes']);
        $campos = collect($entrada['cambios'])->pluck('campo')->all();
        $this->assertContains("pago_{$pago->id}_metodo", $campos);
        $this->assertContains("item_{$mesa->id}_precio", $campos);
    }

    public function test_al_rechazarla_no_se_toca_nada_y_se_dice_por_que(): void
    {
        [$orden, $mesa] = $this->orden();
        $id = $this->pedir($orden, ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]])->json('id');

        $this->actingAs($this->jefa)->postJson("/api/solicitudes-cambio/{$id}/rechazar", ['respuesta' => ''])->assertStatus(422);
        $this->actingAs($this->jefa)->postJson("/api/solicitudes-cambio/{$id}/rechazar", ['respuesta' => 'No hay promoción vigente.'])->assertOk();

        $this->assertEquals(800000, $mesa->fresh()->precio_unitario);
        $this->assertSame('rechazada', SolicitudCambio::find($id)->estado);

        $entrada = \App\Models\OrdenEdicion::where('orden_id', $orden->id)->first()->cambios[0];
        $this->assertSame('rechazo_dinero', $entrada['tipo']);
        $this->assertSame('No hay promoción vigente.', $entrada['respuesta']);
    }

    public function test_el_vendedor_no_se_la_aprueba_a_si_mismo(): void
    {
        [$orden, $mesa] = $this->orden();
        $id = $this->pedir($orden, ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]])->json('id');

        $this->actingAs($this->paola)->postJson("/api/solicitudes-cambio/{$id}/aprobar")->assertStatus(403);
        $this->assertEquals(800000, $mesa->fresh()->precio_unitario);
    }

    // ── Pasados 5 días, el vendedor ya no modifica nada ──────────────────────

    /** La orden se hizo hace `$dias` días. */
    private function hecha(Orden $orden, int $dias, ?int $confirmadaHace = null): void
    {
        DB::table('ordenes')->where('id', $orden->id)->update([
            'created_at'    => now()->subDays($dias),
            'confirmada_en' => $confirmadaHace === null ? null : now()->subDays($confirmadaHace),
        ]);
    }

    public function test_dentro_de_los_5_dias_el_vendedor_edita_despues_ya_no(): void
    {
        [$orden] = $this->orden();

        $this->hecha($orden, 4);
        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Tela gris'])->assertOk();

        $this->hecha($orden, 6);
        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Mejor azul'])
            ->assertStatus(403)->assertJsonPath('edicion_vencida', true);
        $this->assertSame('Tela gris', $orden->fresh()->notas);
    }

    public function test_vencida_tampoco_corrige_la_referencia_de_un_pago(): void
    {
        [$orden, , $pago] = $this->orden();
        $this->hecha($orden, 6);

        $this->actingAs($this->paola)->patchJson("/api/pagos/{$pago->id}", ['monto' => 400000, 'metodo' => 'efectivo', 'referencia' => 'Recibo 9'])
            ->assertStatus(403);
    }

    public function test_vencida_sigue_pudiendo_pedir_un_cambio_de_dinero(): void
    {
        [$orden, $mesa] = $this->orden();
        $this->hecha($orden, 20);

        $id = $this->pedir($orden, ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]])
            ->assertCreated()->json('id');
        $this->actingAs($this->jefa)->postJson("/api/solicitudes-cambio/{$id}/aprobar")->assertOk();

        $this->assertEquals(650000, $mesa->fresh()->precio_unitario);
    }

    public function test_el_supervisor_edita_sin_limite_de_dias(): void
    {
        [$orden] = $this->orden();
        $this->hecha($orden, 30);

        $this->actingAs($this->jefa)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Cambio autorizado'])->assertOk();
    }

    public function test_una_restauracion_tiene_8_dias(): void
    {
        [$orden, , $pago] = $this->orden();
        $orden->update(['serie' => 'R', 'serie_numero' => 1098]);

        // A los 7 días una venta ya estaría cerrada; una restauración no
        $this->hecha($orden, 7);
        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Cambiar el relleno'])->assertOk();
        $this->actingAs($this->paola)->getJson("/api/ordenes/{$orden->id}")
            ->assertJsonPath('edicion_vencida', false)->assertJsonPath('dias_para_editar', 8);

        // A los 9, igual que una venta a los 6: nada directo, solo pedir
        $this->hecha($orden, 9);
        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Otro relleno'])
            ->assertStatus(403)->assertJsonPath('edicion_vencida', true)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Pasaron 8 días'));
        $this->actingAs($this->paola)->patchJson("/api/pagos/{$pago->id}", ['monto' => 400000, 'metodo' => 'efectivo', 'referencia' => 'Recibo 9'])
            ->assertStatus(403);
        $this->assertSame('Cambiar el relleno', $orden->fresh()->notas);

        // El supervisor, sin límite
        $this->actingAs($this->jefa)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Autorizado'])->assertOk();
    }

    public function test_los_dias_cuentan_desde_que_se_confirmo(): void
    {
        // Un borrador empezado hace 10 días y completado hace 2: sigue editable.
        [$orden] = $this->orden();
        $this->hecha($orden, 10, 2);

        $this->actingAs($this->paola)->patchJson("/api/ordenes/{$orden->id}", ['notas' => 'Ok'])->assertOk();
    }

    public function test_quien_la_pidio_la_puede_retirar(): void
    {
        [$orden, $mesa] = $this->orden();
        $id = $this->pedir($orden, ['cambios_orden' => ['items' => [['id' => $mesa->id, 'precio_unitario' => 650000]]]])->json('id');

        $this->actingAs($this->paola)->postJson("/api/solicitudes-cambio/{$id}/cancelar")->assertOk();
        $this->assertSame('cancelada', SolicitudCambio::find($id)->estado);
        // Y ya no se puede aprobar.
        $this->actingAs($this->jefa)->postJson("/api/solicitudes-cambio/{$id}/aprobar")->assertStatus(422);
    }
}
