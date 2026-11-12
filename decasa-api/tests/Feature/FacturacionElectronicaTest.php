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
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable();
            $t->boolean('activa')->default(true); $t->date('cerrada_en')->nullable();
        });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('cedula')->nullable(); $t->string('telefono')->nullable();
            $t->string('email')->nullable(); $t->string('direccion')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('precio_base', 14, 2)->default(0); $t->decimal('precio_pieza', 14, 2)->nullable(); $t->string('medidas')->nullable();
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
            $t->timestamp('devuelto_en')->nullable(); $t->json('specs_personalizacion')->nullable(); $t->json('ficha_especial')->nullable(); $t->timestamps();
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
        (require base_path('database/migrations/2026_11_12_000001_pagos_alegra_en_facturas_electronicas.php'))->up();
        (require base_path('database/migrations/2026_11_13_000001_retencion_del_cliente_en_facturas_electronicas.php'))->up();
        Schema::create('garantias', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('despacho_item_id')->nullable();
        });

        DB::table('tiendas')->insert(['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'ciudad' => 'Armenia']);
        $this->supervisor = DB::table('usuarios')->insertGetId(['nombre' => 'Sup', 'rol' => 'supervisor']);
        $this->facturador = DB::table('usuarios')->insertGetId(['nombre' => 'Fac', 'rol' => 'vendedor', 'facturacion' => true, 'tienda_default_id' => self::NORTE]);
        $this->vendedor   = DB::table('usuarios')->insertGetId(['nombre' => 'Ven', 'rol' => 'vendedor', 'tienda_default_id' => self::NORTE]);

        config([
            'services.alegra.email' => 'api@decasa.test',
            'services.alegra.token' => 'token-de-prueba',
            'services.alegra.url'   => 'https://api.alegra.com/api/v1',
        ]);

        // Nada sale a Alegra de verdad. La numeración "5" está vigente salvo que
        // la prueba diga otra cosa (cada revisión pregunta por ella).
        Http::preventStrayRequests();
        Http::fake([
            'api.alegra.com/api/v1/number-templates/*' => fn () => Http::response($this->numeracion, 200),
            'api.alegra.com/api/v1/taxes'              => fn () => Http::response($this->impuestos, 200),
            'api.alegra.com/api/v1/bank-accounts'      => fn () => Http::response($this->cuentas, 200),
            'api.alegra.com/api/v1/items/*'            => fn () => Http::response($this->item, 200),
            'api.alegra.com/api/v1/contacts/*'         => fn (PeticionHttp $r) => Http::response(['id' => '77'], 200),
            'api.alegra.com/api/v1/payments'           => fn (PeticionHttp $r) => $this->respuestaPago
                ? ($this->respuestaPago)($r)
                : Http::response(['id' => 'P1'], 201),
        ]);
    }

    private array $impuestos = [['id' => '3', 'name' => 'IVA', 'percentage' => 19, 'status' => 'active'],
        ['id' => '4', 'name' => 'IVA 5%', 'percentage' => 5, 'status' => 'active']];
    private array $cuentas = [['id' => '1', 'name' => 'Caja', 'status' => 'active'], ['id' => '2', 'name' => 'Bancolombia', 'status' => 'active'],
        ['id' => '3', 'name' => 'Datáfono', 'status' => 'active'], ['id' => '9', 'name' => 'Cuenta vieja', 'status' => 'inactive']];
    private array $item = ['id' => '10', 'name' => 'Venta de muebles', 'status' => 'active', 'type' => 'service'];
    /** Si una prueba quiere que Alegra conteste otra cosa a POST /payments. */
    private ?\Closure $respuestaPago = null;

    /** Los POST /payments que llegaron a Alegra. */
    private function pagosMandados(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/payments'))
            ->map(fn ($r) => $r->data())->values()->all();
    }

    /** Lo que Alegra contesta de la numeración "5" (FEN, como la de Decasa). */
    private array $numeracion = [
        'id' => '5', 'name' => 'FEN', 'prefix' => 'FEN', 'status' => 'active', 'isElectronic' => true,
        'nextInvoiceNumber' => 1500, 'maxInvoiceNumber' => 2000, 'endDate' => '2099-12-31',
    ];

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
            'telefono' => '3001234567', 'direccion' => 'Cra 14 # 20-30', 'ciudad' => 'Armenia', 'departamento' => 'Quindío', 'regimen' => 'no_responsable',
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
            // Lo normal en Decasa: cada pago ya lo digitó el facturador en Alegra como
            // recibo (anticipo) al entrar. Una prueba puede mandar facturacion_hecha_at => null.
            DB::table('pagos')->insert($p + ['orden_id' => $id, 'tienda_id' => self::NORTE, 'facturacion_hecha_at' => $p['created_at'] ?? now()]);
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
        $this->prender(['facturar_restauraciones' => true]);
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
        $this->prender(['registrar_pagos' => true]);
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();

        $this->facturar($id)->assertOk()
            ->assertJsonPath('factura.pagos_sin_registrar', [])
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
        // Los pagos NO van dentro de la factura: se registran después, contra ella.
        $this->assertArrayNotHasKey('payments', $envio->data());
        $pagos = $this->pagosMandados();
        $this->assertCount(2, $pagos);
        $this->assertSame(['id' => '2'], $pagos[0]['bankAccount'], 'La transferencia entra al banco escogido.');
        $this->assertSame('transfer', $pagos[0]['paymentMethod']);
        $this->assertEquals([['id' => '500', 'amount' => 1725500]], $pagos[0]['invoices']);
        $this->assertSame(['id' => '77'], $pagos[0]['client']);
        $this->assertSame(now('America/Bogota')->toDateString(), $pagos[0]['date'], 'De fábrica, con la fecha de la factura.');
        $this->assertStringContainsString('Recibido el 02/11/2026', $pagos[0]['observations']);

        // El cliente de Alegra se recuerda.
        $this->assertSame('77', DB::table('clientes')->value('alegra_contacto_id'));

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
    }

    // ── Cómo lleva Decasa los pagos (contador, 2026-11-11) ───────────────────

    public function test_facturar_no_toca_la_marca_de_recibo_digitado(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        // El segundo pago todavía no lo ha digitado el facturador en Alegra.
        $id = $this->ordenEntregada(pagos: [
            ['tipo' => 'anticipo', 'monto' => 1725500, 'metodo' => 'transferencia', 'created_at' => '2026-11-02 15:00:00'],
            ['tipo' => 'saldo_final', 'monto' => 1725500, 'metodo' => 'efectivo', 'created_at' => '2026-11-05 15:00:00', 'facturacion_hecha_at' => null],
        ]);

        $r = $this->facturar($id)->assertStatus(422);
        $this->assertContains('recibo_sin_registrar', $r->json('sin_aceptar'), 'Se avisa que falta digitar ese recibo.');

        $this->facturar($id, ['aceptadas' => ['recibo_sin_registrar']])->assertOk();
        // Antes se marcaban todos como hechos y ese recibo desaparecía de la lista del facturador.
        $this->assertSame(1, DB::table('pagos')->where('orden_id', $id)->whereNull('facturacion_hecha_at')->count());
    }

    public function test_la_retencion_del_cliente_empresa_no_es_deuda(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        // Compró $3.451.000, le retuvo $86.275 (2,5 % de la base) y pagó el resto.
        $id = $this->ordenEntregada(pagos: [
            ['tipo' => 'anticipo', 'monto' => 3364725, 'metodo' => 'transferencia', 'created_at' => '2026-11-02 15:00:00'],
        ]);

        $sin = $this->actingAs($this->como($this->facturador))->getJson("/api/facturacion-electronica/ordenes/{$id}")->json('armado');
        $this->assertSame('CREDIT', $sin['forma_pago']);

        $con = $this->actingAs($this->como($this->facturador))->getJson("/api/facturacion-electronica/ordenes/{$id}?retencion=86275")->json('armado');
        $this->assertSame('CASH', $con['forma_pago'], 'Con la retención anotada es de contado.');
        $this->assertNotContains('saldo', $con['advertencias']);
        $this->assertEquals(86275, $con['por_cruzar']['retencion']);

        $f = $this->facturar($id, ['retencion' => 86275])->assertOk()->json('factura');
        $this->assertEquals(86275, $f['retencion_cliente']);
        $envio = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/invoices'));
        $this->assertSame('CASH', $envio['paymentForm']);
    }

    public function test_una_retencion_mayor_que_el_saldo_no_se_deja(): void
    {
        $this->prender();
        $id = $this->ordenEntregada(pagos: [
            ['tipo' => 'anticipo', 'monto' => 3400000, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00'],
        ]);
        $codigos = array_column($this->actingAs($this->como($this->facturador))
            ->getJson("/api/facturacion-electronica/ordenes/{$id}?retencion=200000")->json('armado.alertas'), 'codigo');
        $this->assertContains('retencion_mayor', $codigos);
    }

    public function test_por_pago_sigue_mostrando_los_pagos_con_recibo_digitado(): void
    {
        $this->prender(['momento' => 'por_pago']);
        $id = $this->ordenEntregada(['estado' => 'en_produccion']);
        // Los dos pagos ya están digitados como recibo: eso no es tener factura.
        $this->assertCount(2, $this->actingAs($this->como($this->facturador))
            ->getJson('/api/facturacion-electronica/pendientes')->json('data'));
    }

    // ── Respuestas del contador (2026-11-12) ────────────────────────────────

    public function test_la_linea_dice_nombre_version_medidas_y_tela(): void
    {
        $this->prender();
        $cama = DB::table('productos')->insertGetId(['nombre' => 'Cama Bali', 'precio_base' => 2380000, 'medidas' => '140 x 190 cm']);
        $id = $this->ordenEntregada(['valor_total' => 3570000, 'descuento_total' => 0], items: [
            ['producto_id' => $cama, 'cantidad' => 1, 'precio_unitario' => 2380000, 'variante_detalle' => '1.40',
                'specs_personalizacion' => json_encode(['tela' => 'Lino gris'])],
            ['nombre_custom' => 'Sofá en L especial', 'cantidad' => 1, 'precio_unitario' => 1190000, 'es_personalizado' => true,
                'ficha_especial' => json_encode(['tipo_mueble' => 'Sofá', 'ancho' => '280 cm', 'fondo' => '160 cm', 'alto' => '90 cm'])],
        ], pagos: [['tipo' => 'anticipo', 'monto' => 3570000, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00']]);

        $lineas = ArmarFactura::de(\App\Models\Orden::find($id))['factura']['items'];
        $this->assertSame('Cama Bali · 1.40 · Medidas: 140 x 190 cm · Tela: Lino gris', $lineas[0]['description']);
        $this->assertSame('Sofá en L especial · Medidas: 280 cm x 160 cm x 90 cm', $lineas[1]['description']);
        $this->assertEquals(1, $lineas[0]['quantity'], 'Las unidades van aparte.');
    }

    public function test_la_variante_de_inventario_sale_como_en_la_orden(): void
    {
        $this->prender();
        // Una venta vieja sin variante_detalle: la tela/color/medida sale de Inventario.
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->string('marca')->nullable(); $t->string('marca_tela')->nullable();
            $t->string('nombre_color')->nullable(); $t->string('medida')->nullable(); $t->timestamps();
        });
        $sofa = DB::table('productos')->insertGetId(['nombre' => 'Sofá Bali', 'precio_base' => 2380000]);
        $var = DB::table('producto_variantes')->insertGetId(['producto_id' => $sofa, 'marca_tela' => 'Lino', 'nombre_color' => 'Gris perla']);
        $id = $this->ordenEntregada(['valor_total' => 2380000, 'descuento_total' => 0],
            items: [['producto_id' => $sofa, 'variante_id' => $var, 'cantidad' => 1, 'precio_unitario' => 2380000]],
            pagos: [['tipo' => 'anticipo', 'monto' => 2380000, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00']]);

        $orden = \App\Models\Orden::find($id);
        $linea = ArmarFactura::de($orden)['factura']['items'][0];
        $this->assertSame('Sofá Bali · ' . $orden->items->first()->variante_texto, $linea['description'], 'El mismo texto de la orden.');
        $this->assertStringContainsString('Gris perla', $linea['description']);
    }

    public function test_en_una_orden_mixta_la_restauracion_no_va_en_la_factura(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        // Sofá $2.380.000 + restauración de un comedor $1.190.000, todo pagado.
        $sofa = DB::table('productos')->insertGetId(['nombre' => 'Sofá Bali', 'precio_base' => 2380000]);
        $id = $this->ordenEntregada(['valor_total' => 3570000, 'descuento_total' => 0], items: [
            ['producto_id' => $sofa, 'cantidad' => 1, 'precio_unitario' => 2380000],
            ['nombre_custom' => 'Restauración comedor', 'cantidad' => 1, 'precio_unitario' => 1190000, 'es_restauracion' => true],
        ], pagos: [['tipo' => 'anticipo', 'monto' => 3570000, 'metodo' => 'efectivo', 'created_at' => '2026-11-02 15:00:00']]);

        $armado = ArmarFactura::de(\App\Models\Orden::find($id));
        $this->assertCount(1, $armado['lineas'], 'Solo el sofá.');
        $this->assertEqualsWithDelta(2380000, $armado['totales']['total'], 1);
        $this->assertSame('CASH', $armado['forma_pago'], 'Pagó toda la orden: contado.');
        $this->assertContains('restauracion_fuera', array_column($armado['alertas'], 'codigo'));
        $this->assertNotContains('redondeo', $armado['advertencias'], 'La parte facturada cuadra.');

        $this->facturar($id)->assertOk();
        // "Diferencias" compara contra la orden entera: sin cambios, no sale nada.
        $this->assertCount(0, $this->actingAs($this->como($this->facturador))
            ->getJson('/api/facturacion-electronica/facturas?grupo=diferencias')->json('data'));
    }

    public function test_sin_regimen_correo_o_nombre_completo_no_se_factura(): void
    {
        $this->prender();
        $id = $this->ordenEntregada(cliente: ['regimen' => null, 'email' => null, 'nombre' => 'Carlos']);

        $faltan = $this->actingAs($this->como($this->facturador))
            ->getJson("/api/facturacion-electronica/ordenes/{$id}")->json('armado.cliente.faltan');
        $this->assertContains('régimen de IVA', $faltan, 'Una persona natural puede ser responsable de IVA: no se supone.');
        $this->assertContains('correo', $faltan);
        $this->assertContains('nombre completo (nombres y apellidos)', $faltan);
        $this->facturar($id)->assertStatus(422);
    }

    public function test_de_fabrica_un_dia_de_plazo_y_la_factura_al_correo(): void
    {
        $r = ReglasFacturacion::todo();
        $this->assertSame(1, $r['dias_credito']);
        $this->assertTrue($r['enviar_correo']);
        $this->assertTrue($r['exigir_correo']);
        $this->assertFalse($r['facturar_restauraciones']);
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

    // ── Revisión del 2026-11-11 ─────────────────────────────────────────────

    public function test_cambiar_de_por_pago_a_al_entregar_no_factura_dos_veces(): void
    {
        $this->prender(['momento' => 'por_pago']);
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();
        $pago = DB::table('pagos')->where('orden_id', $id)->orderBy('id')->value('id');
        $this->facturar($id, ['pago_id' => $pago])->assertOk();

        // Cambian la regla: la orden ya tiene una factura por pago.
        $this->prender(['momento' => 'al_entregar']);
        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->assertJsonCount(0, 'data');
        $this->facturar($id)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'facturas por cada pago'));
        $this->assertSame(1, $this->postsAFacturas());
    }

    public function test_un_pago_de_una_orden_facturada_completa_no_se_factura_aparte(): void
    {
        $this->prender();
        $id = $this->ordenEntregada();
        $this->actingAs($this->como($this->facturador))
            ->postJson("/api/facturacion-electronica/ordenes/{$id}/externa", ['numero' => 'FEN1900'])->assertOk();

        $this->prender(['momento' => 'por_pago']);
        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->assertJsonCount(0, 'data');
    }

    public function test_una_entrega_por_garantia_no_revive_una_orden_vieja(): void
    {
        $this->prender();
        // Entregada en octubre (antes de "desde", facturada a mano)...
        $id = $this->ordenEntregada();
        DB::table('despacho_items')->where('orden_id', $id)->update(['entregado_at' => '2026-10-10 15:00:00']);
        // ...y el 6 de noviembre se le entregó el mueble arreglado por garantía.
        $garantia = DB::table('despacho_items')->insertGetId(['orden_id' => $id, 'estado' => 'entregado', 'entregado_at' => '2026-11-06 15:00:00']);
        DB::table('garantias')->insert(['orden_id' => $id, 'despacho_item_id' => $garantia]);

        $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')->assertJsonCount(0, 'data');
        $this->facturar($id)->assertStatus(422);
    }

    public function test_la_lista_trae_solo_lo_pendiente_lo_mas_viejo_primero(): void
    {
        $this->prender();
        $vieja = $this->ordenEntregada(['updated_at' => '2026-11-03 15:00:00']);
        $nueva = $this->ordenEntregada(['updated_at' => '2026-11-08 15:00:00']);
        $hecha = $this->ordenEntregada();
        $this->actingAs($this->como($this->facturador))
            ->postJson("/api/facturacion-electronica/ordenes/{$hecha}/externa", ['numero' => 'FEN1901'])->assertOk();

        $ids = collect($this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/pendientes')
            ->json('data'))->pluck('orden_id')->all();
        $this->assertSame([$vieja, $nueva], $ids);
    }

    public function test_una_orden_cancelada_despues_de_facturar_sale_en_diferencias(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();
        $this->facturar($id)->assertOk();

        DB::table('ordenes')->where('id', $id)->update(['estado' => 'cancelado']);

        $dif = $this->actingAs($this->como($this->facturador))->getJson('/api/facturacion-electronica/facturas?grupo=diferencias')->json('data');
        $this->assertCount(1, $dif);
        $this->assertTrue($dif[0]['orden_cancelada']);
        $this->assertEquals(-3451000, $dif[0]['diferencia']);
    }

    public function test_lo_que_esta_inactivo_en_alegra_o_el_iva_equivocado_frena(): void
    {
        $this->prender(['registrar_pagos' => true]);
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();
        $codigos = fn () => array_column($this->actingAs($this->como($this->facturador))
            ->getJson("/api/facturacion-electronica/ordenes/{$id}")->json('armado.alertas'), 'codigo');

        // Escogieron "IVA 5%": el sistema calcula con 19 % y Alegra con 5 %.
        $this->prender(['impuesto_iva_id' => '4']);
        $this->assertContains('iva_distinto', $codigos());

        // Desactivaron en Alegra el banco de las transferencias.
        \Illuminate\Support\Facades\Cache::flush();
        $this->prender(['impuesto_iva_id' => '3', 'cuentas' => ['*' => ['efectivo' => '1', 'transferencia' => '9']]]);
        $this->assertContains('cuenta_no_sirve', $codigos());

        // Y el producto genérico.
        \Illuminate\Support\Facades\Cache::flush();
        $this->prender(['cuentas' => ['*' => ['efectivo' => '1', 'transferencia' => '2']]]);
        $this->item['status'] = 'inactive';
        $this->assertContains('item_no_sirve', $codigos());
        $this->facturar($id)->assertStatus(422);
        $this->assertSame(0, $this->postsAFacturas());
    }

    public function test_en_ajustes_no_salen_bancos_ni_impuestos_inactivos(): void
    {
        Http::fake(['api.alegra.com/api/v1/number-templates?*' => Http::response([$this->numeracion], 200)]);
        $this->impuestos[] = ['id' => '8', 'name' => 'IVA viejo', 'percentage' => 16, 'status' => 'inactive'];

        $cat = $this->actingAs($this->como($this->supervisor))->getJson('/api/facturacion-electronica/alegra/catalogos')->assertOk();

        $this->assertSame(['1', '2', '3'], array_column($cat->json('cuentas'), 'id'), 'La "Cuenta vieja" inactiva no sale.');
        $this->assertSame(['3', '4'], array_column($cat->json('impuestos'), 'id'));
        $this->assertSame('vigente', $cat->json('numeraciones.0.estado'));
    }

    public function test_los_pagos_nunca_pasan_el_total_que_dice_alegra(): void
    {
        $this->prender(['registrar_pagos' => true]);
        $id = $this->ordenEntregada();
        Http::fake([
            'api.alegra.com/api/v1/contacts?*' => Http::response([], 200),
            'api.alegra.com/api/v1/contacts'   => Http::response(['id' => '77'], 201),
            // Por los decimales, Alegra dejó la factura en $3.450.999,99.
            'api.alegra.com/api/v1/invoices'   => Http::response(['id' => '500', 'status' => 'open', 'total' => 3450999.99,
                'numberTemplate' => ['fullNumber' => 'FEN1918'], 'stamp' => ['legalStatus' => 'STAMPED_AND_ACCEPTED', 'cufe' => 'c']], 201),
            'api.alegra.com/api/v1/invoices/500*' => Http::response(['id' => '500', 'status' => 'open'], 200),
        ]);

        $this->facturar($id)->assertOk();

        $montos = array_map(fn ($p) => $p['invoices'][0]['amount'], $this->pagosMandados());
        $this->assertEquals([1725500, 1725499.99], $montos, 'El último pago se recorta al total de Alegra.');
    }

    public function test_si_un_pago_no_entra_a_alegra_queda_para_reintentar(): void
    {
        $this->prender(['registrar_pagos' => true]);
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada();
        $intentos = 0;
        $this->respuestaPago = function () use (&$intentos) {
            $intentos++;

            return $intentos <= 2 ? Http::response(['message' => 'Período cerrado'], 400) : Http::response(['id' => 'P9'], 201);
        };

        $f = $this->facturar($id)->assertOk()->json('factura');
        // La factura salió (es legal); los pagos quedaron para reintentar.
        $this->assertSame('emitida', $f['estado']);
        $this->assertCount(2, $f['pagos_sin_registrar']);
        $this->assertCount(1, $this->actingAs($this->como($this->facturador))
            ->getJson('/api/facturacion-electronica/facturas?grupo=problemas')->json('data'));

        $this->actingAs($this->como($this->facturador))->postJson("/api/facturacion-electronica/facturas/{$f['id']}/registrar-pagos")
            ->assertOk()->assertJsonPath('factura.pagos_sin_registrar', []);
    }

    public function test_si_el_cliente_ya_existe_en_alegra_se_le_ponen_los_datos_de_hoy(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada(cliente: ['alegra_contacto_id' => '77', 'email' => 'nuevo@correo.test']);

        $this->facturar($id)->assertOk();

        $put = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/contacts/77'));
        $this->assertNotNull($put, 'Se actualizó el contacto antes de facturar.');
        $this->assertSame('nuevo@correo.test', $put['email']);
    }

    public function test_municipio_y_departamento_salen_con_el_nombre_de_alegra(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        $id = $this->ordenEntregada(cliente: ['ciudad' => 'armenia', 'departamento' => 'QUINDIO']);
        $this->facturar($id)->assertOk();
        $contacto = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/contacts'));
        $this->assertSame(['address' => 'Cra 14 # 20-30', 'city' => 'Armenia', 'department' => 'Quindío', 'country' => 'Colombia'], $contacto['address']);

        $this->assertSame('Cartagena de Indias', \App\Support\MunicipiosAlegra::municipio('Bolivar', 'Cartagena'));
        $this->assertSame('Guadalajara de Buga', \App\Support\MunicipiosAlegra::municipio('Valle del Cauca', 'Buga'));
        $this->assertSame('N. de Santander', \App\Support\MunicipiosAlegra::departamento('Norte de Santander'));
        $this->assertSame('Bogotá, D.C.', \App\Support\MunicipiosAlegra::municipio('Bogotá D.C.', 'Bogotá'));

        // Algo que no está en la lista frena (Alegra lo rechazaría).
        $otra = $this->ordenEntregada(cliente: ['ciudad' => 'Armenia Q.', 'departamento' => 'Quindío', 'cedula' => '555']);
        $codigos = array_column($this->actingAs($this->como($this->facturador))
            ->getJson("/api/facturacion-electronica/ordenes/{$otra}")->json('armado.alertas'), 'codigo');
        $this->assertContains('municipio_no_oficial', $codigos);
    }

    public function test_la_tarea_pone_al_dia_lo_que_la_dian_estaba_validando(): void
    {
        $this->prender();
        $this->alegraQueAcepta('STAMPED_AND_WAITING_RESPONSE');
        $id = $this->ordenEntregada();
        $this->facturar($id)->assertOk()->assertJsonPath('factura.estado', 'pendiente_dian');

        FacturaElectronica::query()->update(['updated_at' => now()->subMinutes(20)]);
        Http::fake(['api.alegra.com/api/v1/invoices/500*' => Http::response([
            'id' => '500', 'status' => 'open', 'stamp' => ['legalStatus' => 'STAMPED_AND_ACCEPTED', 'cufe' => 'cufe-abc'],
        ], 200)]);
        Artisan::call('facturacion:actualizar-estados');

        $this->assertSame('emitida', FacturaElectronica::first()->estado);
    }

    // ── Numeración (resolución DIAN) ────────────────────────────────────────

    public function test_con_la_numeracion_vencida_no_se_emite(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        // FEU de Decasa: venció el 7 de noviembre de 2026.
        $this->numeracion = ['id' => '5', 'name' => 'FEU', 'prefix' => 'FEU', 'status' => 'active',
            'nextInvoiceNumber' => 106, 'maxInvoiceNumber' => 10000, 'endDate' => now('America/Bogota')->subDays(4)->toDateString()];
        $id = $this->ordenEntregada();

        $r = $this->facturar($id)->assertStatus(422);
        $this->assertStringContainsString('venció', $r->json('message'));
        $this->assertSame(0, $this->postsAFacturas());
    }

    public function test_en_modo_borrador_la_numeracion_vencida_solo_se_avisa(): void
    {
        $this->prender(['modo' => 'borrador']);
        $this->alegraQueAcepta();
        $this->numeracion['endDate'] = now('America/Bogota')->subDay()->toDateString();
        $id = $this->ordenEntregada();

        $this->facturar($id)->assertOk()->assertJsonPath('factura.estado', 'borrador_alegra');
    }

    public function test_con_pocos_numeros_o_por_vencer_se_avisa_sin_frenar(): void
    {
        $this->prender();
        $this->alegraQueAcepta();
        // FEN de Decasa: va en 1918 de 2000 (quedan 83) y el aviso es con 100.
        $this->prender(['aviso_numeracion_numeros' => 100]);
        $this->numeracion['nextInvoiceNumber'] = 1918;
        $id = $this->ordenEntregada();

        $armado = $this->actingAs($this->como($this->facturador))->getJson("/api/facturacion-electronica/ordenes/{$id}")->json('armado');
        $this->assertTrue($armado['se_puede']);
        $this->assertSame(83, $armado['numeracion']['quedan']);
        $this->assertContains('numeracion_pocos', array_column($armado['alertas'], 'codigo'));
        $this->facturar($id)->assertOk();
    }

    public function test_el_estado_sale_tambien_del_texto_de_la_resolucion(): void
    {
        // Si Alegra no mandara endDate/maxInvoiceNumber, se leen del texto (tal cual lo muestra Alegra).
        $e = \App\Services\Alegra\EstadoNumeracion::de([
            'id' => '9', 'name' => 'FP PEREIRA', 'prefix' => 'FEP', 'nextInvoiceNumber' => 261,
            'invoiceText' => 'Autorización de numeración de facturación N° 18764089064697 de 2025-02-18 Modalidad Factura Electrónica Desde N° FEP212 hasta FEP1000 con vigencia hasta 2026-02-18',
        ]);
        $this->assertSame('2026-02-18', $e['vence']);
        $this->assertSame(1000, $e['maximo']);
        $this->assertSame('vencida', $e['estado']);
        $this->assertFalse($e['sirve']);
    }

    public function test_en_ajustes_solo_salen_las_tiendas_abiertas(): void
    {
        DB::table('tiendas')->insert([
            ['id' => 2, 'nombre' => 'Circunvalar', 'ciudad' => 'Pereira', 'activa' => true, 'cerrada_en' => '2026-08-27'],
            ['id' => 3, 'nombre' => 'Vieja', 'ciudad' => 'Armenia', 'activa' => false, 'cerrada_en' => null],
            ['id' => 4, 'nombre' => 'Unicentro', 'ciudad' => 'Pereira', 'activa' => true, 'cerrada_en' => null],
        ]);

        $nombres = collect($this->actingAs($this->como($this->supervisor))->getJson('/api/facturacion-electronica/reglas')
            ->assertOk()->json('tiendas'))->pluck('nombre')->all();

        $this->assertSame(['Decasa Norte', 'Unicentro'], $nombres);
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
