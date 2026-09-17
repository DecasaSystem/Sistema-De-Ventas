<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\MetaTienda;
use App\Models\Tienda;
use App\Models\TiendaAsesor;
use App\Models\TiendaReemplazo;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cambiarle la sede a alguien lo traslada solo en Comisiones, y una tienda
 * cerrada deja de arrastrar meta y equipo.
 *
 * El caso real: Circunvalar cerró el 27 de agosto y Genesis pasó a Unicentro.
 * En el perfil se le cambió la tienda, pero Comisiones no se enteraba: había
 * que registrar el traslado a mano, y al mes siguiente moverla de equipo a
 * mano. Y aunque se hiciera, Circunvalar seguía arrastrando su meta de $40M a
 * septiembre —$0 vendido contra $40M, que en una tienda trimestral se come el
 * trimestre— y a Genesis en su equipo, pesando 30 días en una tienda cerrada.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CambioDeSedeTest extends TestCase
{
    private const CIRCUNVALAR = 5;
    private const UNICENTRO   = 4;
    private const GENESIS     = 14;
    private const JUAN        = 26;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); $t->date('cerrada_en')->nullable();
            $t->boolean('comisiones_compartidas')->default(true);
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable(); $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('independiente')->default(false); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes', 7); $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable(); $t->string('nota')->nullable(); $t->timestamps();
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->string('tipo')->default('venta');
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
        });

        DB::table('tiendas')->insert([
            ['id' => self::UNICENTRO,   'nombre' => 'Decasa Unicentro Pereira'],
            ['id' => self::CIRCUNVALAR, 'nombre' => 'Decasa Circunvalar'],
        ]);
        DB::table('usuarios')->insert([
            ['id' => self::GENESIS, 'nombre' => 'Genesis', 'tienda_default_id' => self::CIRCUNVALAR],
            ['id' => self::JUAN,    'nombre' => 'Juan',    'tienda_default_id' => self::UNICENTRO],
        ]);
        // Equipos y metas cargados en julio, como en la realidad; agosto y
        // septiembre los arrastran.
        DB::table('tienda_asesores_comision')->insert([
            ['tienda_id' => self::CIRCUNVALAR, 'mes' => '2026-07', 'vendedor_id' => self::GENESIS],
            ['tienda_id' => self::UNICENTRO,   'mes' => '2026-07', 'vendedor_id' => self::JUAN],
        ]);
        DB::table('metas_tienda')->insert([
            ['tienda_id' => self::CIRCUNVALAR, 'mes' => '2026-07', 'meta' => 40_000_000],
            ['tienda_id' => self::UNICENTRO,   'mes' => '2026-07', 'meta' => 40_000_000],
        ]);

        Carbon::setTestNow('2026-08-27 10:00:00');
        TiendaReemplazo::olvidarCache();
        TiendaAsesor::olvidarCache();
        Tienda::olvidarCerradas();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function equipo(int $tienda, string $mes): array
    {
        TiendaAsesor::olvidarCache();
        return collect(TiendaAsesor::vigentesEn($mes)[$tienda] ?? [])
            ->pluck('vendedor_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    private function pesos(int $tienda, string $mes): array
    {
        TiendaReemplazo::olvidarCache();
        return TiendaReemplazo::pesosDelMes($tienda, $mes, $this->equipo($tienda, $mes));
    }

    public function test_cambiar_de_sede_a_alguien_del_equipo_lo_traslada_solo(): void
    {
        $res = ComisionController::trasladarPorCambioDeSede(Usuario::find(self::GENESIS), self::CIRCUNVALAR, self::UNICENTRO);

        $this->assertTrue($res['traslado']);

        // Este mes: un traslado de hoy a fin de mes, como se hacía a mano.
        $t = TiendaReemplazo::first();
        $this->assertSame(TiendaReemplazo::TRASLADO, $t->tipo);
        $this->assertSame(self::UNICENTRO, (int) $t->tienda_id);
        $this->assertNull($t->reemplaza_a_id);
        $this->assertSame('2026-08-27', $t->desde->toDateString());
        $this->assertSame('2026-08-31', $t->hasta->toDateString());

        // Agosto se reparte por días: 26 en Circunvalar, 5 en Unicentro.
        $this->assertSame([self::GENESIS => 26], $this->pesos(self::CIRCUNVALAR, '2026-08'));
        $this->assertSame([self::JUAN => 31, self::GENESIS => 5], $this->pesos(self::UNICENTRO, '2026-08'));

        // Desde septiembre está en el equipo de Unicentro y fuera del de Circunvalar.
        $this->assertSame([self::GENESIS, self::JUAN], $this->equipo(self::UNICENTRO, '2026-09'));
        $this->assertSame(0, TiendaAsesor::where('tienda_id', self::CIRCUNVALAR)->where('mes', '2026-09')->count());
        // Agosto no se tocó: las listas de este mes ya llevan sus días.
        $this->assertSame([self::JUAN], $this->equipo(self::UNICENTRO, '2026-08'));
        $this->assertSame([self::GENESIS], $this->equipo(self::CIRCUNVALAR, '2026-08'));
        // Y el divisor de la meta de septiembre sigue al equipo.
        $this->assertSame(2, (int) MetaTienda::where('tienda_id', self::UNICENTRO)->where('mes', '2026-09')->value('divisor_asesores'));
    }

    public function test_a_quien_no_estaba_en_ningun_equipo_no_le_mueve_nada(): void
    {
        // Un supervisor con sede en una tienda no está en su reparto.
        DB::table('usuarios')->insert(['id' => 99, 'nombre' => 'Jefa', 'tienda_default_id' => self::CIRCUNVALAR]);

        $this->assertNull(ComisionController::trasladarPorCambioDeSede(Usuario::find(99), self::CIRCUNVALAR, self::UNICENTRO));
        $this->assertSame(0, TiendaReemplazo::count());
        $this->assertSame([self::JUAN], $this->equipo(self::UNICENTRO, '2026-09'));
    }

    public function test_si_ya_tenia_un_movimiento_este_mes_no_le_pone_otro_pero_si_cambia_el_equipo(): void
    {
        // Ya lo habían registrado a mano.
        TiendaReemplazo::create(['tienda_id' => self::UNICENTRO, 'tipo' => TiendaReemplazo::TRASLADO,
                                 'usuario_id' => self::GENESIS, 'desde' => '2026-08-27', 'hasta' => '2026-08-31']);

        $res = ComisionController::trasladarPorCambioDeSede(Usuario::find(self::GENESIS), self::CIRCUNVALAR, self::UNICENTRO);

        $this->assertFalse($res['traslado']);
        $this->assertSame(1, TiendaReemplazo::count());
        $this->assertSame([self::GENESIS, self::JUAN], $this->equipo(self::UNICENTRO, '2026-09'));
    }

    public function test_una_tienda_cerrada_no_arrastra_meta_ni_equipo_a_los_meses_de_despues(): void
    {
        Tienda::where('id', self::CIRCUNVALAR)->update(['activa' => false, 'cerrada_en' => '2026-08-27']);
        Tienda::olvidarCerradas();

        // El mes del cierre sigue como estaba: ahí vendió y su gente estuvo.
        $this->assertArrayHasKey(self::CIRCUNVALAR, MetaTienda::vigentesEn('2026-08'));
        $this->assertSame([self::GENESIS], $this->equipo(self::CIRCUNVALAR, '2026-08'));

        // Desde el siguiente, nada: ni meta que reste ni gente que pese.
        $this->assertArrayNotHasKey(self::CIRCUNVALAR, MetaTienda::vigentesEn('2026-09'));
        $mapa = MetaTienda::mapaVigente(['2026-08', '2026-09']);
        $this->assertArrayHasKey(self::CIRCUNVALAR . '_2026-08', $mapa);
        $this->assertArrayNotHasKey(self::CIRCUNVALAR . '_2026-09', $mapa);
        $this->assertSame([], $this->equipo(self::CIRCUNVALAR, '2026-09'));
        $this->assertSame([], $this->pesos(self::CIRCUNVALAR, '2026-09'));

        // Las demás siguen igual.
        $this->assertArrayHasKey(self::UNICENTRO, MetaTienda::vigentesEn('2026-09'));
        $this->assertSame([self::JUAN], $this->equipo(self::UNICENTRO, '2026-09'));
    }

    public function test_cerrar_la_tienda_desde_su_pantalla_le_pone_la_fecha(): void
    {
        Schema::table('tiendas', fn (Blueprint $t) => $t->boolean('es_fabrica')->default(false));
        Schema::table('tiendas', fn (Blueprint $t) => $t->boolean('es_independientes')->default(false));
        Schema::table('tiendas', fn (Blueprint $t) => $t->string('ciudad')->nullable());
        Schema::table('tiendas', fn (Blueprint $t) => $t->string('direccion')->nullable());
        Schema::table('tiendas', fn (Blueprint $t) => $t->string('telefono')->nullable());
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id'); $t->integer('cantidad_disponible')->default(0);
        });
        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Jefa', 'rol' => 'supervisor']);

        // Tiene gente asociada: se desactiva en vez de borrarse.
        $this->actingAs(Usuario::find(1))
            ->deleteJson('/api/tiendas/' . self::CIRCUNVALAR)
            ->assertOk()
            ->assertJsonPath('desactivada', true);

        $tienda = Tienda::find(self::CIRCUNVALAR);
        $this->assertFalse($tienda->activa);
        $this->assertSame('2026-08-27', $tienda->cerrada_en->toDateString());
    }
}
