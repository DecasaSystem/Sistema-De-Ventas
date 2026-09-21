<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\TiendaReemplazo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración que registra el reemplazo de agosto en Vía El Edén: Manuela
 * cubrió a Sebastián del 18 al 22. Con eso Sebastián pesa 26 días, Manuela 5
 * y Gladys los 31, y el pool de agosto se reparte así.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ManuelaCubrioASebastianEnElEdenTest extends TestCase
{
    private const MES = '2026-08';
    private const EDEN = 1, VIRTUAL = 3;
    private const GLADYS = 1, SEBASTIAN = 2, MANUELA = 3;

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
            $t->string('canal')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
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
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->json('cambios'); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tienda_trimestres', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('trimestre', 7);
            $t->decimal('deficit_inicial', 15, 2)->default(0);
            $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0);
            $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::EDEN,    'nombre' => 'Decasa Vía El Edén', 'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::VIRTUAL, 'nombre' => 'Tienda Virtual',     'activa' => true, 'comisiones_compartidas' => false],
        ]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => self::EDEN, 'mes' => self::MES, 'meta' => 20_000_000,
            'divisor_asesores' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            [self::GLADYS,    'Gladys',    self::EDEN],
            [self::SEBASTIAN, 'Sebastián', self::EDEN],
            [self::MANUELA,   'Manuela',   self::VIRTUAL],
        ] as [$id, $nombre, $tienda]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor',
                'tienda_default_id' => $tienda, 'created_at' => now(),
            ]);
        }
        foreach ([self::GLADYS, self::SEBASTIAN] as $quien) {
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => self::EDEN, 'mes' => self::MES, 'vendedor_id' => $quien,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        ComisionController::olvidarQuienComparte();

        $this->prestarleASqliteLoQueEsDeMysql();
    }

    private function venta(int $vendedor, string $dia, float $valor): Orden
    {
        $orden = Orden::create([
            'tienda_id' => self::EDEN, 'vendedor_id' => $vendedor, 'canal' => 'fisica',
            'estado' => 'entregado', 'valor_total' => $valor,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => self::MES . "-{$dia} 15:00:00"]);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'precio_unitario' => $valor]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => now()]);

        ComisionController::crearParaOrden($orden->fresh());

        return $orden->fresh();
    }

    private function correrLaMigracion(): void
    {
        $m = require base_path('database/migrations/2026_10_02_000002_manuela_cubrio_a_sebastian_en_el_eden.php');
        $m->up();
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
                $pools->invoke($ctrl, $metas, $totTienda, false), \Carbon\Carbon::parse('2026-09-25'));
            $quien = $nombres[$c->vendedor_id];
            $out[$quien] = ($out[$quien] ?? 0) + (float) $f['monto_comision'];
        }
        return $out;
    }

    public function test_registra_el_reemplazo_y_reparte_agosto_por_dias(): void
    {
        // Agosto: 30.000.000 en la tienda; pool = 10.000.000 ÷ 1,19 × 5% = $420.168.
        $this->venta(self::GLADYS,    '05', 12_000_000);
        $this->venta(self::SEBASTIAN, '10', 12_000_000);
        $this->venta(self::MANUELA,   '20',  6_000_000);

        // Sin el registro, Sebastián pesa el mes entero y Manuela cobra su 5%
        // por fuera, como cualquiera que vende en una tienda ajena.
        $antes = $this->loQueCobraCadaUno();
        $this->assertEqualsWithDelta(420_168 / 2, $antes['Sebastián'], 2);
        $this->assertEqualsWithDelta(6_000_000 / 1.19 * 0.05, $antes['Manuela'], 2);

        $this->correrLaMigracion();

        $r = TiendaReemplazo::first();
        $this->assertNotNull($r, 'quedó registrado');
        $this->assertSame([self::EDEN, self::MANUELA, self::SEBASTIAN, '2026-08-18', '2026-08-22'],
            [(int) $r->tienda_id, (int) $r->usuario_id, (int) $r->reemplaza_a_id, $r->desde->toDateString(), $r->hasta->toDateString()]);

        $despues = $this->loQueCobraCadaUno();

        // Sebastián pesa 26 de 62 días, Manuela 5, Gladys 31. El pool no cambia.
        $this->assertEqualsWithDelta(420_168 * 31 / 62, $despues['Gladys'], 2);
        $this->assertEqualsWithDelta(420_168 * 26 / 62, $despues['Sebastián'], 2);
        $this->assertEqualsWithDelta(420_168 *  5 / 62, $despues['Manuela'], 2);
    }

    public function test_si_ya_estaba_registrado_no_lo_duplica(): void
    {
        $this->correrLaMigracion();
        $this->correrLaMigracion();

        $this->assertSame(1, TiendaReemplazo::count());
    }

    public function test_si_no_encuentra_a_la_gente_no_toca_nada(): void
    {
        DB::table('usuarios')->where('id', self::MANUELA)->update(['nombre' => 'Otra persona']);

        $this->correrLaMigracion();

        $this->assertSame(0, TiendaReemplazo::count());
    }

    // ── FV2-3 y FV2-4 son de Gladys ──────────────────────────────────────────

    private function fv2(int $numero, int $vendedor, float $valor = 5_000_000): Orden
    {
        $orden = $this->venta($vendedor, '12', $valor);
        DB::table('ordenes')->where('id', $orden->id)
            ->update(['serie' => Orden::SERIE_FV2, 'serie_numero' => $numero]);

        return $orden->fresh();
    }

    private function pasarFv2AGladys(): void
    {
        $m = require base_path('database/migrations/2026_10_02_000003_fv2_3_y_fv2_4_son_de_gladys.php');
        $m->up();
    }

    public function test_fv2_3_y_fv2_4_pasan_a_gladys_con_su_comision_y_rastro(): void
    {
        $fv2_3 = $this->fv2(3, self::SEBASTIAN);
        $fv2_4 = $this->fv2(4, self::SEBASTIAN);
        $fv2_5 = $this->fv2(5, self::SEBASTIAN);
        $this->venta(self::GLADYS, '05', 12_000_000);

        $antes = $this->loQueCobraCadaUno();

        $this->pasarFv2AGladys();

        foreach ([$fv2_3, $fv2_4] as $o) {
            $this->assertSame(self::GLADYS, (int) $o->fresh()->vendedor_id);
            $this->assertSame(self::GLADYS, (int) Comision::where('orden_id', $o->id)->value('vendedor_id'));
            $this->assertSame(self::EDEN,   (int) Comision::where('orden_id', $o->id)->value('tienda_id'));
            $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', $o->id)->count(), 'queda en el historial');
        }
        // La FV2-5 no es de las dos: sigue siendo de Sebastián.
        $this->assertSame(self::SEBASTIAN, (int) $fv2_5->fresh()->vendedor_id);

        // A nadie le cambia lo que cobra: el pool se reparte por días. (Un
        // peso de diferencia es el redondeo de repartir entre más órdenes.)
        $despues = $this->loQueCobraCadaUno();
        $this->assertEqualsWithDelta($antes['Gladys'],    $despues['Gladys'],    2);
        $this->assertEqualsWithDelta($antes['Sebastián'], $despues['Sebastián'], 2);
    }

    public function test_correr_dos_veces_no_hace_nada_la_segunda(): void
    {
        $this->fv2(3, self::SEBASTIAN);
        $this->fv2(4, self::SEBASTIAN);

        $this->pasarFv2AGladys();
        $this->pasarFv2AGladys();

        $this->assertSame(2, DB::table('orden_ediciones')->count());
    }

    public function test_una_comision_ya_pagada_a_sebastian_no_se_mueve(): void
    {
        $fv2_3 = $this->fv2(3, self::SEBASTIAN);
        Comision::where('orden_id', $fv2_3->id)->update(['estado' => 'pagada', 'monto_comision' => 100]);

        $this->pasarFv2AGladys();

        $this->assertSame(self::GLADYS,    (int) $fv2_3->fresh()->vendedor_id, 'la orden sí');
        $this->assertSame(self::SEBASTIAN, (int) Comision::where('orden_id', $fv2_3->id)->value('vendedor_id'), 'lo pagado no');
    }
}
