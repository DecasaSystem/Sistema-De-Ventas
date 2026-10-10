<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\Finanzas\Periodo;
use App\Services\Finanzas\Proyeccion;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El módulo de Finanzas (docs/plan-gestion-financiera.md).
 *
 * Hoy es el 20 de octubre de 2026. Las cuentas de cada prueba están hechas a
 * mano en el comentario. El esquema se monta a mano (SQLite), y las tablas de
 * Finanzas y de conceptos de nómina salen de sus migraciones reales.
 */
class FinanzasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->prestarleASqliteLoQueEsDeMysql();
        Carbon::setTestNow(Carbon::parse('2026-10-20 15:00:00', 'America/Bogota'));

        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('cedula')->nullable(); $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->unsignedBigInteger('nomina_sueldo_id')->nullable(); $t->date('nomina_desde')->nullable();
            $t->unsignedBigInteger('nomina_bonificacion_id')->nullable();
            $t->boolean('nomina_auxilio')->default(true); $t->boolean('nomina_seguridad_social')->default(true);
            $t->string('periodicidad')->default('quincenal');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); $t->boolean('es_independientes')->default(false);
        });
        Schema::create('proveedores', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('en_produccion'); $t->string('serie')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->boolean('sin_descontar_iva')->default(false); $t->string('canal')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('metodo')->default('efectivo'); $t->decimal('monto', 15, 2)->default(0); $t->timestamps();
        });
        DB::statement('CREATE VIEW v_saldo_ordenes AS
            SELECT o.id AS orden_id, o.valor_total, COALESCE(SUM(p.monto), 0) AS total_pagado,
                   o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
            FROM ordenes o LEFT JOIN pagos p ON p.orden_id = o.id GROUP BY o.id, o.valor_total');
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id'); $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->timestamps();
        });
        Schema::create('comision_anticipos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vendedor_id'); $t->string('tipo', 12); $t->char('mes', 7);
            $t->decimal('monto', 15, 2); $t->unsignedBigInteger('comision_id')->nullable(); $t->timestamps();
        });
        Schema::create('compras', function (Blueprint $t) {
            $t->id(); $t->string('item'); $t->string('estado')->default('pendiente');
            $t->decimal('precio', 12, 2)->nullable(); $t->date('fecha_compra')->nullable(); $t->timestamps();
        });
        Schema::create('fichas_tecnicas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id')->nullable(); $t->decimal('costo_materiales', 12, 2)->default(0);
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor'); $t->timestamp('updated_at')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        // Nómina.
        Schema::create('nomina_sueldos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('valor', 12, 2); $t->string('unidad')->default('dia');
            $t->decimal('horas_dia', 5, 2)->default(8); $t->decimal('valor_auxilio_mes', 12, 2)->default(0);
            $t->decimal('valor_seguridad_social_mes', 12, 2)->default(0); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_bonificaciones', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('periodo')->nullable(); $t->decimal('tope', 12, 2)->nullable();
            $t->boolean('tope_activo')->default(false); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_bonificacion_metas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('nomina_bonificacion_id'); $t->decimal('desde', 12, 2);
            $t->decimal('hasta', 12, 2)->nullable(); $t->decimal('monto', 12, 2); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('periodicidad');
            $t->date('fecha_inicio'); $t->date('fecha_fin'); $t->string('sueldo_nombre')->nullable();
            foreach (['valor_dia', 'valor_hora', 'horas_dia', 'dias', 'subtotal', 'descuento_faltas', 'valor_auxilio_dia',
                      'descuento_incapacidad', 'auxilio_transporte', 'valor_seguridad_social_dia',
                      'descuento_seguridad_social', 'total_ajustes', 'produccion_total', 'bonificacion', 'total'] as $c) {
                $t->decimal($c, 12, 2)->default(0);
            }
            $t->string('bonificacion_nombre')->nullable(); $t->string('bonificacion_detalle')->nullable();
            $t->text('observaciones')->nullable(); $t->timestamp('pagado_at')->nullable(); $t->timestamps();
        });
        foreach (['nomina_ausencias', 'nomina_ajustes', 'nomina_producciones'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
                $t->date('fecha'); $t->string('tipo')->nullable(); $t->decimal('horas', 5, 2)->default(0);
                $t->string('motivo')->nullable(); $t->string('nombre')->nullable(); $t->decimal('monto', 12, 2)->default(0);
                $t->string('concepto')->nullable(); $t->decimal('valor_unitario', 12, 2)->default(0);
                $t->decimal('cantidad', 12, 2)->default(0); $t->decimal('total', 12, 2)->default(0); $t->timestamps();
            });
        }
        Schema::create('nomina_prestamos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('motivo')->nullable(); $t->decimal('monto', 12, 2);
            $t->integer('cuotas')->default(1); $t->decimal('valor_cuota', 12, 2)->default(0); $t->date('fecha')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_prestamo_cuotas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('prestamo_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
            $t->decimal('monto', 12, 2); $t->date('fecha')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Decasa Norte'], ['id' => 2, 'nombre' => 'Decasa El Edén']]);
        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'tienda_default_id' => null, 'created_at' => now()],
            ['id' => 2, 'nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor', 'tienda_default_id' => 1, 'created_at' => now()],
            ['id' => 3, 'nombre' => 'Otro jefe', 'email' => 'o@d.com', 'password' => 'x', 'rol' => 'supervisor', 'tienda_default_id' => null, 'created_at' => now()],
        ]);

        (require database_path('migrations/2026_10_19_000001_costo_empleador_en_nomina.php'))->up();
        (require database_path('migrations/2026_10_20_000001_crear_finanzas.php'))->up();

        // La migración prende Finanzas a los supervisores de hoy; al "otro jefe" se le quita.
        DB::table('usuarios')->where('id', 3)->update(['acceso_finanzas' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jefa(): Usuario
    {
        return Usuario::find(1);
    }

    private function categoria(string $nombre): int
    {
        return (int) DB::table('categorias_gasto')->where('nombre', $nombre)->value('id');
    }

    /** Una orden de octubre con su abono. Fecha en hora de Bogotá. */
    private function venta(float $valor, float $abono, string $fecha = '2026-10-10 12:00', string $estado = 'en_produccion', string $metodo = 'efectivo', int $tienda = 1): int
    {
        $creada = Carbon::parse($fecha, 'America/Bogota')->setTimezone('UTC');
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => $tienda, 'vendedor_id' => 2, 'estado' => $estado, 'valor_total' => $valor,
            'created_at' => $creada, 'updated_at' => $creada,
        ]);
        DB::table('orden_items')->insert(['orden_id' => $id, 'precio_unitario' => $valor]);
        if ($abono) {
            DB::table('pagos')->insert(['orden_id' => $id, 'tienda_id' => $tienda, 'metodo' => $metodo, 'monto' => $abono,
                'created_at' => $creada, 'updated_at' => $creada]);
        }

        return $id;
    }

    // ── Cuadra con Reportes ──────────────────────────────────────────────────

    public function test_lo_vendido_y_cobrado_cuadra_con_reportes(): void
    {
        $this->venta(11_900_000, 5_000_000);
        $this->venta(2_380_000, 2_380_000, '2026-10-19 20:30');   // 8:30 p. m.: sigue siendo octubre en Bogotá
        $this->venta(5_000_000, 1_000_000, '2026-10-12 10:00', 'cancelado');

        $panel = $this->actingAs($this->jefa())->getJson('/api/stats/panel?periodo=mes')->assertOk()->json();
        $fin = $this->actingAs($this->jefa())->getJson('/api/finanzas/estado-resultados?desde=2026-10&hasta=2026-10')->assertOk()->json('meses.0');

        $this->assertEquals($panel['total_vendido'], $fin['ventas']);
        $this->assertEquals($panel['cobranza_periodo'], $fin['cobrado']);
        $this->assertEquals(14_280_000, $fin['ventas'], 'sin la cancelada');
    }

    // ── Estado de resultados ─────────────────────────────────────────────────

    /**
     * Octubre:
     *   ventas 11.900.000 → IVA 1.900.000 → netas 10.000.000
     *   materiales: ficha de 3.000.000 por un mueble de 11.900.000 → 3.000.000
     *   nómina: un pago congelado con bruto 1.000.000 y 357.726 por detrás
     *   comisión causada de octubre: 200.000
     *   arriendo pagado 2.000.000 (fijo) + compra de taller 300.000
     *   franquicia: 1.000.000 pagado con tarjeta × 5,5 % = 55.000
     *   utilidad = 10.000.000 − 3.000.000 − 1.357.726 − 200.000 − 2.300.000 − 55.000 = 3.087.274
     */
    public function test_el_estado_de_resultados_del_mes_hecho_a_mano(): void
    {
        $orden = $this->venta(11_900_000, 0);
        DB::table('pagos')->insert(['orden_id' => $orden, 'tienda_id' => 1, 'metodo' => 'tarjeta', 'monto' => 1_000_000,
            'created_at' => Carbon::parse('2026-10-11 15:00', 'UTC'), 'updated_at' => now()]);
        DB::table('orden_items')->where('orden_id', $orden)->update(['producto_id' => 77]);
        DB::table('fichas_tecnicas')->insert(['producto_id' => 77, 'costo_materiales' => 3_000_000]);

        DB::table('nomina_pagos')->insert([
            'usuario_id' => 2, 'periodicidad' => 'quincenal', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15',
            'subtotal' => 900_000, 'auxilio_transporte' => 100_000, 'descuento_seguridad_social' => 72_000, 'total' => 928_000,
            'pagado_at' => Carbon::parse('2026-10-16 15:00', 'UTC'),
        ]);
        // Lo que congeló Nómina al pagar (la quincena del mínimo).
        DB::table('nomina_pagos')->update(['costo_empleador' => 357_726, 'costo_empleador_detalle' => json_encode([
            'lineas' => [], 'aportes' => 144_634, 'prestaciones' => 213_092, 'otros' => 0, 'total' => 357_726,
        ])]);

        DB::table('comisiones')->insert(['vendedor_id' => 2, 'tienda_id' => 1, 'orden_id' => $orden, 'mes_venta' => '2026-10',
            'estado' => 'pendiente', 'monto_comision' => 200_000, 'fecha_disponible' => '2026-11-20']);

        $this->actingAs($this->jefa())->postJson('/api/finanzas/gastos', [
            'categoria_gasto_id' => $this->categoria('Arriendo'), 'concepto' => 'Arriendo Norte',
            'monto' => 2_000_000, 'fecha_pago' => '2026-10-05', 'tienda_id' => 1,
        ])->assertCreated();
        DB::table('compras')->insert(['item' => 'Grapas', 'estado' => 'comprado', 'precio' => 300_000, 'fecha_compra' => '2026-10-08']);

        $m = $this->actingAs($this->jefa())->getJson('/api/finanzas/estado-resultados?desde=2026-10&hasta=2026-10')->assertOk()->json('meses.0');

        $this->assertEquals(11_900_000, $m['ventas']);
        $this->assertEquals(1_900_000, $m['iva']);
        $this->assertEquals(10_000_000, $m['ingresos_netos']);
        $this->assertEquals(3_000_000, $m['costo_produccion']['monto']);
        $this->assertEquals(1, $m['costo_produccion']['cobertura']);
        $this->assertEquals(1_357_726, $m['nomina']['total'], 'bruto + lo que pone la empresa, no el neto');
        $this->assertEquals(200_000, $m['comisiones']['total']);
        $this->assertEquals(2_300_000, $m['gastos']['total'], 'arriendo + compra del taller');
        $this->assertEquals(2_000_000, $m['gastos']['fijos']);
        $this->assertEquals(55_000, $m['financieros']['franquicia']);
        $this->assertEquals(3_087_274, $m['utilidad_operativa']);
        $this->assertTrue($m['parcial']);
    }

    public function test_la_nomina_en_caja_es_el_neto_mas_la_planilla(): void
    {
        DB::table('nomina_pagos')->insert([
            'usuario_id' => 2, 'periodicidad' => 'quincenal', 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'subtotal' => 900_000, 'auxilio_transporte' => 100_000, 'descuento_seguridad_social' => 72_000,
            // 50.000 de cuota de préstamo: no es gasto, es plata que devuelve.
            'total' => 878_000, 'pagado_at' => Carbon::parse('2026-10-01 15:00', 'UTC'),
        ]);

        $f = $this->actingAs($this->jefa())->getJson('/api/finanzas/flujo-caja?desde=2026-09&hasta=2026-10')->assertOk()->json('meses');

        $this->assertEquals(0, $f[0]['salidas']['nomina'], 'se pagó en octubre');
        // neto 878.000 + su seguridad social 72.000 + aportes del empleador (pensión 108.000 + ARL 4.698 + caja 36.000)
        $this->assertEquals(878_000 + 72_000 + 108_000 + 4_698 + 36_000, $f[1]['salidas']['nomina']);
    }

    // ── Gastos de plantilla ──────────────────────────────────────────────────

    public function test_el_internet_del_dia_5_sale_por_pagar_y_no_se_paga_dos_veces(): void
    {
        $jefa = $this->jefa();
        $id = $this->actingAs($jefa)->postJson('/api/finanzas/recurrentes', [
            'nombre' => 'Internet Norte', 'categoria_gasto_id' => $this->categoria('Internet y telefonía'),
            'monto' => 120_000, 'frecuencia' => 'mensual', 'dia_pago' => 5, 'desde' => '2026-09-01', 'tienda_id' => 1,
        ])->assertCreated()->json('id');

        $pend = collect($this->actingAs($jefa)->getJson('/api/finanzas/gastos/pendientes?dias=30')->assertOk()->json());
        $this->assertEquals(['2026-09-01', '2026-10-01', '2026-11-01'], $pend->pluck('periodo')->all());
        $this->assertEquals('vencida', $pend[1]['estado']);

        $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'gasto_recurrente_id' => $id, 'periodo' => '2026-10-01', 'monto' => 120_000,
        ])->assertCreated()->assertJsonPath('cubre_desde', '2026-10-01')->assertJsonPath('cubre_hasta', '2026-10-31');

        $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'gasto_recurrente_id' => $id, 'periodo' => '2026-10-01', 'monto' => 120_000,
        ])->assertStatus(422);

        // Septiembre no se cobró: se omite y deja de salir.
        $this->actingAs($jefa)->postJson('/api/finanzas/gastos/omitir', ['gasto_recurrente_id' => $id, 'periodo' => '2026-09-01'])->assertCreated();

        $pend = collect($this->actingAs($jefa)->getJson('/api/finanzas/gastos/pendientes?dias=30')->json());
        $this->assertEquals(['2026-11-01'], $pend->pluck('periodo')->all());

        // Un periodo que no es de la plantilla no se acepta.
        $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'gasto_recurrente_id' => $id, 'periodo' => '2026-10-07', 'monto' => 1,
        ])->assertStatus(422);
    }

    public function test_anular_un_pago_lo_saca_de_las_cuentas_y_el_periodo_vuelve_a_quedar_por_pagar(): void
    {
        $jefa = $this->jefa();
        $id = $this->actingAs($jefa)->postJson('/api/finanzas/recurrentes', [
            'nombre' => 'Energía Norte', 'categoria_gasto_id' => $this->categoria('Energía'),
            'monto' => 400_000, 'monto_estimado' => true, 'frecuencia' => 'mensual', 'dia_pago' => 18, 'desde' => '2026-10-01',
        ])->json('id');

        $gasto = $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'gasto_recurrente_id' => $id, 'periodo' => '2026-10-01', 'monto' => 410_000,
        ])->json('id');

        $this->actingAs($jefa)->postJson("/api/finanzas/gastos/{$gasto}/anular", [])->assertStatus(422);
        $this->actingAs($jefa)->postJson("/api/finanzas/gastos/{$gasto}/anular", ['motivo' => 'Se cargó dos veces'])->assertOk();

        $m = $this->actingAs($jefa)->getJson('/api/finanzas/estado-resultados?desde=2026-10&hasta=2026-10')->json('meses.0');
        $this->assertEquals(0, $m['gastos']['total']);
        $this->assertTrue(collect($this->actingAs($jefa)->getJson('/api/finanzas/gastos/pendientes')->json())->contains('periodo', '2026-10-01'));
        $this->assertDatabaseHas('gastos_bitacora', ['entidad' => 'gasto', 'entidad_id' => $gasto, 'accion' => 'anular']);
        $this->assertDatabaseHas('gastos', ['id' => $gasto, 'estado' => 'anulado']);
    }

    public function test_la_licencia_anual_se_reparte_en_doce_meses_pero_sale_entera_de_caja(): void
    {
        $jefa = $this->jefa();
        $id = $this->actingAs($jefa)->postJson('/api/finanzas/recurrentes', [
            'nombre' => 'Licencia anual', 'categoria_gasto_id' => $this->categoria('Software y licencias'),
            'monto' => 1_200_000, 'frecuencia' => 'anual', 'dia_pago' => 10, 'desde' => '2026-08-01', 'prorratear' => true,
        ])->json('id');

        $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'gasto_recurrente_id' => $id, 'periodo' => '2026-08-01', 'monto' => 1_200_000, 'fecha_pago' => '2026-08-10',
        ])->assertCreated();

        $er = collect($this->actingAs($jefa)->getJson('/api/finanzas/estado-resultados?desde=2026-08&hasta=2026-10')->json('meses'));
        $this->assertEquals([100_000, 100_000, 100_000], $er->pluck('gastos.total')->all());

        $caja = collect($this->actingAs($jefa)->getJson('/api/finanzas/flujo-caja?desde=2026-08&hasta=2026-10')->json('meses'));
        $this->assertEquals([1_200_000, 0, 0], $caja->pluck('salidas.gastos')->all());
    }

    public function test_el_recibo_de_septiembre_pagado_en_octubre_es_gasto_de_septiembre(): void
    {
        $this->actingAs($this->jefa())->postJson('/api/finanzas/gastos', [
            'categoria_gasto_id' => $this->categoria('Agua'), 'concepto' => 'Agua de septiembre',
            'monto' => 90_000, 'fecha_pago' => '2026-10-03', 'corresponde_a' => '2026-09',
        ])->assertCreated();

        $er = collect($this->actingAs($this->jefa())->getJson('/api/finanzas/estado-resultados?desde=2026-09&hasta=2026-10')->json('meses'));
        $this->assertEquals([90_000, 0], $er->pluck('gastos.total')->all());
    }

    // ── Comisiones ───────────────────────────────────────────────────────────

    /**
     * Se llevó 200.000 de anticipo en octubre; la comisión bruta de septiembre
     * (500.000) se pagó el 20 de octubre descontando el anticipo de septiembre
     * (200.000). Caja de octubre: 300.000 + 200.000 = 500.000. Costo de
     * septiembre: 500.000.
     */
    public function test_la_comision_con_anticipo_no_se_cuenta_dos_veces(): void
    {
        $c = DB::table('comisiones')->insertGetId(['vendedor_id' => 2, 'tienda_id' => 1, 'mes_venta' => '2026-09',
            'estado' => 'pagada', 'monto_comision' => 500_000, 'fecha_pago' => Carbon::parse('2026-10-20 14:00', 'UTC')]);
        DB::table('comision_anticipos')->insert([
            ['vendedor_id' => 2, 'tipo' => 'anticipo', 'mes' => '2026-10', 'monto' => 200_000, 'comision_id' => null],
            ['vendedor_id' => 2, 'tipo' => 'descuento', 'mes' => '2026-09', 'monto' => 200_000, 'comision_id' => $c],
        ]);

        $er = collect($this->actingAs($this->jefa())->getJson('/api/finanzas/estado-resultados?desde=2026-09&hasta=2026-10')->json('meses'));
        $this->assertEquals([500_000, 0], $er->pluck('comisiones.total')->all());

        $caja = collect($this->actingAs($this->jefa())->getJson('/api/finanzas/flujo-caja?desde=2026-09&hasta=2026-10')->json('meses'));
        $this->assertEquals([0, 500_000], $caja->pluck('salidas.comisiones')->all());
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_solo_supervisores_con_acceso_a_finanzas(): void
    {
        $this->actingAs(Usuario::find(2))->getJson('/api/finanzas/resumen')->assertForbidden();
        $this->actingAs(Usuario::find(3))->getJson('/api/finanzas/resumen')->assertForbidden();
        $this->actingAs(Usuario::find(2))->postJson('/api/finanzas/gastos', [])->assertForbidden();
        $this->actingAs($this->jefa())->getJson('/api/finanzas/resumen')->assertOk()
            ->assertJsonStructure(['mes' => ['ventas', 'utilidad_operativa'], 'indicadores' => ['punto_equilibrio', 'semaforo'], 'proximos']);
    }

    public function test_las_demas_pantallas_responden(): void
    {
        $this->venta(5_950_000, 2_000_000);
        $jefa = $this->jefa();
        $this->actingAs($jefa)->getJson('/api/finanzas/proyeccion?meses=3')->assertOk()->assertJsonCount(4, 'meses');
        $this->actingAs($jefa)->getJson('/api/finanzas/flujo-caja')->assertOk()->assertJsonCount(13, 'semanal.semanas');
        $this->actingAs($jefa)->getJson('/api/finanzas/calendario')->assertOk();
        $this->actingAs($jefa)->getJson('/api/finanzas/por-tienda?mes=2026-10')->assertOk()->assertJsonPath('tiendas.0.nombre', 'Decasa Norte');
        $this->actingAs($jefa)->getJson('/api/finanzas/presupuesto?mes=2026-10')->assertOk();
        $this->actingAs($jefa)->putJson('/api/finanzas/ajustes', ['saldo_inicial' => 10_000_000, 'saldo_fecha' => '2026-09'])->assertOk();
        $this->actingAs($jefa)->getJson('/api/finanzas/flujo-caja?desde=2026-09&hasta=2026-10')->assertOk()
            ->assertJsonPath('meses.0.saldo', 10_000_000)
            ->assertJsonPath('meses.1.saldo', 12_000_000);
    }

    public function test_el_aviso_de_gastos_por_vencer_llega_una_vez(): void
    {
        $this->actingAs($this->jefa())->postJson('/api/finanzas/recurrentes', [
            'nombre' => 'Arriendo Edén', 'categoria_gasto_id' => $this->categoria('Arriendo'),
            'monto' => 3_000_000, 'frecuencia' => 'mensual', 'dia_pago' => 23, 'desde' => '2026-10-01', 'avisar_dias_antes' => 3,
        ])->assertCreated();

        (new \App\Jobs\AvisarGastosPorVencer())->handle();
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => 1, 'tipo' => 'finanzas']);
        // A quien no tiene acceso a Finanzas no le llega.
        $this->assertDatabaseMissing('notificaciones', ['usuario_id' => 3]);

        // Al día siguiente (faltan 2) no se repite.
        Carbon::setTestNow(Carbon::parse('2026-10-21 15:00:00', 'America/Bogota'));
        (new \App\Jobs\AvisarGastosPorVencer())->handle();
        $this->assertEquals(1, DB::table('notificaciones')->where('usuario_id', 1)->count());
    }

    // ── La matemática ────────────────────────────────────────────────────────

    public function test_tendencia_lineal_y_promedio_ponderado_con_numeros_a_mano(): void
    {
        // y = 10 + 2t, sin error: el mes siguiente a seis cerrados (t = 7) da 24.
        $m = Proyeccion::modelo([10, 12, 14, 16, 18, 20]);
        $this->assertSame('tendencia', $m['metodo']);
        $this->assertEqualsWithDelta(24, $m['predecir'](7), 0.0001);
        $this->assertEqualsWithDelta(0, $m['error'], 0.0001);

        // 0,2 × 100 + 0,3 × 200 + 0,5 × 300 = 230.
        $p = Proyeccion::modelo([100, 200, 300]);
        $this->assertSame('promedio_ponderado', $p['metodo']);
        $this->assertEqualsWithDelta(230, $p['predecir'](4), 0.0001);
    }

    public function test_una_semana_que_cruza_de_mes_se_reparte_por_dias(): void
    {
        $r = Periodo::repartir('2026-09-29', '2026-10-05');
        $this->assertEqualsWithDelta(2 / 7, $r['2026-09'], 0.0001);
        $this->assertEqualsWithDelta(5 / 7, $r['2026-10'], 0.0001);
    }

    // ── Recomendaciones (2026-10-10) ─────────────────────────────────────────

    /**
     * Factura de septiembre por 1.000.000 a 30 días; se abonan 400.000 en
     * octubre. El gasto es de septiembre (la factura); octubre solo ve la caja.
     */
    public function test_factura_a_credito_es_gasto_de_su_mes_y_los_abonos_son_caja(): void
    {
        DB::table('proveedores')->insert(['id' => 1, 'nombre' => 'Maderas del Quindío']);
        $jefa = $this->jefa();
        $f = $this->actingAs($jefa)->postJson('/api/finanzas/cuentas-por-pagar', [
            'proveedor_id' => 1, 'concepto' => 'Madera de sillas', 'numero_factura' => 'FE-88',
            'categoria_gasto_id' => $this->categoria('Insumos de taller'), 'monto' => 1_000_000,
            'fecha_factura' => '2026-09-25', 'fecha_vencimiento' => '2026-10-25',
        ])->assertCreated()->json('id');

        $this->actingAs($jefa)->postJson("/api/finanzas/cuentas-por-pagar/{$f}/pagar", ['monto' => 400_000, 'fecha_pago' => '2026-10-05'])
            ->assertOk()->assertJsonPath('saldo', 600000)->assertJsonPath('estado', 'pendiente');

        $er = collect($this->actingAs($jefa)->getJson('/api/finanzas/estado-resultados?desde=2026-09&hasta=2026-10')->json('meses'));
        $this->assertEquals([1_000_000, 0], $er->pluck('gastos.total')->all());
        $caja = collect($this->actingAs($jefa)->getJson('/api/finanzas/flujo-caja?desde=2026-09&hasta=2026-10')->json('meses'));
        $this->assertEquals([0, 400_000], $caja->pluck('salidas.gastos')->all());

        $cal = collect($this->actingAs($jefa)->getJson('/api/finanzas/calendario')->json());
        $this->assertEquals(600_000, $cal->firstWhere('tipo', 'proveedor')['monto']);

        $this->actingAs($jefa)->postJson("/api/finanzas/cuentas-por-pagar/{$f}/pagar", ['monto' => 700_000])->assertStatus(422);
        $this->actingAs($jefa)->postJson("/api/finanzas/cuentas-por-pagar/{$f}/pagar", [])->assertJsonPath('estado', 'pagada');
    }

    public function test_un_mes_cerrado_no_se_mueve_y_no_acepta_gastos_hasta_reabrirlo(): void
    {
        $jefa = $this->jefa();
        $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'categoria_gasto_id' => $this->categoria('Agua'), 'concepto' => 'Agua', 'monto' => 100_000, 'fecha_pago' => '2026-09-10',
        ])->assertCreated();

        $this->actingAs($jefa)->postJson('/api/finanzas/cierres', ['mes' => '2026-10'])->assertStatus(422);   // no ha terminado
        $this->actingAs($jefa)->postJson('/api/finanzas/cierres', ['mes' => '2026-09'])->assertCreated();

        // Un gasto que llega tarde para septiembre: no entra.
        $this->actingAs($jefa)->postJson('/api/finanzas/gastos', [
            'categoria_gasto_id' => $this->categoria('Agua'), 'concepto' => 'Agua tarde', 'monto' => 50_000,
            'fecha_pago' => '2026-10-02', 'corresponde_a' => '2026-09',
        ])->assertStatus(422);

        // Aunque algo cambie por debajo, el mes cerrado muestra su copia.
        $this->venta(1_190_000, 0, '2026-09-15 10:00');
        $sep = $this->actingAs($jefa)->getJson('/api/finanzas/estado-resultados?desde=2026-09&hasta=2026-09')->json('meses.0');
        $this->assertTrue($sep['cerrado']);
        $this->assertEquals(0, $sep['ventas']);

        $this->actingAs($jefa)->postJson('/api/finanzas/cierres/2026-09/reabrir', ['motivo' => 'Faltó una venta'])->assertOk();
        $sep = $this->actingAs($jefa)->getJson('/api/finanzas/estado-resultados?desde=2026-09&hasta=2026-09')->json('meses.0');
        $this->assertFalse($sep['cerrado']);
        $this->assertEquals(1_190_000, $sep['ventas']);
    }

    public function test_rentabilidad_por_canal_con_la_publicidad_de_cada_uno(): void
    {
        $id = $this->venta(5_950_000, 0);
        DB::table('ordenes')->where('id', $id)->update(['canal' => 'instagram']);
        $this->venta(2_380_000, 0);   // sin canal → "otro"
        $this->actingAs($this->jefa())->postJson('/api/finanzas/gastos', [
            'categoria_gasto_id' => $this->categoria('Publicidad y redes'), 'concepto' => 'Anuncios Instagram',
            'monto' => 500_000, 'fecha_pago' => '2026-10-03', 'canal' => 'instagram',
        ])->assertCreated();

        $c = collect($this->actingAs($this->jefa())->getJson('/api/finanzas/por-canal?mes=2026-10')->assertOk()->json('canales'))->keyBy('canal');
        $this->assertEquals(5_950_000, $c['instagram']['vendido']);
        $this->assertEquals(500_000, $c['instagram']['publicidad']);
        $this->assertEquals(11.9, $c['instagram']['retorno']);   // $11,9 de venta por cada $1 de publicidad
    }

    public function test_la_temporada_sale_del_mismo_mes_del_anio_anterior(): void
    {
        // Un año parejo de 100 con un diciembre de 150. Diciembre de 2025 frente
        // a los 12 meses a su alrededor (jun-25 a may-26): 150 ÷ (11 × 100 + 150) / 12 = 1,44.
        $serie = [];
        foreach (Periodo::meses('2025-06', '2026-09') as $m) $serie[$m] = str_ends_with($m, '-12') ? 150.0 : 100.0;
        $this->assertEqualsWithDelta(1.44, Proyeccion::indiceEstacional($serie, '2026-12'), 0.01);
        $this->assertNull(Proyeccion::indiceEstacional($serie, '2027-09'), 'sin el mismo mes del año anterior no hay temporada');
    }

    public function test_el_asistente_solo_le_cuenta_las_finanzas_a_quien_tiene_acceso(): void
    {
        $this->venta(11_900_000, 5_000_000);
        $agente = app(\App\Services\AgentService::class);
        $resumen = new \ReflectionMethod($agente, 'handleResumenFinanciero');

        $r = $resumen->invoke($agente, ['mes' => '2026-10'], $this->jefa());
        $this->assertEquals(11_900_000, $r['ventas_con_iva']);
        $this->assertEquals(10_000_000, $r['ingresos_netos']);

        $this->assertArrayHasKey('error', $resumen->invoke($agente, [], Usuario::find(2)));
        $this->assertArrayHasKey('error', $resumen->invoke($agente, [], Usuario::find(3)));
    }

    public function test_el_resumen_del_dia_1_llega_a_quien_ve_finanzas(): void
    {
        $this->venta(11_900_000, 5_000_000, '2026-09-10 12:00');
        Carbon::setTestNow(Carbon::parse('2026-10-01 07:30:00', 'America/Bogota'));

        (new \App\Jobs\ResumenFinancieroMensual())->handle();

        $n = DB::table('notificaciones')->where('usuario_id', 1)->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('Septiembre 2026', $n->titulo);
        $this->assertDatabaseMissing('notificaciones', ['usuario_id' => 3]);
    }
}
