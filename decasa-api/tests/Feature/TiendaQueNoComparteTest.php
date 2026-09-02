<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cada tienda dice si su comisión es del equipo o de cada quien.
 *
 * Antes se deducía de tener meta puesta, y son dos cosas distintas: la meta es
 * una cifra de ventas, compartir es un acuerdo con la gente. Se notó con
 * Tienda Virtual —cuatro personas registradas como equipo y sin meta— y había
 * que adivinar qué hacer con una restauración de allá.
 *
 * Con el interruptor apagado no se reparte nada, tenga meta o no: cada uno
 * cobra el 5% de lo suyo y las restauraciones enteras.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class TiendaQueNoComparteTest extends TestCase
{
    private const MES = '2026-08';
    private const TIENDA = 1;

    /** Ids de la gente */
    private const ANA = 1, BETO = 2;

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
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
            $t->string('tipo')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tienda_trimestres', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('trimestre', 7);
            $t->decimal('deficit_inicial', 15, 2)->default(0);
            $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0);
            $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamps();
        });

        // Una tienda con meta y con dos personas. El interruptor lo pone cada
        // prueba, que es lo que se está comprobando.
        DB::table('tiendas')->insert([
            'id' => self::TIENDA, 'nombre' => 'Tienda de prueba', 'activa' => true,
            'comisiones_compartidas' => false,
        ]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => self::TIENDA, 'mes' => self::MES, 'meta' => 20_000_000,
            'divisor_asesores' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[self::ANA, 'Ana'], [self::BETO, 'Beto']] as [$id, $nombre]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor',
                'tienda_default_id' => self::TIENDA, 'created_at' => now(),
            ]);
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => self::TIENDA, 'mes' => self::MES, 'vendedor_id' => $id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        ComisionController::olvidarQuienComparte();

        $this->prestarleASqliteLoQueEsDeMysql();
    }

    private function laTiendaComparte(bool $si): void
    {
        DB::table('tiendas')->where('id', self::TIENDA)
            ->update(['comisiones_compartidas' => $si]);

        ComisionController::olvidarQuienComparte();
    }

    private function orden(int $vendedor, float $valor, bool $restauracion = false): Orden
    {
        $orden = Orden::create([
            'tienda_id' => self::TIENDA, 'vendedor_id' => $vendedor,
            'estado' => 'entregado', 'valor_total' => $valor,
        ]);

        DB::table('ordenes')->where('id', $orden->id)
            ->update(['created_at' => self::MES . '-15 15:00:00']);
        DB::table('orden_items')->insert([
            'orden_id' => $orden->id, 'es_restauracion' => $restauracion, 'precio_unitario' => $valor,
        ]);
        DB::table('pagos')->insert([
            'orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => now(),
        ]);

        ComisionController::crearParaOrden($orden->fresh());
        ComisionController::sincronizarValorOrden($orden->fresh());

        return $orden->fresh();
    }

    /** [nombre => monto], como sale en pantalla. */
    private function loQueCobraCadaUno(): array
    {
        $ctrl = app(ComisionController::class);

        $cargar = new \ReflectionMethod($ctrl, 'cargarTotales');
        $cargar->setAccessible(true);
        [$metas, $totTienda, $totVendedor] = $cargar->invoke($ctrl);

        $pools = new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales');
        $pools->setAccessible(true);

        $enriquecer = new \ReflectionMethod($ctrl, 'enriquecer');
        $enriquecer->setAccessible(true);

        $nombres = DB::table('usuarios')->pluck('nombre', 'id')->all();
        $out = [];

        foreach (Comision::with('orden.pagos', 'tienda')->get() as $c) {
            $f = $enriquecer->invoke($ctrl, $c, $metas, $totTienda, $totVendedor,
                $pools->invoke($ctrl, $metas, $totTienda, false),
                \Carbon\Carbon::parse('2026-09-25'));

            $quien = $nombres[$c->vendedor_id];
            $out[$quien] = ($out[$quien] ?? 0) + (float) $f['monto_comision'];
        }

        return $out;
    }

    public function test_apagado_cada_uno_cobra_el_cinco_por_ciento_de_lo_suyo(): void
    {
        $this->laTiendaComparte(false);

        // Vende por encima de la meta: aun así no hay pool que repartir.
        $this->orden(self::ANA, 30_000_000);

        $cobra = $this->loQueCobraCadaUno();

        // 30.000.000 ÷ 1,19 × 5% = $1.260.504, enteros para ella.
        $this->assertEqualsWithDelta(1_260_504, $cobra['Ana'], 2);
        $this->assertArrayNotHasKey('Beto', $cobra, 'no vendió y aquí no se reparte');
    }

    public function test_encendido_la_misma_venta_va_por_el_pool(): void
    {
        $this->laTiendaComparte(true);

        $this->orden(self::ANA, 30_000_000);

        $cobra = $this->loQueCobraCadaUno();

        // Pool = (30.000.000 − 20.000.000) ÷ 1,19 × 5% = $420.168, ÷ 2 = $210.084
        $this->assertEqualsWithDelta(210_084, $cobra['Ana'], 2);
    }

    public function test_apagado_la_restauracion_es_entera_de_quien_la_hizo(): void
    {
        $this->laTiendaComparte(false);

        $this->orden(self::ANA, 1_000_000, restauracion: true);

        $reparto = Comision::pluck('valor_orden', 'vendedor_id')->map(fn ($v) => (float) $v);

        $this->assertCount(1, $reparto);
        $this->assertEquals(1_000_000.0, $reparto[self::ANA]);
        $this->assertEquals(50_000, $this->loQueCobraCadaUno()['Ana']);
    }

    public function test_encendido_la_restauracion_se_parte(): void
    {
        $this->laTiendaComparte(true);

        $this->orden(self::ANA, 1_000_000, restauracion: true);

        $cobra = $this->loQueCobraCadaUno();

        $this->assertEquals(25_000, $cobra['Ana']);
        $this->assertEquals(25_000, $cobra['Beto']);
    }

    public function test_apagado_a_quien_no_vendio_no_se_le_abre_ningun_renglon(): void
    {
        $this->laTiendaComparte(false);
        $this->orden(self::ANA, 30_000_000);

        $ctrl   = app(ComisionController::class);
        $metodo = new \ReflectionMethod($ctrl, 'asegurarPartesDePool');
        $metodo->setAccessible(true);
        $metodo->invoke($ctrl, self::MES);

        $this->assertSame(0, Comision::where('origen', ComisionController::ORIGEN_PARTE_POOL)->count(),
            'sin pool no hay parte que darle a nadie');
    }

    public function test_encendido_a_quien_no_vendio_si(): void
    {
        $this->laTiendaComparte(true);
        $this->orden(self::ANA, 30_000_000);

        $ctrl   = app(ComisionController::class);
        $metodo = new \ReflectionMethod($ctrl, 'asegurarPartesDePool');
        $metodo->setAccessible(true);
        $metodo->invoke($ctrl, self::MES);

        $renglon = Comision::where('origen', ComisionController::ORIGEN_PARTE_POOL)->first();

        $this->assertNotNull($renglon);
        $this->assertSame(self::BETO, (int) $renglon->vendedor_id);
    }

    public function test_apagarlo_le_devuelve_la_restauracion_entera_a_quien_la_hizo(): void
    {
        $this->laTiendaComparte(true);
        $orden = $this->orden(self::ANA, 1_000_000, restauracion: true);
        $this->assertSame(2, Comision::where('orden_id', $orden->id)->count());

        // Se apaga el interruptor y se vuelve a poner al día.
        $this->laTiendaComparte(false);
        ComisionController::sincronizarValorOrden($orden->fresh());

        $reparto = Comision::where('orden_id', $orden->id)
            ->pluck('valor_orden', 'vendedor_id')->map(fn ($v) => (float) $v);

        $this->assertCount(1, $reparto, 'a Beto se le quita');
        $this->assertEquals(1_000_000.0, $reparto[self::ANA]);
    }
}
