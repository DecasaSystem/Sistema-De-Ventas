<?php

namespace Tests\Feature;

use App\Models\DespachoItem;
use App\Models\EntregaLinea;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Se entregan productos, no órdenes.
 *
 * El caso: el cliente compra un reloj que está en la tienda y un mueble que
 * hay que fabricar. El reloj se lo lleva hoy; el mueble sale cuando el taller
 * lo termine. Antes eso no se podía decir: la entrega era de la orden entera
 * —o todo o nada— y "se lo lleva de una" rechazaba la venta si había algo
 * para fabricar.
 *
 * Se comprueba el camino completo: la venta con "se lo lleva ahora" por
 * producto, la entrega directa de lo que falta cuando el taller termina, el
 * pago que solo se exige en la última entrega, lo que pasa con el stock y con
 * la producción, y que se pueda deshacer.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class EntregaPorProductoTest extends TestCase
{
    private const RELOJ = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('acceso_entregas')->default(false); $t->boolean('facturacion')->default(false);
            $t->boolean('acceso_despacho')->default(false);
            $t->boolean('notif_stock')->default(false);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->string('direccion')->nullable();
            $t->string('cedula')->nullable(); $t->timestamps();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
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
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
            $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
            $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 15, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });

        $this->completarEsquemaDeEntregas();

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'cedula' => '123', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => self::RELOJ, 'nombre' => 'Reloj de pared']);
        DB::table('inventario')->insert(['producto_id' => self::RELOJ, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0]);

        // Las fotos de la entrega se suben a Cloudinary: acá no.
        Http::fake(['api.cloudinary.com/*' => Http::response(['secure_url' => 'https://foto/x.jpg'])]);
    }

    private function vendedora(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v' . rand() . '@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 1, 'firma_url' => 'https://ejemplo/firma.png', 'acceso_entregas' => true,
            'created_at' => now(),
        ]);
    }

    private function supervisora(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j' . rand() . '@d.com', 'password' => 'x', 'rol' => 'supervisor',
            'firma_url' => 'https://ejemplo/firma.png', 'acceso_entregas' => true, 'created_at' => now(),
        ]);
    }

    /** Vende un reloj (catálogo) y un comedor (a fabricar). */
    private function venderRelojYMueble(Usuario $v, bool $relojSeLoLleva): Orden
    {
        $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url' => 'https://ejemplo/firma.png',
            'items' => [
                ['producto_id' => self::RELOJ, 'cantidad' => 1, 'precio_unitario' => 200000, 'llevar_ahora' => $relojSeLoLleva],
                ['nombre_custom' => 'Comedor a la medida', 'cantidad' => 1, 'precio_unitario' => 3000000],
            ],
        ])->assertCreated();

        return Orden::latest('id')->first();
    }

    private function reloj(Orden $orden): OrdenItem
    {
        return $orden->items()->where('producto_id', self::RELOJ)->first();
    }

    private function mueble(Orden $orden): OrdenItem
    {
        return $orden->items()->whereNull('producto_id')->first();
    }

    private function stockReloj(): array
    {
        $i = DB::table('inventario')->where('producto_id', self::RELOJ)->first();
        return [(int) $i->cantidad_disponible, (int) $i->cantidad_reservada];
    }

    /** Abre y cierra una entrega directa con lo que se le diga, con acta y (si hace falta) pago. */
    private function entregar(Usuario $quien, Orden $orden, ?array $lineas, array $pago = [])
    {
        $abrir = $this->actingAs($quien)->postJson('/api/despacho/entrega-directa', ['orden_id' => $orden->id]);
        $abrir->assertStatus(201);
        $entregaId = $abrir->json('despacho_item_id');

        $campos = array_merge([
            'firma_omitida_motivo' => 'prueba',
            'monto' => 0,
        ], $pago);
        if ($lineas !== null) $campos['lineas'] = json_encode($lineas);

        $archivos = ['foto_producto' => UploadedFile::fake()->image('p.jpg')];
        if (($campos['monto'] ?? 0) > 0) $archivos['foto_pago'] = UploadedFile::fake()->image('c.jpg');

        $pagoResp = $this->actingAs($quien)->post("/api/despacho/mis-entregas/{$entregaId}/pago", $campos + $archivos, ['Accept' => 'application/json']);
        if ($pagoResp->status() !== 200) return $pagoResp;

        return $this->actingAs($quien)->patchJson("/api/despacho/mis-entregas/{$entregaId}/entregar");
    }

    // ── "Se lo lleva ahora" al vender ────────────────────────────────────────

    public function test_el_reloj_se_lo_lleva_hoy_y_el_mueble_se_fabrica(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: true);

        // La venta no se rechazó y la orden sigue viva: el comedor está en el taller.
        $this->assertSame('en_produccion', $orden->estado);
        $this->assertSame(1, Produccion::count());

        // El reloj ya salió: entregado, fuera del inventario, con su entrega escrita.
        $this->assertSame(1, (int) $this->reloj($orden)->cantidad_entregada);
        $this->assertSame([2, 0], $this->stockReloj());
        $this->assertSame(1, DespachoItem::where('orden_id', $orden->id)->where('estado', 'entregado')->count());
        $this->assertSame(1, EntregaLinea::where('orden_item_id', $this->reloj($orden)->id)->count());

        // Y la orden dice que va por la mitad.
        $entrega = $orden->fresh()->resumenEntrega();
        $this->assertTrue($entrega['parcial']);
        $this->assertSame(1, $entrega['entregados']);
        $this->assertSame(2, $entrega['total']);
    }

    public function test_sin_la_marca_el_reloj_se_queda_apartado(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: false);

        $this->assertSame(0, (int) $this->reloj($orden)->cantidad_entregada);
        $this->assertSame([3, 1], $this->stockReloj(), 'apartado, no vendido');
        $this->assertSame(0, DespachoItem::count());
    }

    public function test_si_todo_se_lo_lleva_la_orden_nace_entregada(): void
    {
        $v = $this->vendedora();
        $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url' => 'https://ejemplo/firma.png',
            'items' => [['producto_id' => self::RELOJ, 'cantidad' => 2, 'precio_unitario' => 200000, 'llevar_ahora' => true]],
        ])->assertCreated();

        $orden = Orden::first();
        $this->assertSame('entregado', $orden->estado);
        $this->assertSame([1, 0], $this->stockReloj());
        $this->assertTrue($orden->resumenEntrega()['completa']);
    }

    // ── La entrega directa, por producto ─────────────────────────────────────

    public function test_el_reloj_se_entrega_aunque_el_mueble_siga_en_el_taller(): void
    {
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: false);

        // Solo el reloj está listo: es lo que la pantalla ofrece.
        $this->assertTrue($orden->fresh()->laPuedeEntregarDirecto($v));
        $this->assertSame([$this->reloj($orden)->id], $orden->itemsEntregables()->pluck('id')->all());

        $r = $this->entregar($v, $orden, [['orden_item_id' => $this->reloj($orden)->id, 'cantidad' => 1]]);
        $r->assertOk();

        $orden->refresh();
        $this->assertSame(1, (int) $this->reloj($orden)->cantidad_entregada);
        $this->assertSame(0, (int) $this->mueble($orden)->cantidad_entregada);
        $this->assertSame([2, 0], $this->stockReloj());
        // El comedor sigue en el taller y la orden lo dice.
        $this->assertSame('en_produccion', $orden->estado);
        $this->assertSame('pendiente', Produccion::first()->estado);
    }

    public function test_el_mueble_no_se_puede_marcar_hasta_que_el_taller_lo_de_por_listo(): void
    {
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: false);

        // Hay algo que entregar (el reloj), pero el comedor no se puede marcar.
        $r = $this->entregar($v, $orden, [['orden_item_id' => $this->mueble($orden)->id, 'cantidad' => 1]]);
        $r->assertStatus(422);
        $this->assertStringContainsString('taller', $r->json('message'));

        // Y con el reloj ya entregado, no queda nada que abrir.
        $this->entregar($v, $orden, [['orden_item_id' => $this->reloj($orden)->id, 'cantidad' => 1]])->assertOk();
        $this->actingAs($v)->postJson('/api/despacho/entrega-directa', ['orden_id' => $orden->id])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Todavía no hay nada que entregar: lo de catálogo ya se entregó y lo demás sigue en el taller.']);
    }

    public function test_en_una_entrega_parcial_no_se_exige_el_saldo(): void
    {
        // El cliente todavía va a recibir el comedor: no se le cobra todo hoy.
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: false);
        $this->assertGreaterThan(0, $orden->saldoPendiente());

        $this->entregar($v, $orden, [['orden_item_id' => $this->reloj($orden)->id, 'cantidad' => 1]])->assertOk();

        $this->assertSame(0, $orden->pagos()->count());
    }

    public function test_en_la_ultima_entrega_si_se_cobra_el_saldo(): void
    {
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: true);

        // El taller terminó el comedor.
        Produccion::query()->update(['estado' => 'listo']);
        $orden->update(['estado' => 'listo_entrega']);

        // Sin pago, no se puede cerrar: es la última y debe todo.
        $this->entregar($v, $orden, null)->assertStatus(422);

        // Con el saldo, sí: y la orden queda entregada.
        $this->entregar($v, $orden, null, ['monto' => 3200000, 'metodo' => 'efectivo'])->assertOk();

        $orden->refresh();
        $this->assertSame('entregado', $orden->estado);
        $this->assertSame('saldo_final', $orden->pagos()->first()->tipo);
        $this->assertSame('entregado', Produccion::first()->estado);
        $this->assertTrue($orden->resumenEntrega()['completa']);
    }

    public function test_un_abono_en_una_parcial_queda_como_abono(): void
    {
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: false);

        $this->entregar($v, $orden, [['orden_item_id' => $this->reloj($orden)->id, 'cantidad' => 1]],
            ['monto' => 200000, 'metodo' => 'transferencia'])->assertOk();

        $pago = $orden->pagos()->first();
        $this->assertSame('abono', $pago->tipo);
        $this->assertSame(['https://foto/x.jpg'], $pago->comprobante_fotos);
    }

    public function test_de_dos_relojes_se_puede_llevar_uno(): void
    {
        $v = $this->vendedora();
        $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url' => 'https://ejemplo/firma.png',
            'items' => [['producto_id' => self::RELOJ, 'cantidad' => 2, 'precio_unitario' => 200000]],
        ])->assertCreated();
        $orden = Orden::first();
        $item  = $orden->items()->first();

        $this->entregar($v, $orden, [['orden_item_id' => $item->id, 'cantidad' => 1]])->assertOk();

        $this->assertSame(1, (int) $item->fresh()->cantidad_entregada);
        $this->assertSame([2, 1], $this->stockReloj(), 'uno salió, el otro sigue apartado');
        $this->assertNotSame('entregado', $orden->fresh()->estado);

        // No se puede entregar más de lo que falta.
        $this->entregar($v, $orden, [['orden_item_id' => $item->id, 'cantidad' => 2]])->assertStatus(422);
    }

    // ── El supervisor desde la orden ─────────────────────────────────────────

    public function test_el_supervisor_no_puede_marcar_entregada_una_orden_con_algo_en_el_taller(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: true);

        $this->actingAs($this->supervisora())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'entregado'])
            ->assertStatus(422);
    }

    public function test_el_supervisor_marca_entregada_y_queda_como_entrega_de_mostrador(): void
    {
        $v = $this->vendedora();
        $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url' => 'https://ejemplo/firma.png',
            'items' => [['producto_id' => self::RELOJ, 'cantidad' => 1, 'precio_unitario' => 200000]],
        ])->assertCreated();
        $orden = Orden::first();

        $this->actingAs($this->supervisora())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'entregado'])
            ->assertOk();

        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertSame([2, 0], $this->stockReloj());
        // Quedó escrito quién y cómo, no solo el estado.
        $entrega = DespachoItem::first();
        $this->assertSame('entregado', $entrega->estado);
        $this->assertStringContainsString('Jefa', $entrega->firma_omitida_motivo);
    }

    // ── Deshacer ─────────────────────────────────────────────────────────────

    public function test_revertir_devuelve_al_inventario_solo_lo_que_salio(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: true);
        $this->assertSame([2, 0], $this->stockReloj());

        // La orden no está entregada (falta el comedor), así que primero se
        // termina: el taller lo da por listo y se entrega todo.
        Produccion::query()->update(['estado' => 'listo']);
        $orden->update(['estado' => 'listo_entrega']);
        $v = Usuario::first();
        $this->entregar($v, $orden, null, ['monto' => 3200000, 'metodo' => 'efectivo'])->assertOk();
        $this->assertSame('entregado', $orden->fresh()->estado);

        $this->actingAs($this->supervisora())
            ->patchJson("/api/ordenes/{$orden->id}/revertir-entrega", ['motivo' => 'se marcó por error'])
            ->assertOk();

        $orden->refresh();
        $this->assertSame('pendiente_anticipo', $orden->estado);
        $this->assertSame(0, (int) $this->reloj($orden)->cantidad_entregada);
        $this->assertSame(0, (int) $this->mueble($orden)->cantidad_entregada);
        $this->assertSame([3, 1], $this->stockReloj(), 'vuelve, y vuelve apartado');
        $this->assertSame('listo', Produccion::first()->estado);
        $this->assertSame(0, EntregaLinea::count());
    }

    // ── Pagar no es entregar ─────────────────────────────────────────────────

    public function test_pagar_todo_no_marca_la_orden_como_entregada(): void
    {
        $v = $this->vendedora();
        $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url' => 'https://ejemplo/firma.png',
            'items' => [['producto_id' => self::RELOJ, 'cantidad' => 1, 'precio_unitario' => 200000]],
        ])->assertCreated();
        $orden = Orden::first();
        $orden->update(['estado' => 'listo_entrega']);

        $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/pagos", [
            'monto' => 200000, 'metodo' => 'efectivo', 'comprobante_url' => 'https://foto/c.jpg',
        ])->assertStatus(201);

        $this->assertSame('listo_entrega', $orden->fresh()->estado, 'nadie la ha entregado');
        $this->assertSame([3, 1], $this->stockReloj());
    }

    // ── Rutas de conductor por producto (fase D) ─────────────────────────────

    private function despachadora(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Despacha', 'email' => 'd' . rand() . '@d.com', 'password' => 'x', 'rol' => 'supervisor',
            'acceso_despacho' => true, 'acceso_entregas' => true, 'firma_url' => 'x', 'created_at' => now(),
        ]);
    }

    public function test_la_cola_muestra_la_orden_del_taller_que_ya_tiene_algo_listo(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: false);
        $this->assertNotSame('listo_entrega', $orden->estado, 'el comedor sigue en el taller');

        $cola = $this->actingAs($this->despachadora())->getJson('/api/despacho/cola')->assertOk()->json();

        $this->assertCount(1, $cola);
        $this->assertSame($orden->id, $cola[0]['id']);
        // Y dice qué se puede subir al camión y qué no.
        $items = collect($cola[0]['items'])->keyBy('id');
        $this->assertTrue($items[$this->reloj($orden)->id]['entregable']);
        $this->assertFalse($items[$this->mueble($orden)->id]['entregable']);
    }

    public function test_una_orden_del_taller_sin_nada_listo_no_sale_en_la_cola(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: true);
        $this->assertSame('en_produccion', $orden->fresh()->estado);

        $this->actingAs($this->despachadora())->getJson('/api/despacho/cola')->assertOk()->assertJsonCount(0);
    }

    public function test_la_ruta_lleva_solo_lo_que_se_marco_y_el_conductor_entrega_eso(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: false);
        $jefa  = $this->despachadora();

        $ruta = $this->actingAs($jefa)->postJson('/api/despacho/rutas', [
            'nombre_ruta' => 'Norte', 'fecha_despacho' => now()->toDateString(),
        ])->assertStatus(201)->json();

        // El comedor no se puede subir: sigue en el taller.
        $this->actingAs($jefa)->postJson("/api/despacho/rutas/{$ruta['id']}/ordenes", [
            'orden_id' => $orden->id,
            'lineas'   => [['orden_item_id' => $this->mueble($orden)->id, 'cantidad' => 1]],
        ])->assertStatus(422);

        // El reloj sí.
        $agregada = $this->actingAs($jefa)->postJson("/api/despacho/rutas/{$ruta['id']}/ordenes", [
            'orden_id' => $orden->id,
            'lineas'   => [['orden_item_id' => $this->reloj($orden)->id, 'cantidad' => 1]],
        ])->assertStatus(201)->json();
        $this->assertCount(1, $agregada['lineas']);
        $this->assertSame($this->reloj($orden)->id, $agregada['lineas'][0]['orden_item_id']);

        // La ruta sale con un conductor (se simula el envío: no hay camiones en la prueba).
        $conductor = Usuario::create(['nombre' => 'Conduce', 'email' => 'c@d.com', 'password' => 'x',
                                      'rol' => 'conductor', 'created_at' => now()]);
        DB::table('despachos')->where('id', $ruta['id'])->update(['estado' => 'en_ruta', 'conductor_id' => $conductor->id]);
        $entregaId = $agregada['id'];

        // El conductor no marca nada en particular: se entrega lo que se cargó.
        $this->actingAs($conductor)->post("/api/despacho/mis-entregas/{$entregaId}/pago", [
            'firma_omitida_motivo' => 'prueba', 'monto' => 0,
            'foto_producto' => UploadedFile::fake()->image('p.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($conductor)->patchJson("/api/despacho/mis-entregas/{$entregaId}/entregar")->assertOk();

        $orden->refresh();
        $this->assertSame(1, (int) $this->reloj($orden)->cantidad_entregada);
        $this->assertSame(0, (int) $this->mueble($orden)->cantidad_entregada);
        $this->assertSame('en_produccion', $orden->estado, 'el comedor sigue en el taller');
        $this->assertSame('completado', DB::table('despachos')->where('id', $ruta['id'])->value('estado'));

        // Y el saldo no se le exigió: le falta recibir el comedor.
        $this->assertSame(0, $orden->pagos()->count());
    }

    public function test_al_terminar_el_taller_la_orden_vuelve_a_la_cola_con_lo_que_falta(): void
    {
        $orden = $this->venderRelojYMueble($this->vendedora(), relojSeLoLleva: true);
        Produccion::query()->update(['estado' => 'listo']);
        $orden->update(['estado' => 'listo_entrega']);

        $cola = $this->actingAs($this->despachadora())->getJson('/api/despacho/cola')->assertOk()->json();
        $this->assertCount(1, $cola);
        $this->assertTrue($cola[0]['entrega']['parcial']);
        $items = collect($cola[0]['items'])->keyBy('id');
        $this->assertSame(0, $items[$this->reloj($orden)->id]['pendiente_entregar']);
        $this->assertSame(1, $items[$this->mueble($orden)->id]['pendiente_entregar']);
    }

    // ── El vendedor da la orden por lista cuando le llega la mercancía ───────

    public function test_el_vendedor_con_permiso_marca_lista_su_orden_y_lo_fabricado_queda_entregable(): void
    {
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: true);
        $this->assertSame('pendiente', Produccion::first()->estado);

        // Le llegó el comedor del almacén: lo da por listo él mismo.
        $this->actingAs($v)->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'listo_entrega'])->assertOk();

        $orden->refresh();
        $this->assertSame('listo_entrega', $orden->estado);
        $this->assertSame('listo', Produccion::first()->estado, 'lo del taller se da por terminado');
        $this->assertTrue($this->mueble($orden)->fresh()->estaListoParaEntregar());
        $this->assertTrue($orden->laPuedeEntregarDirecto($v));
    }

    public function test_el_vendedor_no_puede_cambiar_otros_estados_ni_ordenes_ajenas(): void
    {
        $v     = $this->vendedora();
        $orden = $this->venderRelojYMueble($v, relojSeLoLleva: false);

        $this->actingAs($v)->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])->assertStatus(403);

        $otra = $this->vendedora();
        $this->actingAs($otra)->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'listo_entrega'])->assertStatus(403);
    }
}
