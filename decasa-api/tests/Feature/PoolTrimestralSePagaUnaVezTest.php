<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\TiendaAsesor;
use App\Models\TiendaReemplazo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El pool de un trimestre se paga UNA vez, no una por cada mes.
 *
 * En Pereira y Circunvalar la meta se mira por trimestre: el pool sale de
 * sumar (ventas − meta) de los tres meses. Pero cada comisión se prorrateaba
 * contra lo que ese vendedor vendió EN SU MES, así que las órdenes de julio
 * sumaban el pool entero, las de agosto otra vez, y las de septiembre otra:
 * un pool de $1.260.504 salía pagado como $3.781.512.
 *
 * Nadie lo había visto porque el primer trimestre con esta regla (Q3 2026)
 * se paga el 20 de octubre.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class PoolTrimestralSePagaUnaVezTest extends TestCase
{
    private const PEREIRA = 1;
    private const JUAN = 1, GENESIS = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable();
            $t->string('nota')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0);
            $t->string('metodo')->nullable(); $t->string('tipo')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('tienda_trimestres', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('trimestre', 7);
            $t->decimal('deficit_inicial', 15, 2)->default(0);
            $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0);
            $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamp('cerrado_at')->nullable();
            $t->timestamps();
        });

        // El nombre es lo que la hace trimestral.
        DB::table('tiendas')->insert([
            'id' => self::PEREIRA, 'nombre' => 'Decasa Unicentro Pereira',
            'activa' => true, 'comisiones_compartidas' => true,
        ]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => self::PEREIRA, 'mes' => '2026-07', 'meta' => 10_000_000,
            'divisor_asesores' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[self::JUAN, 'Juan'], [self::GENESIS, 'Genesis']] as [$id, $nombre]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor',
                'tienda_default_id' => self::PEREIRA, 'created_at' => now(),
            ]);
        }
        DB::table('tienda_asesores_comision')->insert([
            'tienda_id' => self::PEREIRA, 'mes' => '2026-07', 'vendedor_id' => self::JUAN,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();
        $this->prestarleASqliteLoQueEsDeMysql();
    }

    private function orden(int $vendedor, string $fecha, float $valor): Orden
    {
        $orden = Orden::create([
            'tienda_id' => self::PEREIRA, 'vendedor_id' => $vendedor,
            'estado' => 'entregado', 'valor_total' => $valor,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => $fecha . ' 15:00:00']);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'precio_unitario' => $valor]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => now()]);

        ComisionController::crearParaOrden($orden->fresh());

        return $orden->fresh();
    }

    /** [nombre => monto] sumando todas las comisiones del trimestre. */
    private function loQueCobraCadaUno(): array
    {
        $ctrl = app(ComisionController::class);

        $cargar = new \ReflectionMethod($ctrl, 'cargarTotales');
        [$metas, $totTienda, $totVendedor] = $cargar->invoke($ctrl);

        $pools = new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales');
        $poolsTrim = $pools->invoke($ctrl, $metas, $totTienda, false);

        $enriquecer = new \ReflectionMethod($ctrl, 'enriquecer');
        $nombres = DB::table('usuarios')->pluck('nombre', 'id')->all();
        $out = [];

        foreach (Comision::with('orden.pagos', 'tienda')->get() as $c) {
            $f = $enriquecer->invoke($ctrl, $c, $metas, $totTienda, $totVendedor,
                $poolsTrim, \Carbon\Carbon::parse('2026-10-25'));
            $out[$nombres[$c->vendedor_id]] = ($out[$nombres[$c->vendedor_id]] ?? 0) + (float) $f['monto_comision'];
        }

        return $out;
    }

    /** Lo que abre la pantalla del resumen: el renglón de quien no vendió ese mes. */
    private function abrirRenglonesDe(string $mes): void
    {
        $ctrl = app(ComisionController::class);
        $m = new \ReflectionMethod($ctrl, 'crearPartesDePool');
        $m->invoke($ctrl, $mes);
    }

    public function test_un_solo_asesor_vendiendo_los_tres_meses_cobra_el_pool_una_vez(): void
    {
        // Cada mes 20M contra meta de 10M: diferencial del trimestre = +30M.
        // Pool = 30.000.000 ÷ 1,19 × 5% = $1.260.504. Es UNA cifra para el
        // trimestre entero, no una por mes.
        $this->orden(self::JUAN, '2026-07-10', 20_000_000);
        $this->orden(self::JUAN, '2026-08-10', 20_000_000);
        $this->orden(self::JUAN, '2026-09-10', 20_000_000);

        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(1_260_504, $cobra['Juan'], 3);
    }

    public function test_una_venta_sin_la_mitad_pagada_no_agranda_el_pool_del_trimestre(): void
    {
        // Lo mismo que el caso de arriba, más una venta de 10M en septiembre
        // con apenas el 10% pagado. Igual que en las tiendas mensuales, no
        // cuenta para la meta: el pool sigue siendo el de los 30M, no el de 40M.
        $this->orden(self::JUAN, '2026-07-10', 20_000_000);
        $this->orden(self::JUAN, '2026-08-10', 20_000_000);
        $this->orden(self::JUAN, '2026-09-10', 20_000_000);
        $sinMitad = $this->orden(self::JUAN, '2026-09-20', 10_000_000);
        DB::table('pagos')->where('orden_id', $sinMitad->id)->update(['monto' => 1_000_000]);

        $ctrl = app(ComisionController::class);
        [$metas, $totTienda] = (new \ReflectionMethod($ctrl, 'cargarTotales'))->invoke($ctrl);
        $pools = (new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales'))->invoke($ctrl, $metas, $totTienda, false);

        // 30.000.000 ÷ 1,19 × 5%, no 40.000.000 ÷ 1,19 × 5% ($1.680.672).
        $this->assertEqualsWithDelta(1_260_504, $pools[self::PEREIRA . '_2026-Q3']['pool_pagado'], 3);

        // Cuando el cliente paga la mitad, entra y el pool crece.
        DB::table('pagos')->where('orden_id', $sinMitad->id)->update(['monto' => 5_000_000]);
        [$metas, $totTienda] = (new \ReflectionMethod($ctrl, 'cargarTotales'))->invoke($ctrl);
        $pools = (new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales'))->invoke($ctrl, $metas, $totTienda, false);

        $this->assertEqualsWithDelta(1_680_672, $pools[self::PEREIRA . '_2026-Q3']['pool_pagado'], 3);
    }

    public function test_la_cuenta_del_trimestre_se_ve_mes_a_mes(): void
    {
        $this->orden(self::JUAN, '2026-07-10', 20_000_000);
        $this->orden(self::JUAN, '2026-08-10', 20_000_000);
        $this->orden(self::JUAN, '2026-09-10', 20_000_000);
        $sinMitad = $this->orden(self::JUAN, '2026-09-20', 10_000_000);
        DB::table('pagos')->where('orden_id', $sinMitad->id)->update(['monto' => 1_000_000]);

        $ctrl = app(ComisionController::class);
        [$metas, $totTienda] = (new \ReflectionMethod($ctrl, 'cargarTotales'))->invoke($ctrl);
        $pools = (new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales'))->invoke($ctrl, $metas, $totTienda, false);
        $t = (new \ReflectionMethod($ctrl, 'cuentaDelTrimestre'))
            ->invoke($ctrl, self::PEREIRA, '2026-08', $metas, $totTienda, $pools);

        $this->assertSame('2026-Q3', $t['trimestre']);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($t['meses'], 'mes'));
        // Cada mes: 20M que cuentan contra 10M de meta.
        foreach ($t['meses'] as $m) {
            $this->assertEquals(20_000_000, $m['cuenta']);
            $this->assertEquals(10_000_000, $m['meta']);
            $this->assertEquals(10_000_000, $m['diferencia']);
        }
        // La de septiembre sin la mitad se ve aparte, sin sumar.
        $this->assertEquals(10_000_000, $t['meses'][2]['sin_mitad']);

        $this->assertEquals(30_000_000, $t['diferencial']);
        $this->assertEqualsWithDelta(1_260_504, $t['pool_bruto'], 1);
        $this->assertEquals(0, $t['deficit_inicial']);
        $this->assertEqualsWithDelta(1_260_504, $t['pool_pagado'], 1);
        $this->assertEquals(0, $t['deficit_final']);
    }

    public function test_el_mes_que_no_vendio_le_paga_su_parte_y_la_suma_sigue_siendo_el_pool(): void
    {
        // Julio y septiembre vende; agosto no. El pool del trimestre se
        // reparte por meses (31 + 31 + 30 días): lo de agosto le llega por el
        // renglón de "no vendió", y todo junto vuelve a ser el pool entero.
        $this->orden(self::JUAN, '2026-07-10', 30_000_000);
        $this->orden(self::JUAN, '2026-09-10', 30_000_000);
        $this->abrirRenglonesDe('2026-08');

        $cobra = $this->loQueCobraCadaUno();

        // (60M − 30M) ÷ 1,19 × 5% = $1.260.504
        $this->assertEqualsWithDelta(1_260_504, $cobra['Juan'], 3);
        $this->assertSame(1, Comision::where('origen', ComisionController::ORIGEN_PARTE_POOL)->count());
    }

    public function test_quien_llega_a_mitad_de_trimestre_pesa_solo_los_dias_que_estuvo(): void
    {
        // Genesis se traslada el 1 de septiembre. Juan pesa 92 días (jul+ago+sep),
        // ella 30. El pool se parte 92/122 y 30/122 — no la mitad.
        TiendaReemplazo::create([
            'tienda_id' => self::PEREIRA, 'tipo' => TiendaReemplazo::TRASLADO,
            'usuario_id' => self::GENESIS, 'desde' => '2026-09-01', 'hasta' => '2026-09-30',
        ]);
        TiendaReemplazo::olvidarCache();

        $this->orden(self::JUAN,    '2026-07-10', 20_000_000);
        $this->orden(self::JUAN,    '2026-08-10', 20_000_000);
        $this->orden(self::JUAN,    '2026-09-10', 10_000_000);
        $this->orden(self::GENESIS, '2026-09-12', 10_000_000);

        $cobra = $this->loQueCobraCadaUno();

        $pool = 30_000_000 / 1.19 * 0.05;    // $1.260.504
        $this->assertEqualsWithDelta($pool * 92 / 122, $cobra['Juan'], 3);
        $this->assertEqualsWithDelta($pool * 30 / 122, $cobra['Genesis'], 3);
        $this->assertEqualsWithDelta($pool, $cobra['Juan'] + $cobra['Genesis'], 3);
    }
}
