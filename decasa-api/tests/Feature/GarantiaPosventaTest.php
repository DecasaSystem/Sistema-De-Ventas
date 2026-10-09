<?php

namespace Tests\Feature;

use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\EntregaLinea;
use App\Models\Garantia;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\Usuario;
use App\Services\EntregaService;
use App\Services\GarantiaService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Garantías: lo que se daña DESPUÉS de entregado.
 *
 * La señora Pérez recibió el 1 de septiembre una cama hecha a la medida y dos
 * mesas de noche de la tienda. El 9 de octubre llama: a la cama se le despegó
 * el espaldar, y una mesa tiene la pata floja.
 *
 * Se prueba lo que duele si queda mal:
 *  - que el producto se "reactive" en la orden y vuelva a entregarse por el
 *    camino normal, sin descontar del inventario una pieza que ya salió;
 *  - que la historia del taller no se borre al arreglarlo;
 *  - que el cambio consulte el inventario de verdad y mueva la plata solo
 *    cuando lo decide un supervisor;
 *  - que las fechas del anexo (vigencia, 15 días hábiles) salgan bien.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class GarantiaPosventaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Viernes 9 de octubre de 2026, 10 a. m. en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', 'UTC'));

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('acceso_despacho')->default(false); $t->boolean('acceso_entregas')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('apto_produccion')->default(false);
            $t->boolean('no_usa_programa')->default(false); $t->boolean('facturacion')->default(false);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('es_independientes')->default(false); $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->timestamps(); });
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
            $t->timestamp('descuento_condicionado_revertido_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('es_regalo')->default(false);
            $t->boolean('fabricar_pedido')->default(false); $t->boolean('usa_stock_tienda')->default(false);
            $t->date('fecha_entrega_prom')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->string('destino')->default('orden');
            $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->integer('cantidad')->default(1); $t->text('specs')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamp('depositado_at')->nullable();
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal');
            $t->unsignedTinyInteger('orden')->default(1); $t->string('estado')->default('pendiente');
            $t->timestamp('iniciado_at')->nullable(); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->text('trabajadores')->nullable();
            $t->unsignedInteger('rechazos')->default(0); $t->text('ultimo_rechazo')->nullable();
            $t->unsignedBigInteger('rechazado_por_id')->nullable(); $t->timestamp('rechazado_at')->nullable();
            $t->unsignedBigInteger('garantia_id')->nullable();
        });
        Schema::create('produccion_retornos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->unsignedBigInteger('paso_destino_id');
            $t->text('pasos_rehacer'); $t->text('motivo'); $t->unsignedBigInteger('devuelto_por_id');
            $t->timestamp('created_at')->nullable(); $t->timestamp('resuelto_at')->nullable();
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable(); $t->timestamps();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('configuracion', function (Blueprint $t) { $t->string('clave')->primary(); $t->text('valor'); });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1);
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
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
            $t->unsignedInteger('cantidad')->default(1); $t->string('tipo_dano'); $t->string('linea')->nullable();
            $t->text('motivo'); $t->json('fotos')->nullable(); $t->string('donde_esta')->default('casa_cliente');
            $t->string('preferencia_cliente')->nullable(); $t->date('fecha_reporte');
            $t->date('fecha_entrega')->nullable(); $t->date('vence_el')->nullable(); $t->date('responder_antes_de')->nullable();
            $t->unsignedBigInteger('reportado_por_id')->nullable(); $t->string('estado')->default('pendiente');
            $t->string('decision')->nullable(); $t->unsignedBigInteger('decidido_por_id')->nullable();
            $t->timestamp('decidido_at')->nullable(); $t->text('notas_decision')->nullable();
            $t->string('causal_no_procede')->nullable(); $t->json('procesos_reparacion')->nullable();
            $t->unsignedBigInteger('produccion_id')->nullable(); $t->timestamp('recibido_en_taller_at')->nullable();
            $t->unsignedBigInteger('recibido_por_id')->nullable(); $t->unsignedBigInteger('visita_por_id')->nullable();
            $t->date('visita_fecha')->nullable(); $t->text('visita_notas')->nullable(); $t->json('visita_fotos')->nullable();
            $t->unsignedBigInteger('orden_item_nuevo_id')->nullable(); $t->decimal('diferencia_valor', 15, 2)->nullable();
            $t->decimal('monto_reembolso', 15, 2)->nullable(); $t->unsignedBigInteger('pago_reembolso_id')->nullable();
            $t->string('destino_devuelto')->nullable(); $t->unsignedBigInteger('tienda_devuelto_id')->nullable();
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->timestamp('resuelta_at')->nullable();
            $t->unsignedBigInteger('resuelta_por_id')->nullable(); $t->timestamps();
        });

        $this->completarEsquemaDeEntregas();

        DB::table('tipos_proceso')->insert([
            ['id' => 1, 'clave' => 'ebanisteria', 'nombre' => 'Ebanistería', 'activo' => true, 'orden' => 1],
            ['id' => 2, 'clave' => 'tapizado',    'nombre' => 'Tapizado',    'activo' => true, 'orden' => 2],
            ['id' => 4, 'clave' => 'despacho',    'nombre' => 'Despacho',    'activo' => true, 'orden' => 99],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El caso ──────────────────────────────────────────────────────────────

    private function jefa(): Usuario
    {
        return Usuario::create(['nombre' => 'Manuela', 'email' => 'm@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'gestiona_produccion' => true, 'created_at' => now()]);
    }

    private function jefeTaller(): Usuario
    {
        return Usuario::create(['nombre' => 'Don Jairo', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'taller', 'gestiona_produccion' => true, 'created_at' => now()]);
    }

    /**
     * Cama a la medida ($2M, fabricada: tres pasos hechos, con quien los hizo)
     * y dos mesas de noche de la tienda ($300k c/u), entregadas el 1 de
     * septiembre. Mesas: 3 en Norte y 2 en El Edén; sofá Milán: 1 en El Edén.
     *
     * @return array{0: Orden, 1: OrdenItem, 2: OrdenItem, 3: DespachoItem}
     */
    private function ordenEntregada(): array
    {
        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Decasa Norte'], ['id' => 2, 'nombre' => 'Decasa El Edén']]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert([
            ['id' => 7, 'nombre' => 'Mesa de noche', 'precio_base' => 300000],
            ['id' => 8, 'nombre' => 'Sofá Milán',    'precio_base' => 1500000],
        ]);
        DB::table('inventario')->insert([
            ['producto_id' => 7, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0],
            ['producto_id' => 7, 'tienda_id' => 2, 'cantidad_disponible' => 2, 'cantidad_reservada' => 0],
            ['producto_id' => 8, 'tienda_id' => 2, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0],
        ]);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 99, 'estado' => 'entregado',
                                'valor_total' => 2600000, 'serie' => 'FV', 'serie_numero' => 1200]);

        $cama  = OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Cama Macarena 1.40', 'cantidad' => 1,
                                    'cantidad_entregada' => 1, 'precio_unitario' => 2000000, 'es_personalizado' => true]);
        $mesas = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 7, 'cantidad' => 2,
                                    'cantidad_entregada' => 2, 'precio_unitario' => 300000]);

        $prod = Produccion::create(['orden_item_id' => $cama->id, 'estado' => 'entregado',
                                    'fecha_inicio' => '2026-08-01', 'fecha_real' => '2026-09-01']);
        foreach ([['ebanisteria', 1], ['tapizado', 2], ['despacho', 3]] as [$tipo, $n]) {
            $paso = ProduccionPaso::create(['produccion_id' => $prod->id, 'tipo_proceso' => $tipo, 'orden' => $n,
                                            'estado' => 'completado', 'completado_at' => '2026-08-20 12:00:00']);
            DB::table('paso_trabajadores')->insert(['paso_id' => $paso->id, 'usuario_id' => 50, 'horas' => 6, 'calidad' => 5]);
        }

        $despacho = Despacho::create(['tipo' => 'directa', 'estado' => 'completado', 'fecha_despacho' => '2026-09-01']);
        $entrega  = DespachoItem::create(['despacho_id' => $despacho->id, 'orden_id' => $orden->id, 'estado' => 'entregado',
                                          'entregado_at' => Carbon::parse('2026-09-01 17:00:00', 'UTC')]);
        EntregaLinea::create(['despacho_item_id' => $entrega->id, 'orden_item_id' => $cama->id,  'cantidad' => 1, 'resultado' => 'entregado']);
        EntregaLinea::create(['despacho_item_id' => $entrega->id, 'orden_item_id' => $mesas->id, 'cantidad' => 2, 'resultado' => 'entregado']);

        return [$orden, $cama, $mesas, $entrega];
    }

    private function reportar(Usuario $quien, OrdenItem $item, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($quien)->postJson('/api/garantias', $extra + [
            'orden_item_id' => $item->id, 'cantidad' => 1, 'tipo_dano' => 'madera',
            'motivo' => 'Se despegó el espaldar',
        ]);
    }

    private function inv(int $producto, int $tienda): array
    {
        $f = DB::table('inventario')->where('producto_id', $producto)->where('tienda_id', $tienda)->first();

        return [(int) $f->cantidad_disponible, (int) $f->cantidad_reservada];
    }

    // ── Reportar ─────────────────────────────────────────────────────────────

    public function test_se_reporta_con_la_vigencia_del_anexo_y_el_plazo_de_respuesta(): void
    {
        [$orden, $cama] = $this->ordenEntregada();
        $jefa = $this->jefa();

        $this->reportar($jefa, $cama, ['linea' => 'elite_promocional'])
            ->assertCreated()
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('fecha_entrega', '2026-09-01')
            // Madera, línea élite: 5 años desde la entrega.
            ->assertJsonPath('vence_el', '2031-09-01')
            ->assertJsonPath('dentro_de_garantia', true)
            // 15 días hábiles desde el día siguiente: se salta el 12 de octubre
            // (Día de la Raza) y el 2 de noviembre (Todos los Santos).
            ->assertJsonPath('responder_antes_de', '2026-11-03');

        // Reportar no mueve nada: la orden sigue entregada hasta que se decida.
        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertSame(1, (int) $cama->fresh()->cantidad_entregada);
        $this->assertSame(1, DB::table('notificaciones')->where('tipo', 'garantia')->where('urgente', true)->count());
        $this->assertStringContainsString('Garantía reportada', DB::table('orden_mensajes')->value('mensaje'));
    }

    public function test_la_tela_tiene_seis_meses_y_avisa_si_ya_vencio(): void
    {
        [, , $mesas, $entrega] = $this->ordenEntregada();
        $entrega->update(['entregado_at' => Carbon::parse('2026-01-15 17:00:00', 'UTC')]);

        $this->reportar($this->jefa(), $mesas, ['tipo_dano' => 'tela_espuma', 'motivo' => 'Se hundió la espuma'])
            ->assertCreated()
            ->assertJsonPath('vence_el', '2026-07-15')
            ->assertJsonPath('dentro_de_garantia', false);
    }

    public function test_no_se_reclama_mas_de_lo_que_el_cliente_tiene_en_la_casa(): void
    {
        [, $cama, $mesas] = $this->ordenEntregada();
        $jefa = $this->jefa();

        $this->reportar($jefa, $mesas, ['cantidad' => 3])->assertStatus(422);

        $this->reportar($jefa, $cama)->assertCreated();
        // La misma cama ya está esperando dictamen.
        $this->reportar($jefa, $cama)->assertStatus(422);
    }

    // ── Taller ───────────────────────────────────────────────────────────────

    public function test_al_taller_espera_que_lo_recojan_y_no_borra_la_historia_de_fabricacion(): void
    {
        [$orden, $cama] = $this->ordenEntregada();
        $g = $this->reportar($this->jefa(), $cama)->json();

        $this->actingAs($this->jefeTaller())
            ->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'taller', 'procesos' => ['tapizado']])
            ->assertOk()->assertJsonPath('estado', 'por_recoger');

        // El producto se reactiva: vuelve a estar por entregar.
        $cama->refresh();
        $this->assertSame(0, (int) $cama->cantidad_entregada);
        $this->assertSame(1, (int) $cama->cantidad_en_garantia);
        $this->assertSame('en_produccion', $orden->fresh()->estado);

        // Misma producción, reabierta; sin pasos nuevos mientras el mueble
        // siga en la casa.
        $prod = Produccion::where('orden_item_id', $cama->id)->sole();
        $this->assertSame('pendiente', $prod->estado);
        $this->assertSame(3, ProduccionPaso::where('produccion_id', $prod->id)->count());

        $this->actingAs($this->jefa())->postJson("/api/garantias/{$g['id']}/recibir")
            ->assertOk()->assertJsonPath('estado', 'en_taller')
            ->assertJsonPath('devolver_antes_de', '2026-11-08');

        $pasos = ProduccionPaso::where('produccion_id', $prod->id)->orderBy('orden')->get();
        // Los tres de fabricación siguen ahí, completados, con quien los hizo.
        $this->assertSame(['completado', 'completado', 'completado', 'en_proceso', 'pendiente'], $pasos->pluck('estado')->all());
        $this->assertSame(['tapizado', 'despacho'], $pasos->slice(3)->pluck('tipo_proceso')->values()->all());
        $this->assertSame([$g['id'], $g['id']], $pasos->slice(3)->pluck('garantia_id')->map(fn ($v) => (int) $v)->values()->all());
        $this->assertSame(3, DB::table('paso_trabajadores')->count());
        $this->assertSame('en_proceso', $prod->fresh()->estado);
    }

    public function test_la_mesa_arreglada_vuelve_a_la_casa_sin_descontar_stock_otra_vez(): void
    {
        [$orden, , $mesas] = $this->ordenEntregada();
        $jefa = $this->jefa();

        $g = $this->reportar($jefa, $mesas, ['motivo' => 'Pata floja', 'donde_esta' => 'tienda'])->json();
        // La trajo a la tienda: entra al taller de una vez, solo con despacho.
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'taller'])
            ->assertOk()->assertJsonPath('estado', 'en_taller');

        $prod = Produccion::where('orden_item_id', $mesas->id)->sole();
        $this->assertSame('pendiente_despachador', $prod->estado);
        $this->assertSame('en_produccion', $orden->fresh()->estado);
        // Mientras el taller no la dé por lista, no se puede entregar.
        $this->assertFalse($mesas->fresh()->estaListoParaEntregar());

        $prod->update(['estado' => 'listo']);
        $entrega = EntregaService::entregarEnMostrador($orden->fresh(), [$mesas->id => 1], $jefa, 'Garantía');

        // La misma pieza volvió a la casa: el inventario no se mueve.
        $this->assertSame([3, 0], $this->inv(7, 1));
        $this->assertSame(1, (int) EntregaLinea::where('despacho_item_id', $entrega->id)->value('unidades_garantia'));
        $this->assertSame(2, (int) $mesas->fresh()->cantidad_entregada);
        $this->assertSame(0, (int) $mesas->fresh()->cantidad_en_garantia);
        $this->assertSame('entregado', $orden->fresh()->estado);
        $gar = Garantia::find($g['id']);
        $this->assertSame('resuelta', $gar->estado);
        $this->assertSame($entrega->id, (int) $gar->despacho_item_id);

        // Deshacer esa entrega: la garantía se reabre y el stock sigue igual.
        EntregaService::revertir($entrega, $jefa, 'Se registró sin llevarla');
        $this->assertSame('en_taller', $gar->fresh()->estado);
        $this->assertSame([3, 0], $this->inv(7, 1));
        $this->assertSame(1, (int) $mesas->fresh()->cantidad_en_garantia);
        $this->assertSame(1, (int) $mesas->fresh()->cantidad_entregada);
    }

    // ── Cambio ───────────────────────────────────────────────────────────────

    public function test_otra_unidad_igual_se_aparta_en_la_tienda_que_la_tiene(): void
    {
        [$orden, , $mesas] = $this->ordenEntregada();
        $jefa = $this->jefa();
        $g = $this->reportar($jefa, $mesas, ['motivo' => 'Llegó rajada'])->json();

        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'cambio_mismo', 'tienda_id' => 2])
            ->assertOk()->assertJsonPath('estado', 'cambio');

        $nuevo = OrdenItem::where('orden_id', $orden->id)->latest('id')->first();
        $this->assertSame(7, (int) $nuevo->producto_id);
        $this->assertSame(2, (int) $nuevo->tienda_origen_id);
        $this->assertEquals(300000, $nuevo->precio_unitario);
        $this->assertSame([2, 1], $this->inv(7, 2));
        // El renglón viejo se queda con la que no se dañó.
        $this->assertSame(1, (int) $mesas->fresh()->cantidad);
        $this->assertSame(1, (int) $mesas->fresh()->cantidad_entregada);
        // La orden no cambia de valor, y queda lista para llevarle la nueva.
        $this->assertEquals(2600000, $orden->fresh()->valor_total);
        $this->assertSame('listo_entrega', $orden->fresh()->estado);

        EntregaService::entregarEnMostrador($orden->fresh(), [$nuevo->id => 1], $jefa, 'Cambio por garantía');
        $this->assertSame([1, 0], $this->inv(7, 2));
        $this->assertSame([3, 0], $this->inv(7, 1));
        $this->assertSame('resuelta', Garantia::find($g['id'])->estado);
        $this->assertSame('entregado', $orden->fresh()->estado);
    }

    public function test_sin_stock_en_esa_tienda_no_se_inventa_el_cambio(): void
    {
        [, , $mesas] = $this->ordenEntregada();
        $jefa = $this->jefa();
        $g = $this->reportar($jefa, $mesas, ['cantidad' => 2])->json();
        DB::table('inventario')->where('producto_id', 7)->where('tienda_id', 1)->update(['cantidad_reservada' => 2]);

        // En Norte hay 3, pero 2 están apartadas para otras órdenes.
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'cambio_mismo', 'tienda_id' => 1])
            ->assertStatus(422);
        $this->assertSame('pendiente', Garantia::find($g['id'])->estado);
        $this->assertSame(2, (int) $mesas->fresh()->cantidad_entregada);
    }

    public function test_cambiar_por_otro_producto_lo_decide_un_supervisor_y_mueve_el_valor(): void
    {
        [$orden, $cama] = $this->ordenEntregada();
        $jefa = $this->jefa();
        $g = $this->reportar($jefa, $cama)->json();
        DB::table('comisiones')->insert(['orden_id' => $orden->id, 'vendedor_id' => 99, 'mes_venta' => '2026-08',
                                         'valor_orden' => 2600000, 'estado' => 'pendiente']);

        $pedido = ['decision' => 'cambio_otro', 'producto_id' => 8, 'tienda_id' => 2, 'precio_unitario' => 1500000];

        $this->actingAs($this->jefeTaller())->postJson("/api/garantias/{$g['id']}/decidir", $pedido)->assertForbidden();

        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", $pedido)
            ->assertOk()->assertJsonPath('diferencia_valor', -500000);

        $this->assertNotNull($cama->fresh()->devuelto_en);
        $this->assertEquals(2100000, $orden->fresh()->valor_total);
        $this->assertSame([1, 1], $this->inv(8, 2));
        $this->assertEquals(2100000, DB::table('comisiones')->value('valor_orden'));
        $this->assertSame('listo_entrega', $orden->fresh()->estado);
    }

    // ── Reembolso ────────────────────────────────────────────────────────────

    private function pagado(Orden $orden, float $monto): void
    {
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'vendedor_id' => 99, 'tienda_id' => 1, 'tipo' => 'anticipo',
                                    'monto' => $monto, 'metodo' => 'efectivo', 'created_at' => '2026-08-10 15:00:00']);
    }

    public function test_el_reembolso_sale_cuando_llega_el_producto_y_deja_de_ser_venta(): void
    {
        [$orden, , $mesas] = $this->ordenEntregada();
        $this->pagado($orden, 2600000);
        DB::table('comisiones')->insert(['orden_id' => $orden->id, 'vendedor_id' => 99, 'mes_venta' => '2026-08',
                                         'valor_orden' => 2600000, 'estado' => 'pendiente']);
        $jefa = $this->jefa();

        $g = $this->reportar($jefa, $mesas, ['motivo' => 'Se rajó la tapa'])
            ->assertJsonPath('monto_sugerido', 300000)->json();

        // La plata la decide un supervisor.
        $this->actingAs($this->jefeTaller())->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'reembolso', 'monto' => 300000])
            ->assertForbidden();
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'reembolso', 'monto' => 300000])
            ->assertOk()->assertJsonPath('estado', 'por_devolver');

        // Aprobarlo no mueve nada: la plata sale cuando llegue la mesa.
        $this->assertEquals(2600000, $orden->fresh()->valor_total);
        $this->assertSame(1, DB::table('pagos')->count());
        $this->assertSame(2, (int) $mesas->fresh()->cantidad);

        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/recibir-devolucion", [
            'metodo' => 'efectivo', 'destino' => 'inventario', 'tienda_id' => 1,
        ])->assertOk()->assertJsonPath('estado', 'resuelta')->assertJsonPath('destino_devuelto', 'inventario');

        // Deja de ser venta: el renglón se queda con la que no volvió.
        $this->assertSame(1, (int) $mesas->fresh()->cantidad);
        $this->assertSame(1, (int) $mesas->fresh()->cantidad_entregada);
        $o = $orden->fresh();
        $this->assertEquals(2300000, $o->valor_total);
        $this->assertEquals(2300000, DB::table('comisiones')->value('valor_orden'));
        // La plata sale como pago negativo: el saldo queda en cero, no "a favor".
        $this->assertEquals(-300000, DB::table('pagos')->where('tipo', 'reembolso')->value('monto'));
        $this->assertEqualsWithDelta(0, $o->saldoPendiente(), 0.01);
        // La mesa volvió a la tienda y la orden sigue entregada (lo demás se quedó).
        $this->assertSame([4, 0], $this->inv(7, 1));
        $this->assertSame('entregado', $o->estado);
    }

    public function test_si_debia_algo_el_sugerido_descuenta_lo_que_no_ha_pagado_y_la_merma_no_vuelve(): void
    {
        [$orden, $cama] = $this->ordenEntregada();
        $this->pagado($orden, 2500000);   // le faltaban $100.000
        $jefa = $this->jefa();

        $g = $this->reportar($jefa, $cama)->assertJsonPath('monto_sugerido', 1900000)->json();
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'reembolso', 'monto' => 1900000])->assertOk();

        // No se devuelve más de lo que pagó.
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/recibir-devolucion", [
            'metodo' => 'transferencia', 'destino' => 'merma', 'monto' => 3000000,
        ])->assertStatus(422);

        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/recibir-devolucion", [
            'metodo' => 'transferencia', 'destino' => 'merma',
        ])->assertOk();

        $o = $orden->fresh();
        $this->assertNotNull($cama->fresh()->devuelto_en);
        $this->assertEquals(600000, $o->valor_total);
        $this->assertEqualsWithDelta(0, $o->saldoPendiente(), 0.01);
        $this->assertSame([3, 0], $this->inv(7, 1));
    }

    public function test_si_devuelve_todo_la_orden_se_cancela(): void
    {
        [$orden, $cama, $mesas] = $this->ordenEntregada();
        $this->pagado($orden, 2600000);
        $jefa = $this->jefa();

        foreach ([[$cama, 1, 2000000], [$mesas, 2, 600000]] as [$item, $n, $monto]) {
            $g = $this->reportar($jefa, $item, ['cantidad' => $n])->json();
            $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'reembolso', 'monto' => $monto])->assertOk();
            $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/recibir-devolucion", ['metodo' => 'efectivo', 'destino' => 'merma'])->assertOk();
        }

        $o = $orden->fresh();
        $this->assertSame('cancelado', $o->estado);
        $this->assertEquals(0, $o->valor_total);
        $this->assertEqualsWithDelta(0, $o->totalPagado(), 0.01);
    }

    // ── Domicilio y no procede ───────────────────────────────────────────────

    public function test_a_domicilio_se_cierra_con_la_visita_sin_tocar_la_orden(): void
    {
        [$orden, $cama] = $this->ordenEntregada();
        $jefa    = $this->jefa();
        $tecnico = Usuario::create(['nombre' => 'Wilson', 'rol' => 'taller', 'created_at' => now()]);
        $g = $this->reportar($jefa, $cama)->json();

        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", [
            'decision' => 'domicilio', 'visita_por_id' => $tecnico->id, 'visita_fecha' => '2026-10-13',
        ])->assertOk()->assertJsonPath('estado', 'a_domicilio');
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', $tecnico->id)->count());

        $this->actingAs($tecnico)->postJson("/api/garantias/{$g['id']}/visita", ['notas' => 'Se pegó y se atornilló el espaldar'])
            ->assertOk()->assertJsonPath('estado', 'resuelta');

        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertSame(1, (int) $cama->fresh()->cantidad_entregada);
        $this->assertSame('entregado', Produccion::where('orden_item_id', $cama->id)->value('estado'));
    }

    public function test_no_procede_se_cierra_con_la_causal(): void
    {
        [$orden, $cama] = $this->ordenEntregada();
        $jefa = $this->jefa();
        $g = $this->reportar($jefa, $cama)->json();

        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'no_procede'])->assertStatus(422);
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'no_procede', 'causal' => 'maltrato'])
            ->assertOk()->assertJsonPath('estado', 'no_procede')->assertJsonPath('causal_no_procede', 'maltrato');

        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertSame(1, (int) $cama->fresh()->cantidad_entregada);
        // Y ya no se puede decidir otra vez.
        $this->actingAs($jefa)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'domicilio',
            'visita_por_id' => $jefa->id, 'visita_fecha' => '2026-10-13'])->assertStatus(422);
    }

    public function test_la_entrega_original_no_se_deshace_con_una_garantia_encima(): void
    {
        [, $cama, , $entrega] = $this->ordenEntregada();
        $this->assertFalse(GarantiaService::bloqueaDeshacer($entrega));

        $this->reportar($this->jefa(), $cama)->assertCreated();

        $this->assertTrue(GarantiaService::bloqueaDeshacer($entrega->fresh()));
    }

    public function test_un_vendedor_reporta_pero_no_decide(): void
    {
        [, $cama] = $this->ordenEntregada();
        $vendedora = Usuario::create(['nombre' => 'Laura', 'rol' => 'vendedor', 've_todas_ordenes' => true, 'created_at' => now()]);

        $g = $this->reportar($vendedora, $cama)->assertCreated()->json();
        $this->actingAs($vendedora)->postJson("/api/garantias/{$g['id']}/decidir", ['decision' => 'taller'])->assertForbidden();
    }
}
