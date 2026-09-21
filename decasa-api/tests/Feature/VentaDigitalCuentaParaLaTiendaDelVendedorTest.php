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
 * Lo que se vende por WhatsApp, Instagram o la página cuenta para la tienda
 * de la persona, no para la tienda donde se registró la orden.
 *
 * Se nota en los reemplazos. Génesis, de Unicentro, cubre en Norte: lo que
 * venda en el mostrador es de Norte —empuja esa meta, entra a ese pool—, pero
 * lo que cierre por WhatsApp esos días es de Unicentro, como siempre. Manuela,
 * de Tienda Virtual, cubriendo allá cobra lo digital por fuera de la meta,
 * con las reglas de su tienda. Antes todo se le cargaba a Norte.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class VentaDigitalCuentaParaLaTiendaDelVendedorTest extends TestCase
{
    private const MES = '2026-09';

    private const NORTE = 1, UNICENTRO = 2, VIRTUAL = 3;

    /** Ids de la gente */
    private const GENESIS = 1, MANUELA = 2, PAOLA = 3, INDEPENDIENTE = 4;

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

        // Dos tiendas con meta y equipo, y Tienda Virtual sin ninguna de las dos.
        DB::table('tiendas')->insert([
            ['id' => self::NORTE,     'nombre' => 'Decasa Norte',   'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::UNICENTRO, 'nombre' => 'Unicentro',      'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::VIRTUAL,   'nombre' => 'Tienda Virtual', 'activa' => true, 'comisiones_compartidas' => false],
        ]);
        foreach ([self::NORTE, self::UNICENTRO] as $tienda) {
            DB::table('metas_tienda')->insert([
                'tienda_id' => $tienda, 'mes' => self::MES, 'meta' => 20_000_000,
                'divisor_asesores' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            [self::GENESIS,       'Génesis', self::UNICENTRO, false],
            [self::MANUELA,       'Manuela', self::VIRTUAL,   false],
            [self::PAOLA,         'Paola',   self::NORTE,     false],
            [self::INDEPENDIENTE, 'Indep',   null,            true],
        ] as [$id, $nombre, $tienda, $independiente]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor', 'independiente' => $independiente,
                'tienda_default_id' => $tienda, 'created_at' => now(),
            ]);
        }
        foreach ([[self::NORTE, self::PAOLA], [self::UNICENTRO, self::GENESIS]] as [$tienda, $quien]) {
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => $tienda, 'mes' => self::MES, 'vendedor_id' => $quien,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        ComisionController::olvidarQuienComparte();

        $this->prestarleASqliteLoQueEsDeMysql();
    }

    /** Alguien va a cubrir a Paola en Norte todo el mes. */
    private function cubreEnNorte(int $quien): void
    {
        DB::table('tienda_reemplazos')->insert([
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo', 'usuario_id' => $quien,
            'reemplaza_a_id' => self::PAOLA, 'desde' => self::MES . '-01', 'hasta' => self::MES . '-30',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Models\TiendaReemplazo::olvidarCache();
    }

    private function orden(int $vendedor, int $tienda, string $canal, float $valor): Orden
    {
        $orden = Orden::create([
            'tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'canal' => $canal,
            'estado' => 'entregado', 'valor_total' => $valor,
        ]);

        DB::table('ordenes')->where('id', $orden->id)
            ->update(['created_at' => self::MES . '-15 15:00:00']);
        DB::table('orden_items')->insert([
            'orden_id' => $orden->id, 'es_restauracion' => false, 'precio_unitario' => $valor,
        ]);
        DB::table('pagos')->insert([
            'orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => now(),
        ]);

        ComisionController::crearParaOrden($orden->fresh());
        ComisionController::sincronizarValorOrden($orden->fresh());

        return $orden->fresh();
    }

    private function tiendaDeLaComision(Orden $orden): int
    {
        return (int) Comision::where('orden_id', $orden->id)
            ->where('vendedor_id', $orden->vendedor_id)->value('tienda_id');
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
                \Carbon\Carbon::parse('2026-10-25'));

            $quien = $nombres[$c->vendedor_id];
            $out[$quien] = ($out[$quien] ?? 0) + (float) $f['monto_comision'];
        }

        return $out;
    }

    public function test_genesis_cubriendo_en_norte_lo_fisico_es_de_norte_y_lo_de_whatsapp_de_unicentro(): void
    {
        $this->cubreEnNorte(self::GENESIS);

        $mostrador = $this->orden(self::GENESIS, self::NORTE, 'fisica',   10_000_000);
        $whatsapp  = $this->orden(self::GENESIS, self::NORTE, 'whatsapp', 25_000_000);

        $this->assertSame(self::NORTE,     $this->tiendaDeLaComision($mostrador));
        $this->assertSame(self::UNICENTRO, $this->tiendaDeLaComision($whatsapp));

        // La orden sigue siendo de Norte: ahí se hizo, de ahí sale el mueble.
        $this->assertSame(self::NORTE, (int) $whatsapp->tienda_id);

        // Y así se cuenta contra cada meta.
        $ventas = ComisionController::ventasParaMeta();
        $this->assertEquals(10_000_000, $ventas[self::NORTE . '_' . self::MES]);
        $this->assertEquals(25_000_000, $ventas[self::UNICENTRO . '_' . self::MES]);
    }

    public function test_manuela_de_tienda_virtual_cobra_lo_digital_con_las_reglas_de_su_tienda(): void
    {
        $this->cubreEnNorte(self::MANUELA);

        $this->orden(self::MANUELA, self::NORTE, 'fisica',    5_000_000);
        $instagram = $this->orden(self::MANUELA, self::NORTE, 'instagram', 3_000_000);

        $this->assertSame(self::VIRTUAL, $this->tiendaDeLaComision($instagram));

        // Norte no llega a la meta con lo del mostrador, así que de ahí no
        // cobra nada por pool. Lo de Instagram es suyo: 3.000.000 ÷ 1,19 × 5%.
        $ventas = ComisionController::ventasParaMeta();
        $this->assertEquals(5_000_000, $ventas[self::NORTE . '_' . self::MES]);
        $this->assertEquals(3_000_000, $ventas[self::VIRTUAL . '_' . self::MES]);
        $this->assertEqualsWithDelta(126_050, $this->loQueCobraCadaUno()['Manuela'], 2);
    }

    public function test_un_independiente_sigue_con_la_tienda_de_la_orden(): void
    {
        $whatsapp = $this->orden(self::INDEPENDIENTE, self::NORTE, 'whatsapp', 2_000_000);

        $this->assertSame(self::NORTE, $this->tiendaDeLaComision($whatsapp));
    }

    public function test_una_orden_vieja_sin_canal_es_fisica(): void
    {
        $orden = Orden::create([
            'tienda_id' => self::NORTE, 'vendedor_id' => self::GENESIS, 'canal' => null,
            'estado' => 'entregado', 'valor_total' => 1_000_000,
        ]);
        ComisionController::crearParaOrden($orden->fresh());

        $this->assertSame(self::NORTE, $this->tiendaDeLaComision($orden));
    }

    public function test_recalcular_mueve_las_que_nacieron_antes_de_esta_regla(): void
    {
        $whatsapp = $this->orden(self::GENESIS, self::NORTE, 'whatsapp', 4_000_000);

        // Como quedó guardada antes: con la tienda de la orden.
        Comision::where('orden_id', $whatsapp->id)->update(['tienda_id' => self::NORTE]);

        $movidas = ComisionController::sincronizarValorOrden($whatsapp->fresh());

        $this->assertSame(1, $movidas);
        $this->assertSame(self::UNICENTRO, $this->tiendaDeLaComision($whatsapp));
    }

    public function test_lo_pagado_no_se_mueve(): void
    {
        $whatsapp = $this->orden(self::GENESIS, self::NORTE, 'whatsapp', 4_000_000);
        Comision::where('orden_id', $whatsapp->id)->update(['tienda_id' => self::NORTE, 'estado' => 'pagada']);

        ComisionController::sincronizarValorOrden($whatsapp->fresh());

        $this->assertSame(self::NORTE, $this->tiendaDeLaComision($whatsapp));
    }

    public function test_la_migracion_pone_al_dia_todo_el_historial_pagado_incluido(): void
    {
        $whatsapp  = $this->orden(self::GENESIS, self::NORTE, 'whatsapp', 4_000_000);
        $mostrador = $this->orden(self::GENESIS, self::NORTE, 'fisica',   1_000_000);
        $indep     = $this->orden(self::INDEPENDIENTE, self::NORTE, 'whatsapp', 1_000_000);

        // Como quedaron guardadas antes de la regla, y ya pagadas.
        Comision::query()->update(['tienda_id' => self::NORTE, 'estado' => 'pagada', 'monto_comision' => 100]);

        $migracion = require base_path('database/migrations/2026_10_02_000001_lo_digital_cuenta_para_la_tienda_del_vendedor.php');
        $migracion->up();

        $this->assertSame(self::UNICENTRO, $this->tiendaDeLaComision($whatsapp));
        $this->assertSame(self::NORTE,     $this->tiendaDeLaComision($mostrador));
        $this->assertSame(self::NORTE,     $this->tiendaDeLaComision($indep));

        // Lo pagado sigue pagado y con su monto: solo cambió a quién se le cuenta.
        $c = Comision::where('orden_id', $whatsapp->id)->first();
        $this->assertSame('pagada', $c->estado);
        $this->assertEquals(100, (float) $c->monto_comision);
    }
}
