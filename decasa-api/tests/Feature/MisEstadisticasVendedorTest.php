<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Mis estadísticas" del vendedor se parece al Resumen de Reportes: su
 * recuadro cuadra (vendido = cobrado + por cobrar del período), dice de qué
 * es lo vendido, se compara con el período anterior, y trae las tarjetas de
 * SUS tiendas —no de todas— con cuánto de cada una es suyo.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class MisEstadisticasVendedorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable();
            $t->boolean('activa')->default(true); $t->boolean('es_fabrica')->default(false);
            $t->boolean('es_independientes')->default(false); $t->date('cerrada_en')->nullable();
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes', 7); $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable(); $t->string('nota')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('covendedor_id')->nullable(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->boolean('es_compartida')->default(false); $t->string('estado')->default('en_produccion');
            $t->string('canal')->nullable(); $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
            $t->timestamps();
        });

        DB::statement('
            CREATE VIEW v_saldo_ordenes AS
            SELECT o.id AS orden_id, o.valor_total,
                   COALESCE(SUM(p.monto), 0) AS total_pagado,
                   o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
            FROM ordenes o LEFT JOIN pagos p ON p.orden_id = o.id
            GROUP BY o.id, o.valor_total
        ');

        $this->prestarleASqliteLoQueEsDeMysql();

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Norte'],
            ['id' => 2, 'nombre' => 'Decasa Sur'],
            ['id' => 3, 'nombre' => 'Decasa Centro'],
        ]);
        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Vendedora', 'rol' => 'vendedor', 'tienda_default_id' => 1, 'created_at' => now()],
            ['id' => 2, 'nombre' => 'Compañero', 'rol' => 'vendedor', 'tienda_default_id' => 1, 'created_at' => now()],
            ['id' => 3, 'nombre' => 'Del Centro', 'rol' => 'vendedor', 'tienda_default_id' => 3, 'created_at' => now()],
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente']);
    }

    private function orden(Carbon $creada, float $valor, int $vendedor = 1, int $tienda = 1, array $extra = []): int
    {
        return DB::table('ordenes')->insertGetId(array_merge([
            'tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'cliente_id' => 1, 'estado' => 'en_produccion',
            'valor_total' => $valor, 'created_at' => $creada, 'updated_at' => $creada,
        ], $extra));
    }

    private function abono(int $ordenId, Carbon $fecha, float $monto): void
    {
        DB::table('pagos')->insert([
            'orden_id' => $ordenId, 'tienda_id' => 1, 'monto' => $monto, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    private function yo(): Usuario
    {
        return Usuario::find(1);
    }

    public function test_el_recuadro_cuadra_y_dice_de_que_es_lo_vendido(): void
    {
        $esteMes = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');

        // Una venta de 10M con 3M abonados, y una restauración de 2M sin abonar.
        $venta = $this->orden($esteMes, 10_000_000);
        $this->abono($venta, $esteMes, 3_000_000);
        $this->orden($esteMes, 2_000_000, 1, 1, ['serie' => 'R']);

        $r = $this->actingAs($this->yo())->getJson('/api/stats/vendedores/me?periodo=mes')->assertOk()->json();

        $this->assertEquals(12_000_000, $r['total_vendido']);
        $this->assertEquals(3_000_000,  $r['dinero_vendido']);
        $this->assertEquals(9_000_000,  $r['cartera_periodo'], 'vendido = cobrado + por cobrar del período');

        $this->assertEquals(10_000_000, $r['por_tipo']['venta']['monto']);
        $this->assertEquals(1,          $r['por_tipo']['venta']['ordenes']);
        $this->assertEquals(2_000_000,  $r['por_tipo']['restauracion']['monto']);
        $this->assertEquals(0,          $r['por_tipo']['fv2']['monto']);
    }

    public function test_se_compara_con_el_periodo_anterior(): void
    {
        $esteMes   = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');
        $mesPasado = Carbon::now('America/Bogota')->subMonthNoOverflow()->startOfMonth()->addDays(10)->setTimezone('UTC');

        // Rango "mes_anterior": vendió 4M el mes pasado; el anterior a ese, nada.
        $this->orden($mesPasado, 4_000_000);
        $this->orden($esteMes,   6_000_000);

        $r = $this->actingAs($this->yo())->getJson('/api/stats/vendedores/me?periodo=mes_anterior')->assertOk()->json();
        $this->assertEquals(4_000_000, $r['total_vendido']);
        $this->assertNull($r['comparativa']['variacion_pct'], 'sin ventas antes no hay con qué comparar');

        // Con el rango del mes, se compara contra el mismo número de días
        // hacia atrás: si cae dentro del mes pasado, sale la variación.
        $dias = (int) Carbon::now('America/Bogota')->format('j');
        $desde = Carbon::now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = Carbon::now('America/Bogota')->toDateString();
        $anteriorDesde = Carbon::now('America/Bogota')->startOfMonth()->subDays($dias)->toDateString();
        $this->orden(Carbon::parse($anteriorDesde, 'America/Bogota')->addHours(12)->setTimezone('UTC'), 3_000_000);

        $r = $this->actingAs($this->yo())->getJson("/api/stats/vendedores/me?desde=$desde&hasta=$hasta")->assertOk()->json();
        $this->assertEquals(6_000_000, $r['total_vendido']);
        $this->assertEquals(3_000_000, $r['comparativa']['vendido_anterior']);
        $this->assertEquals(100.0,     $r['comparativa']['variacion_pct']);
    }

    public function test_mis_tiendas_trae_solo_las_suyas_con_su_parte(): void
    {
        $esteMes = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');

        // Yo vendí 6M en Norte (mi tienda) y 2M cubriendo en Sur. Mi compañero
        // vendió 4M en Norte. En Centro vendió otra persona: no es mi tienda.
        $this->orden($esteMes, 6_000_000, 1, 1);
        $this->orden($esteMes, 2_000_000, 1, 2);
        $this->orden($esteMes, 4_000_000, 2, 1);
        $this->orden($esteMes, 9_000_000, 3, 3);

        $filas = $this->actingAs($this->yo())->getJson('/api/stats/mis-tiendas?periodo=mes')->assertOk()->json();

        $this->assertEqualsCanonicalizing([1, 2], array_column($filas, 'tienda_id'), 'Centro no sale');

        $norte = collect($filas)->firstWhere('tienda_id', 1);
        $this->assertEquals(10_000_000, $norte['total_vendido'], 'lo de toda la tienda, como en Reportes');
        $this->assertEquals(6_000_000,  $norte['mi_parte']['vendido']);
        $this->assertEquals(1,          $norte['mi_parte']['ordenes']);
        $this->assertEquals(60.0,       $norte['mi_parte']['pct']);
        $this->assertTrue($norte['es_mi_tienda_hoy']);
        $this->assertSame(1, $filas[0]['tienda_id'], 'la tienda donde está hoy va primera');

        $sur = collect($filas)->firstWhere('tienda_id', 2);
        $this->assertEquals(2_000_000, $sur['total_vendido']);
        $this->assertEquals(100.0,     $sur['mi_parte']['pct']);
        $this->assertFalse($sur['es_mi_tienda_hoy']);
    }

    public function test_sin_ventas_sale_igual_la_tienda_de_la_ficha(): void
    {
        $filas = $this->actingAs($this->yo())->getJson('/api/stats/mis-tiendas?periodo=hoy')->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertSame(1, $filas[0]['tienda_id']);
        $this->assertEquals(0, $filas[0]['mi_parte']['vendido']);
        $this->assertNull($filas[0]['mi_parte']['pct']);
    }

    public function test_un_independiente_ve_su_propia_tarjeta_y_no_la_de_otro(): void
    {
        DB::table('tiendas')->insert(['id' => 9, 'nombre' => 'Independientes', 'es_independientes' => true]);
        DB::table('usuarios')->insert([
            ['id' => 5, 'nombre' => 'Indep. A', 'rol' => 'vendedor', 'independiente' => true, 'tienda_default_id' => 9, 'created_at' => now()],
            ['id' => 6, 'nombre' => 'Indep. B', 'rol' => 'vendedor', 'independiente' => true, 'tienda_default_id' => 9, 'created_at' => now()],
        ]);
        $esteMes = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');
        $this->orden($esteMes, 5_000_000, 5, 9);
        $this->orden($esteMes, 7_000_000, 6, 9);

        $filas = $this->actingAs(Usuario::find(5))->getJson('/api/stats/mis-tiendas?periodo=mes')->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertTrue($filas[0]['es_independiente']);
        $this->assertSame(5, $filas[0]['usuario_id']);
        $this->assertEquals(5_000_000, $filas[0]['total_vendido']);
    }
}
