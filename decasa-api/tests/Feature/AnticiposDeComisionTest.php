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
use App\Services\AnticiposComision;
use Tests\TestCase;
/**
 * Anticipos de comisión: lo que se llevan cada mes por adelantado y se les
 * descuenta al pagarles. El escenario es Unicentro Pereira (trimestral, meta
 * de 10M al mes, Juan en el equipo), donde más se usa.
 */
class AnticiposDeComisionTest extends TestCase
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
        Schema::create('tiendas', function (Blueprint $t) { $t->string('comision_periodicidad')->default('mensual');
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
        Schema::create('comisiones', function (Blueprint $t) { $t->string('clave_unica')->nullable()->unique(); $t->string('forma_pago_pagada')->nullable();
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

        // Trimestral por lo que dice la tienda, no por su nombre.
        DB::table('tiendas')->insert([
            'id' => self::PEREIRA, 'nombre' => 'Decasa Unicentro Pereira', 'comision_periodicidad' => 'trimestral',
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

    // ─────────── Anticipos ───────────

    private function montarAnticipos(): void
    {
        Schema::create('comision_anticipos_config', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vendedor_id'); $t->char('desde_mes', 7);
            $t->decimal('monto', 15, 2)->default(0); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamps();
            $t->unique(['vendedor_id', 'desde_mes']);
        });
        Schema::create('comision_anticipos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vendedor_id'); $t->string('tipo'); $t->char('mes', 7);
            $t->decimal('monto', 15, 2); $t->string('clave')->nullable()->unique();
            $t->unsignedBigInteger('comision_id')->nullable(); $t->boolean('editado_a_mano')->default(false);
            $t->string('nota')->nullable(); $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamps();
        });
        AnticiposComision::olvidarEsquema();
    }

    private function conAnticipo(int $vendedorId, float $monto, string $desde): void
    {
        DB::table('comision_anticipos_config')->insert([
            'vendedor_id' => $vendedorId, 'desde_mes' => $desde, 'monto' => $monto, 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Paga todo lo listo, como el botón. Devuelve [pagado, descontado]. */
    private function pagarTodo(): array
    {
        $ctrl = app(ComisionController::class);
        foreach (['2026-07', '2026-08', '2026-09'] as $mes) $this->abrirRenglonesDe($mes);
        [$metas, $tt, $tv] = (new \ReflectionMethod($ctrl, 'cargarTotales'))->invoke($ctrl);
        $pools = (new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales'))->invoke($ctrl, $metas, $tt, false);
        $quien = \App\Models\Usuario::find(self::JUAN);
        $pagado = 0.0;
        $ids = [];
        foreach (Comision::with('orden.pagos', 'tienda')->where('estado', '!=', 'pagada')->get() as $c) {
            $f = (new \ReflectionMethod($ctrl, 'enriquecer'))->invoke($ctrl, $c, $metas, $tt, $tv, $pools, ComisionController::hoy());
            if ($f['estado_calculado'] !== 'lista') continue;
            (new \ReflectionMethod($ctrl, 'registrarPago'))->invoke($ctrl, $c, $f, $quien);
            $pagado += (float) $f['monto_comision'];
            $ids[] = $c->id;
        }
        return [$pagado, AnticiposComision::descontadoDe($ids)];
    }

    public function test_pereira_se_lleva_200_mil_al_mes_y_al_pagar_el_trimestre_se_le_restan_600(): void
    {
        $this->montarAnticipos();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');

        // Cada mes 20M contra 10M: pool del trimestre 1.260.504.
        $this->orden(self::JUAN, '2026-07-10', 20_000_000);
        $this->orden(self::JUAN, '2026-08-10', 20_000_000);
        $this->orden(self::JUAN, '2026-09-10', 20_000_000);

        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 10:00', 'America/Bogota'));
        [$pagado, $descontado] = $this->pagarTodo();

        $this->assertEqualsWithDelta(1_260_504, $pagado, 3);
        $this->assertEquals(600_000, $descontado, 'jul + ago + sep');
        // El de octubre ya se lo llevó, pero es de la comisión de octubre.
        $this->assertEquals(200_000, AnticiposComision::debeHasta(self::JUAN, '2026-10'));
        $this->assertEquals(0, AnticiposComision::debeHasta(self::JUAN, '2026-09'));
    }

    public function test_si_no_comisiona_lo_sigue_debiendo(): void
    {
        $this->montarAnticipos();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');

        // 5M + 10M + 10M contra 30M: no cubre, no comisiona.
        $this->orden(self::JUAN, '2026-07-10', 5_000_000);
        $this->orden(self::JUAN, '2026-08-10', 10_000_000);
        $this->orden(self::JUAN, '2026-09-10', 10_000_000);

        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 10:00', 'America/Bogota'));
        [$pagado, $descontado] = $this->pagarTodo();

        $this->assertEquals(0, $pagado);
        $this->assertEquals(0, $descontado);
        $this->assertEquals(600_000, AnticiposComision::debeHasta(self::JUAN, '2026-09'));
    }

    public function test_si_la_comision_no_alcanza_se_descuenta_lo_que_hay_y_debe_el_resto(): void
    {
        $this->montarAnticipos();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');

        // 10M + 10M + 15M contra 30M: +5M → 210.084, menos que los 600 mil.
        $this->orden(self::JUAN, '2026-07-10', 10_000_000);
        $this->orden(self::JUAN, '2026-08-10', 10_000_000);
        $this->orden(self::JUAN, '2026-09-10', 15_000_000);

        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 10:00', 'America/Bogota'));
        [$pagado, $descontado] = $this->pagarTodo();

        $this->assertEqualsWithDelta(210_084, $pagado, 2);
        $this->assertEqualsWithDelta($pagado, $descontado, 0.01, 'se descuenta todo lo que hay: le entregan $0');
        $this->assertEqualsWithDelta(600_000 - $pagado, AnticiposComision::debeHasta(self::JUAN, '2026-09'), 1);
    }

    public function test_un_mes_que_se_llevo_otra_cantidad_se_corrige_y_no_lo_pisa_la_configuracion(): void
    {
        $this->montarAnticipos();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 10:00', 'America/Bogota'));
        AnticiposComision::asegurarHasta('2026-10');

        // En agosto solo tomó 100 mil.
        DB::table('comision_anticipos')->where('clave', AnticiposComision::claveDe(self::JUAN, '2026-08'))
            ->update(['monto' => 100_000, 'editado_a_mano' => true]);
        AnticiposComision::asegurarHasta('2026-10');

        $this->assertEquals(500_000, AnticiposComision::debeHasta(self::JUAN, '2026-09'));
    }

    public function test_cambiar_el_monto_vale_de_ese_mes_en_adelante_y_apagarlo_tambien(): void
    {
        $this->montarAnticipos();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');
        $this->conAnticipo(self::JUAN, 300_000, '2026-11');
        DB::table('comision_anticipos_config')->insert([
            'vendedor_id' => self::JUAN, 'desde_mes' => '2027-01', 'monto' => 0, 'activo' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->travelTo(\Carbon\Carbon::parse('2027-02-05 10:00', 'America/Bogota'));
        AnticiposComision::asegurarHasta('2027-02');

        $porMes = DB::table('comision_anticipos')->where('tipo', 'anticipo')->pluck('monto', 'mes')->map(fn ($v) => (float) $v)->all();
        $this->assertEquals(['2026-07' => 200_000, '2026-08' => 200_000, '2026-09' => 200_000, '2026-10' => 200_000,
                             '2026-11' => 300_000, '2026-12' => 300_000], $porMes);
    }

    // ─────────── El aviso del día de pago ───────────

    private function conSupervisor(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Schema::table('usuarios', fn ($t) => $t->boolean('acceso_comisiones')->default(false));
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });
        DB::table('usuarios')->insert(['id' => 50, 'nombre' => 'Jefe', 'rol' => 'supervisor',
            'acceso_comisiones' => true, 'created_at' => now()]);
    }

    public function test_el_20_llega_un_solo_aviso_con_el_total_a_pagar(): void
    {
        $this->montarAnticipos();
        $this->conSupervisor();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');
        $this->orden(self::JUAN, '2026-07-10', 20_000_000);
        $this->orden(self::JUAN, '2026-08-10', 20_000_000);
        $this->orden(self::JUAN, '2026-09-10', 20_000_000);

        // Antes del 20 no se avisa nada.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-15 07:00', 'America/Bogota'));
        $this->assertNull(app(ComisionController::class)->avisarDiaDePago());

        // El 20: el trimestre entero de Pereira (jul + ago + sep), menos los
        // 600 mil de anticipos.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-20 07:00', 'America/Bogota'));
        $aviso = app(ComisionController::class)->avisarDiaDePago();

        $this->assertSame('2026-09', $aviso['mes']);
        $this->assertSame(1, $aviso['personas']);
        $this->assertEqualsWithDelta(1_260_504, $aviso['total'], 3);
        $this->assertEquals(600_000, $aviso['anticipos']);
        $this->assertEqualsWithDelta(660_504, $aviso['entregar'], 3);
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', 50)->count());

        // Una sola vez: la tarea del día siguiente no lo repite.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 07:00', 'America/Bogota'));
        $this->assertNull(app(ComisionController::class)->avisarDiaDePago());
        $this->assertSame(1, DB::table('notificaciones')->count());
    }

    public function test_deshacer_el_pago_devuelve_el_descuento(): void
    {
        $this->montarAnticipos();
        $this->conAnticipo(self::JUAN, 200_000, '2026-07');
        $this->orden(self::JUAN, '2026-07-10', 20_000_000);
        $this->orden(self::JUAN, '2026-08-10', 20_000_000);
        $this->orden(self::JUAN, '2026-09-10', 20_000_000);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 10:00', 'America/Bogota'));
        $this->pagarTodo();
        $this->assertEquals(0, AnticiposComision::debeHasta(self::JUAN, '2026-09'));

        foreach (Comision::where('estado', 'pagada')->get() as $c) {
            AnticiposComision::revertir($c);
        }

        $this->assertEquals(600_000, AnticiposComision::debeHasta(self::JUAN, '2026-09'));
    }
}
