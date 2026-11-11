<?php

namespace Tests\Feature;

use App\Models\FacturaElectronica;
use App\Models\Usuario;
use App\Services\Facturacion\ArmarFactura;
use App\Support\ReglasFacturacion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Facturación electrónica con Alegra (docs/plan-facturacion-alegra.md).
 *
 * El flujo que pidió el dueño (2026-11-09): la orden se entrega → le aparece
 * al facturador → la revisa con todo → toca Facturar → sale en Alegra. Y todo
 * personalizable desde Ajustes: cuándo se factura, si las FV2, las
 * restauraciones o las de independientes se facturan, si hace falta revisión.
 *
 * Alegra no se llama de verdad: Http::fake responde como la API documentada
 * (developer.alegra.com). Lo más importante que se prueba es que NUNCA salgan
 * dos facturas de lo mismo (Alegra no tiene llave de idempotencia).
 *
 * El esquema se monta a mano (el historial de migraciones no corre en
 * SQLite), pero la tabla nueva y las columnas de clientes salen de la
 * migración real.
 */
class FacturacionElectronicaTest extends TestCase
{
    private const NORTE = 1;

    private int $supervisor;
    private int $facturador;
    private int $vendedor;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->boolean('facturacion')->default(false);
            $t->boolean('independiente')->default(false); $t->boolean('ve_todas_ordenes')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable(); });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('cedula')->nullable(); $t->string('telefono')->nullable();
            $t->string('email')->nullable(); $t->string('direccion')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('precio_base', 14, 2)->default(0); $t->decimal('precio_pieza', 14, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('pendiente_anticipo');
            $t->string('serie')->nullable(); $t->integer('serie_numero')->nullable(); $t->integer('numero_orden')->nullable();
            $t->string('numero_anulado')->nullable(); $t->boolean('sin_descontar_iva')->default(false);
            $t->decimal('valor_total', 14, 2)->default(0); $t->decimal('descuento_total', 14, 2)->default(0);
            $t->decimal('descuento_condicionado', 14, 2)->default(0);
            $t->string('direccion_envio')->nullable(); $t->string('ciudad_envio')->nullable(); $t->string('departamento_envio')->nullable();
            $t->json('factura_fotos')->nullable(); $t->string('factura_foto_url')->nullable(); $t->string('anexo_foto_url')->nullable();
            $t->string('canal')->nullable(); $t->text('notas')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->integer('piezas_juego')->nullable(); $t->boolean('es_pieza_suelta')->default(false);
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 14, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('es_restauracion')->default(false);
            $t->timestamp('devuelto_en')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('tipo')->default('anticipo');
            $t->decimal('monto', 14, 2); $t->string('metodo')->nullable(); $t->string('referencia')->nullable();
            $t->text('notas')->nullable(); $t->string('comprobante_url')->nullable(); $t->json('comprobante_fotos')->nullable();
            $t->unsignedBigInteger('facturacion_tomada_por')->nullable(); $t->timestamp('facturacion_hecha_at')->nullable();
            $t->timestamps();
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->string('estado')->nullable(); $t->timestamp('entregado_at')->nullable();
            $t->string('foto_producto')->nullable(); $t->json('fotos_producto')->nullable();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('solicitudes_cambio', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->string('estado'); $t->timestamps();
        });
        Schema::create('configuracion', function (Blueprint $t) { $t->string('clave')->primary(); $t->text('valor'); });

        // La migración de verdad: tabla nueva + columnas de clientes.
        (require base_path('database/migrations/2026_11_10_000001_facturacion_electronica.php'))->up();

        DB::table('tiendas')->insert(['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'ciudad' => 'Armenia']);
        $this->supervisor = DB::table('usuarios')->insertGetId(['nombre' => 'Sup', 'rol' => 'supervisor']);
        $this->facturador = DB::table('usuarios')->insertGetId(['nombre' => 'Fac', 'rol' => 'vendedor', 'facturacion' => true, 'tienda_default_id' => self::NORTE]);
        $this->vendedor   = DB::table('usuarios')->insertGetId(['nombre' => 'Ven', 'rol' => 'vendedor', 'tienda_default_id' => self::NORTE]);

        config([
            'services.alegra.email' => 'api@decasa.test',
            'services.alegra.token' => 'token-de-prueba',
            'services.alegra.url'   => 'https://api.alegra.com/api/v1',
        ]);
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    private function prender(array $extra = []): void
    {
        [, $errores] = ReglasFacturacion::guardar(array_merge([
            'activo' => true, 'modo' => 'emitir', 'desde' => '2026-11-01',
            'impuesto_iva_id' => '3', 'item_producto_id' => '10', 'item_servicio_id' => '11',
            'numeraciones' => ['*' => '5'],
            'cuentas' => ['*' => ['efectivo' => '1', 'transferencia' => '2', 'tarjeta' => '3', 'addi' => '2', 'otro' => '1']],
        ], $extra));
        $this->assertSame([], $errores);
    }

    /**
     * Una orden entregada el 5 de noviembre: un sofá de $2.380.000 y dos mesas
     * de $595.000, con $119.000 de descuento → el cliente paga $3.451.000.
     */
    private function ordenEntregada(array $orden = [], array $cliente = [], ?array $pagos = null, ?array $items = null): int
    {
        $clienteId = DB::table('clientes')->insertGetId(array_merge([
            'nombre' => 'Ana María Gómez Ruiz', 'cedula' => '1094888777', 'email' => 'ana@correo.test',
            'telefono' => '3001234567', 'direccion' => 'Cra 14 # 20-30', 'ciudad' => 'Armenia', 'departamento' => 'Quindío',
        ], $cliente));
        $sofa = DB::table('productos')->insertGetId(['nombre' => 'Sofá Bali', 'precio_base' => 2380000]);
        $mesa = DB::table('productos')->insertGetId(['nombre' => 'Mesa Roma', 'precio_base' => 595000]);

        $id = DB::table('ordenes')->insertGetId(array_merge([
            'cliente_id' => $clienteId, 'tienda_id' => self::NORTE, 'vendedor_id' => $this->vendedor,
            'estado' => 'entregado', 'numero_orden' => 4500 + DB::table('ordenes')->count(),
            'valor_total' => 3451000, 'descuento_total' => 119000,
            'created_at' => '2026-11-02 15:00:00', 'updated_at' => '2026-11-05 15:00:00',
        ], $orden));

        foreach ($items ?? [
            ['producto_id' => $sofa, 'cantidad' => 1, 'precio_unitario' => 2380000],
            ['producto_id' => $mesa, 'cantidad' => 2, 'precio_unitario' => 595000],
        ] as $it) {
            DB::table('orden_items')->insert(['orden_id' => $id] + $it);
        }
        foreach ($pagos ?? [
            ['tipo' => 'anticipo', 'monto' => 1725500, 'metodo' => 'transferencia', 'created_at' => '2026-11-02 15:00:00'],
            ['tipo' => 'saldo_final', 'monto' => 1725500, 'metodo' => 'efectivo', 'created_at' => '2026-11-05 15:00:00'],
        ] as $p) {
            DB::table('pagos')->insert(['orden_id' => $id, 'tienda_id' => self::NORTE] + $p);
        }
        DB::table('despacho_items')->insert(['orden_id' => $id, 'estado' => 'entregado', 'entregado_at' => '2026-11-05 15:00:00']);

        return $id;
    }

    private function como(int $id): Usuario
    {
        return Usuario::find($id);
    }

    /** Alegra que todo lo acepta. Devuelve la lista de peticiones que le llegaron. */
    private function alegraQueAcepta(string $legal = 'STAMPED_AND_ACCEPTED'): void
    {
        Http::fake([
            'api.alegra.com/api/v1/contacts?*' => Http::response([], 200),
            'api.alegra.com/api/v1/contacts'   => Http::response(['id' => '77', 'name' => 'Ana'], 201),
            'api.alegra.com/api/v1/invoices'   => fn (PeticionHttp $r) => Http::response([
                'id' => '500', 'status' => isset($r['stamp']) ? 'open' : 'draft', 'total' => 3451000,
                'numberTemplate' => ['id' => '5', 'prefix' => 'FE', 'number' => '12', 'fullNumber' => 'FE12'],
                'stamp' => isset($r['stamp']) ? ['legalStatus' => $legal, 'cufe' => 'cufe-abc'] : null,
            ], 201),
            'api.alegra.com/api/v1/invoices/500*' => Http::response(['id' => '500', 'status' => 'open', 'pdf' => 'https://alegra.test/fe12.pdf'], 200),
        ]);
    }

    private function facturar(int $ordenId, array $extra = [], ?int $quien = null)
    {
        return $this->actingAs($this->como($quien ?? $this->facturador))
            ->postJson("/api/facturacion-electronica/ordenes/{$ordenId}/facturar", array_merge(['revisado' => true], $extra));
    }

    private function postsAFacturas(): int
    {
        return count(Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/invoices')));
    }

    // ── Ajustes ─────────────────────────────────────────────────────────────

    public function test_viene_apagado_y_con_las_reglas_que_pidio_el_dueno(): void
    {
        $r = ReglasFacturacion::todo();
        $this->assertFalse($r['activo']);
        $this->assertSame('al_entregar', $r['momento']);
        $this->assertFalse($r['facturar_fv2']);
        $this->assertTrue($r['requiere_revision']);
        $this->assertSame('borrador', $r['modo'], 'Se empieza probando: nada va a la DIAN hasta que lo cambien.');

        $this->ordenEntregada();
        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_solo_un_supervisor_cambia_los_ajustes(): void
    {
        $this->actingAs($this->como($this->facturador))
            ->putJson('/api/facturacion-electronica/reglas', ['reglas' => ['activo' => true]])->assertForbidden();
        $this->actingAs($this->como($this->vendedor))
            ->getJson('/api/facturacion-electronica/pendientes')->assertForbidden();

        $this->actingAs($this->como($this->supervisor))
            ->putJson('/api/facturacion-electronica/reglas', ['reglas' => ['activo' => true, 'facturar_restauraciones' => false]])
            ->assertOk()->assertJsonPath('reglas.activo', true)->assertJsonPath('reglas.facturar_restauraciones', false);

        // Prenderlo sin fecha pone hoy: si no, saldría toda la historia (ya facturada a mano).
        $this->assertSame(now('America/Bogota')->toDateString(), ReglasFacturacion::get('desde'));
    }

    public function test_un_valor_raro_en_los_ajustes_no_se_guarda(): void
    {
        $this->actingAs($this->como($this->supervisor))
            ->putJson('/api/facturacion-electronica/reglas', ['reglas' => ['momento' => 'cuando_sea']])
            ->assertStatus(422);
        $this->assertSame('al_entregar', ReglasFacturacion::get('momento'));
    }

    // ── Qué sale por facturar ───────────────────────────────────────────────

    public function test_sale_lo_entregado_y_no_las_fv2_ni_lo_que_falta_por_entregar(): void
    {
        $this->prender();
        $normal  = $this->ordenEntregada();
        $fv2     = $this->ordenEntregada(['serie' => 'FV2', 'serie_numero' => 3, 'numero_orden' => null]);
        $enCamino = $this->ordenEntregada(['estado' => 'en_camino']);
        $vieja   = $this->ordenEntregada();
        DB::table('despacho_items')->where('orden_id', $vieja)->update(['entregado_at' => '2026-10-20 15:00:00']);

        $ids = collect($this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')
            ->assertOk()->json('data'))->pluck('orden_id')->all();

        $this->assertSame([$normal], $ids);

        // Si en Ajustes prenden las FV2, salen.
        $this->prender(['facturar_fv2' => true]);
        $ids = collect($this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')
            ->json('data'))->pluck('orden_id')->sort()->values()->all();
        $this->assertSame([$normal, $fv2], $ids);
        $this->assertNotContains($enCamino, $ids);
    }

    public function test_restauraciones_e_independientes_se_pueden_sacar_desde_ajustes(): void
    {
        $this->prender();
        $resta = $this->ordenEntregada(['serie' => 'R', 'serie_numero' => 1100, 'numero_orden' => null],
            items: [['nombre_custom' => 'Restauración sofá', 'cantidad' => 1, 'precio_unitario' => 3451000, 'es_restauracion' => true]]);
        $indep = DB::table('usuarios')->insertGetId(['nombre' => 'Henry', 'rol' => 'vendedor', 'independiente' => true]);
        $deIndep = $this->ordenEntregada(['vendedor_id' => $indep]);

        $ver = fn () => collect($this->actingAs($this->como($this->facturador))
            ->getJson('/api/facturacion-electronica/pendientes')->json('data'))->pluck('orden_id')->sort()->values()->all();

        $this->assertSame([$resta, $deIndep], $ver());
        $this->prender(['facturar_restauraciones' => false]);
        $this->assertSame([$deIndep], $ver());
        $this->prender(['facturar_restauraciones' => true, 'facturar_independientes' => false]);
        $this->assertSame([$resta], $ver());
    }

    // ── Las cuentas ─────────────────────────────────────────────────────────

    public function test_la_factura_suma_lo_que_pago_el_cliente_con_el_iva_aparte(): void
    {
        $this->prender();
        $id = $this->ordenEntregada();
        $armado = ArmarFactura::de(\App\Models\Orden::find($id));

        // 3.451.000 = base 2.900.000 + IVA 551.000 (19 %).
        $this->assertEqualsWithDelta(2900000, $armado['totales']['base'], 1);
        $this->assertEqualsWithDelta(551000, $armado['totales']['iva'], 1);
        $this->assertEqualsWithDelta(3451000, $armado['totales']['total'], 1);

        // El descuento se reparte: el sofá (2.380.000 de 3.570.000) se lleva 2/3 de los 119.000.
        $sofa = $armado['lineas'][0];
        $this->assertEqualsWithDelta(2300666.67, $sofa['neto_con_iva'], 0.01);
        $this->assertSame(round(2380000 * (3451000 / 3570000) / 1.19, 2), $sofa['base_unitaria']);
        $this->assertSame('CASH', $armado['forma_pago'], 'Pagó todo: de contado.');
        $this->assertTrue($armado['se_puede']);

        // Va al ítem, con el impuesto de IVA escogido y el precio SIN IVA.
        $item = $armado['factura']['items'][0];
        $this->assertSame('10', $item['id']);
        $this->assertSame([['id' => '3']], $item['tax']);
        $this->assertSame($sofa['base_unitaria'], $item['price']);
    }

    public function test_si_queda_saldo_sale_a_credito_y_se_avisa(): void
    {
        $this->prender();
        $id = $this->ordenEntregada(pagos: [['tipo' => 'anticipo', 'monto' => 1725500, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00']]);
        $armado = ArmarFactura::de(\App\Models\Orden::find($id));

        $this->assertSame('CREDIT', $armado['forma_pago']);
        $this->assertContains('saldo', $armado['advertencias']);
    }

    public function test_sin_datos_del_cliente_no_se_puede_y_el_facturador_los_completa(): void
    {
        $this->prender();
        $id = $this->ordenEntregada(cliente: ['cedula' => null, 'ciudad' => null, 'departamento' => null]);

        $det = $this->actingAs($this->como($this->facturador))->getJson("/api/facturacion-electronica/ordenes/{$id}")->assertOk();
        $this->assertFalse($det->json('armado.se_puede'));
        $this->assertContains('documento', $det->json('armado.cliente.faltan'));
        // La ciudad la toma de la tienda; el departamento no se adivina.
        $this->assertSame('Armenia', $det->json('armado.cliente.ciudad'));
        $this->assertContains('departamento', $det->json('armado.cliente.faltan'));

        $this->facturar($id)->assertStatus(422);

        $clienteId = $det->json('armado.cliente.id');
        $this->actingAs($this->como($this->facturador))->putJson("/api/facturacion-electronica/clientes/{$clienteId}", [
            'cedula' => '1.094.888.777', 'tipo_documento' => 'CC', 'departamento' => 'Quindío',
        ])->assertOk()->assertJsonPath('cliente.cedula', '1094888777');

        $this->actingAs($this->como($this->facturador))->getJson("/api/facturacion-electronica/ordenes/{$id}")
            ->assertJsonPath('armado.se_puede', true);
    }

    public function test_el_digito_de_verificacion_del_nit_y_el_nombre_partido(): void
    {
        $this->assertSame(8, ArmarFactura::digitoVerificacion('890903938')); // Bancolombia
        $this->assertSame(4, ArmarFactura::digitoVerificacion('800.197.268')); // la DIAN
        $this->assertSame(['Ana María', 'Gómez Ruiz'], ArmarFactura::partirNombre('Ana María Gómez Ruiz'));
        $this->assertSame(['Pedro', 'Arias Bastidas'], ArmarFactura::partirNombre('Pedro Arias Bastidas'));
        $this->assertSame(['Pedro', 'Arias'], ArmarFactura::partirNombre('  Pedro   Arias '));
    }

    // ── Facturar ────────────────────────────────────────────────────────────

    public function test_facturar_crea_el_cliente_y_la_factura_en_alegra_y_no_se_puede_dos_veces(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();

        $this->facturar($id)->assertOk()
            ->assertJsonPath('factura.estado', 'emitida')
            ->assertJsonPath('factura.numero', 'FE12')
            ->assertJsonPath('factura.cufe', 'cufe-abc')
            ->assertJsonPath('factura.pdf_url', 'https://alegra.test/fe12.pdf');

        $envio = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/invoices'));
        $this->assertSame(['generateStamp' => true], $envio['stamp']);
        $this->assertSame(['id' => '77'], $envio['client']);
        $this->assertSame(['id' => '5'], $envio['numberTemplate']);
        $this->assertSame('CASH', $envio['paymentForm']);
        $this->assertStringContainsString("[DECASA-ORDEN-{$id}]", $envio['observations']);
        $this->assertCount(2, $envio['payments']);
        $this->assertSame(['id' => '2'], $envio['payments'][0]['account'], 'La transferencia entra al banco escogido.');
        $this->assertSame('transfer', $envio['payments'][0]['paymentMethod']);

        // El cliente de Alegra se recuerda y la marca vieja de "facturado" queda puesta.
        $this->assertSame('77', DB::table('clientes')->value('alegra_contacto_id'));
        $this->assertSame(0, DB::table('pagos')->where('orden_id', $id)->whereNull('facturacion_hecha_at')->count());

        // Sale de la lista y un segundo clic no crea otra.
        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->assertJsonCount(0, 'data');
        $this->facturar($id)->assertStatus(409);
        $this->assertSame(1, $this->postsAFacturas());
    }

    public function test_sin_marcar_que_reviso_no_se_factura(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();

        $this->facturar($id, ['revisado' => false])->assertStatus(422);
        $this->assertSame(0, $this->postsAFacturas());
    }

    public function test_se_les_fue_un_cero_hay_que_aceptar_la_advertencia(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        // El sofá de $2.380.000 quedó en $23.800.000.
        $id = $this->ordenEntregada(['valor_total' => 24990000, 'descuento_total' => 0],
            pagos: [['tipo' => 'anticipo', 'monto' => 24990000, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00']],
            items: [
                ['producto_id' => DB::table('productos')->insertGetId(['nombre' => 'Sofá Bali', 'precio_base' => 2380000]), 'cantidad' => 1, 'precio_unitario' => 23800000],
                ['producto_id' => DB::table('productos')->insertGetId(['nombre' => 'Mesa Roma', 'precio_base' => 595000]), 'cantidad' => 2, 'precio_unitario' => 595000],
            ]);

        $r = $this->facturar($id)->assertStatus(422);
        $this->assertContains('precio_raro', $r->json('sin_aceptar'));
        $this->assertSame(0, $this->postsAFacturas());

        $this->facturar($id, ['aceptadas' => ['precio_raro']])->assertOk();
        $this->assertSame(['precio_raro'], FacturaElectronica::first()->advertencias_aceptadas);
    }

    public function test_una_solicitud_de_cambio_sin_decidir_frena_la_factura(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();
        DB::table('solicitudes_cambio')->insert(['orden_id' => $id, 'estado' => 'pendiente']);

        $this->facturar($id)->assertStatus(422)->assertJsonPath('alertas.0.codigo', fn ($c) => is_string($c));
        $this->assertSame(0, $this->postsAFacturas());
    }

    public function test_si_alegra_no_responde_no_se_vuelve_a_crear_a_ciegas(): void
    {
        $this->prender();
        $id = $this->ordenEntregada();
        $creadas = 0;
        Http::fake([
            'api.alegra.com/api/v1/contacts?*' => Http::response([['id' => '77', 'identificationObject' => ['number' => '1094888777']]], 200),
            'api.alegra.com/api/v1/invoices?*' => Http::response([
                ['id' => '900', 'status' => 'open', 'observations' => "[DECASA-ORDEN-{$id}] · #4500"],
            ], 200),
            'api.alegra.com/api/v1/invoices/900*' => Http::response([
                'id' => '900', 'status' => 'open', 'numberTemplate' => ['fullNumber' => 'FE13'],
                'stamp' => ['legalStatus' => 'STAMPED_AND_ACCEPTED', 'cufe' => 'cufe-900'],
            ], 200),
            // La primera vez se cae la conexión (la factura SÍ quedó creada en Alegra).
            'api.alegra.com/api/v1/invoices' => function () use (&$creadas) {
                $creadas++;
                throw new ConnectionException('timeout');
            },
        ]);

        $this->facturar($id)->assertStatus(422)->assertJsonPath('factura.estado', 'incierta');

        // El segundo intento primero la busca por la marca, la encuentra y la adopta.
        $this->facturar($id)->assertOk()->assertJsonPath('factura.numero', 'FE13')->assertJsonPath('factura.estado', 'emitida');
        $this->assertSame(1, $creadas, 'No se mandó otra factura.');
        $this->assertSame('77', DB::table('clientes')->value('alegra_contacto_id'), 'Usó el contacto que ya existía.');
    }

    public function test_rechazo_de_la_dian_deja_borrador_y_el_reintento_lo_rehace(): void
    {
        $this->prender();
        $id = $this->ordenEntregada();
        $intentos = 0;
        Http::fake([
            'api.alegra.com/api/v1/contacts?*' => Http::response([], 200),
            'api.alegra.com/api/v1/contacts'   => Http::response(['id' => '77'], 201),
            'api.alegra.com/api/v1/invoices/600*' => fn (PeticionHttp $r) => $r->method() === 'DELETE'
                ? Http::response(['message' => 'ok'], 200)
                : Http::response(['id' => '600', 'status' => 'draft'], 200),
            'api.alegra.com/api/v1/invoices/601*' => Http::response(['id' => '601', 'status' => 'open'], 200),
            'api.alegra.com/api/v1/invoices' => function () use (&$intentos) {
                $intentos++;

                return $intentos === 1
                    ? Http::response(['code' => 3001, 'message' => 'La resolución está vencida', 'invoice' => ['id' => '600', 'status' => 'draft']], 400)
                    : Http::response(['id' => '601', 'status' => 'open', 'numberTemplate' => ['fullNumber' => 'FE14'],
                        'stamp' => ['legalStatus' => 'STAMPED_AND_ACCEPTED', 'cufe' => 'c601']], 201);
            },
        ]);

        $this->facturar($id)->assertStatus(422)->assertJsonPath('factura.estado', 'rechazada')
            ->assertJsonPath('factura.error', fn ($e) => str_contains($e, 'resolución'));

        $this->facturar($id)->assertOk()->assertJsonPath('factura.numero', 'FE14');
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'invoices/600')),
            'El borrador rechazado se borró antes de crear la buena.');
    }

    public function test_en_modo_borrador_no_va_a_la_dian(): void
    {
        $this->prender(['modo' => 'borrador']);
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();

        $this->facturar($id)->assertOk()->assertJsonPath('factura.estado', 'borrador_alegra');
        $envio = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/invoices'));
        $this->assertArrayNotHasKey('stamp', $envio->data());
        $this->assertArrayNotHasKey('payments', $envio->data());
        $this->assertSame('draft', $envio['status']);
        $this->assertSame(1, DB::table('pagos')->whereNull('facturacion_hecha_at')->count() > 0 ? 1 : 0,
            'Un borrador no marca los pagos como facturados.');
    }

    public function test_si_la_dian_esta_validando_queda_pendiente_y_luego_se_actualiza(): void
    {
        $this->prender();
        $this->alegraQueAcepta('STAMPED_AND_WAITING_RESPONSE');
        $id = $this->ordenEntregada();

        $f = $this->facturar($id)->assertOk()->assertJsonPath('factura.estado', 'pendiente_dian')->json('factura');

        Http::fake(['api.alegra.com/api/v1/invoices/500*' => Http::response([
            'id' => '500', 'status' => 'open', 'stamp' => ['legalStatus' => 'STAMPED_AND_ACCEPTED', 'cufe' => 'cufe-abc'],
        ], 200)]);
        $this->actingAs($this->como($this->facturador))->postJson("/api/facturacion-electronica/facturas/{$f['id']}/actualizar")
            ->assertOk()->assertJsonPath('factura.estado', 'emitida');
    }

    public function test_por_cada_pago_sale_una_factura_por_lo_que_pago(): void
    {
        $this->prender(['momento' => 'por_pago']);
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada(['estado' => 'en_produccion']);

        $lista = $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->json('data');
        $this->assertCount(2, $lista, 'Un renglón por pago, aunque la orden no se haya entregado.');

        $pagoId = $lista[0]['pago_id'];
        $det = $this->actingAs($this->como($this->facturador))->getJson("/api/facturacion-electronica/ordenes/{$id}?pago_id={$pagoId}")->json('armado');
        $this->assertCount(1, $det['lineas']);
        $this->assertEqualsWithDelta(1725500, $det['totales']['total'], 1);
        $this->assertStringContainsString('Anticipo', $det['lineas'][0]['nombre']);

        $this->facturar($id, ['pago_id' => $pagoId])->assertOk();
        $this->assertSame("pago:{$pagoId}", FacturaElectronica::first()->clave);
        $this->assertCount(1, $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->json('data'));
    }

    public function test_si_cambia_la_plata_despues_de_facturar_sale_en_con_diferencias(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();
        $this->facturar($id)->assertOk();

        // Se les fue un cero y lo corrigieron después de facturar.
        DB::table('ordenes')->where('id', $id)->update(['valor_total' => 3000000]);

        $dif = $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/facturas?grupo=diferencias')
            ->assertOk()->json('data');
        $this->assertCount(1, $dif);
        $this->assertEquals(-451000, $dif[0]['diferencia']);

        $this->actingAs($this->como($this->facturador))
            ->postJson("/api/facturacion-electronica/facturas/{$dif[0]['id']}/diferencia-resuelta", ['nota' => 'Nota crédito NC-3 en Alegra'])
            ->assertOk();
        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/facturas?grupo=diferencias')
            ->assertJsonCount(0, 'data');
    }

    public function test_lo_facturado_a_mano_se_anota_y_sale_de_la_lista(): void
    {
        $this->prender();
        $id = $this->ordenEntregada();

        $this->actingAs($this->como($this->facturador))
            ->postJson("/api/facturacion-electronica/ordenes/{$id}/externa", ['numero' => 'FE-900', 'nota' => 'La hice en Alegra'])
            ->assertOk();
        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->assertJsonCount(0, 'data');
    }

    public function test_sin_revision_la_tarea_factura_sola_lo_que_no_tiene_advertencias(): void
    {
        $this->prender(['requiere_revision' => false]);
        $this->alegraQueAcepta();
        $limpia = $this->ordenEntregada();
        $conSaldo = $this->ordenEntregada(pagos: [['tipo' => 'anticipo', 'monto' => 100000, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00']]);

        Artisan::call('facturacion:emitir-pendientes');

        $this->assertSame('emitida', FacturaElectronica::where('orden_id', $limpia)->value('estado'));
        $this->assertNull(FacturaElectronica::where('orden_id', $conSaldo)->where('estado', 'emitida')->first(),
            'La que tiene saldo (advertencia) la revisa una persona.');
    }

    // ── Cómo entra a Alegra ─────────────────────────────────────────────────

    public function test_con_el_jwt_nuevo_entra_como_bearer_y_no_necesita_correo(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJzb2RlZ2UifQ.ZmlybWE';
        config(['services.alegra.email' => null, 'services.alegra.token' => "  {$jwt}\n"]);
        Http::fake(['api.alegra.com/*' => Http::response([], 200)]);

        $this->assertTrue(\App\Services\Alegra\ClienteAlegra::configurado());
        (new \App\Services\Alegra\ClienteAlegra())->get('number-templates');

        Http::assertSent(fn ($r) => $r->header('Authorization') === ["Bearer {$jwt}"]);
    }

    public function test_con_el_token_clasico_entra_con_correo_y_token(): void
    {
        config(['services.alegra.email' => 'api@decasa.test ', 'services.alegra.token' => 'token-clasico']);
        Http::fake(['api.alegra.com/*' => Http::response([], 200)]);

        (new \App\Services\Alegra\ClienteAlegra())->get('number-templates');

        Http::assertSent(fn ($r) => $r->header('Authorization') === ['Basic ' . base64_encode('api@decasa.test:token-clasico')]);

        // El clásico sin correo no sirve: se dice que falta, no se intenta.
        config(['services.alegra.email' => null]);
        $this->assertFalse(\App\Services\Alegra\ClienteAlegra::configurado());
    }

    public function test_con_revision_prendida_la_tarea_no_hace_nada(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $this->ordenEntregada();

        Artisan::call('facturacion:emitir-pendientes');

        $this->assertSame(0, FacturaElectronica::count());
        Http::assertNothingSent();
    }
}
