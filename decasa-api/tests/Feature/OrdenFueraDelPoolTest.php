<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\TiendaAsesor;
use App\Models\TiendaReemplazo;
use App\Models\Usuario;
use App\Services\AnticiposComision;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Una orden "fuera del pool": no suma a la meta ni al pool y no paga comisión.
 *
 * El caso del dueño (2026-10-23): en Decasa Norte, Jessica —que no es del
 * equipo— vendió en la tienda y ya se le pagó aparte; su venta no debe armar
 * el pool de Norte. Aquí: Norte con meta de 10M y Paola + Martha en el equipo;
 * septiembre: Paola 8M, Martha 6M y Jessica 4M. El Edén, otra tienda, no se
 * puede mover.
 */
class OrdenFueraDelPoolTest extends TestCase
{
    private const NORTE = 1, EDEN = 2;
    private const PAOLA = 1, MARTHA = 2, JESSICA = 3, GLADYS = 4, JEFE = 50;

    private int $deJessica;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_comisiones')->default(false); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->string('comision_periodicidad')->default('mensual');
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
            $t->id(); $t->string('clave_unica')->nullable()->unique(); $t->string('forma_pago_pagada')->nullable();
            $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
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
            $t->boolean('fuera_del_pool')->default(false); $t->string('fuera_del_pool_motivo')->nullable();
            $t->unsignedBigInteger('fuera_del_pool_por')->nullable(); $t->timestamp('fuera_del_pool_at')->nullable();
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
            $t->decimal('deficit_inicial', 15, 2)->default(0); $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0); $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamp('cerrado_at')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });
        AnticiposComision::olvidarEsquema();
        \Illuminate\Support\Facades\Queue::fake();

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'comisiones_compartidas' => true],
            ['id' => self::EDEN, 'nombre' => 'Decasa El Edén', 'comisiones_compartidas' => true],
        ]);
        foreach ([self::NORTE, self::EDEN] as $tienda) {
            DB::table('metas_tienda')->insert(['tienda_id' => $tienda, 'mes' => '2026-09', 'meta' => 10_000_000,
                'divisor_asesores' => $tienda === self::NORTE ? 2 : 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[self::NORTE, self::PAOLA], [self::NORTE, self::MARTHA], [self::EDEN, self::GLADYS]] as [$tienda, $quien]) {
            DB::table('tienda_asesores_comision')->insert(['tienda_id' => $tienda, 'mes' => '2026-09',
                'vendedor_id' => $quien, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('usuarios')->insert([
            ['id' => self::PAOLA, 'nombre' => 'Paola', 'rol' => 'vendedor', 'acceso_comisiones' => false, 'tienda_default_id' => self::NORTE, 'created_at' => now()],
            ['id' => self::MARTHA, 'nombre' => 'Martha', 'rol' => 'vendedor', 'acceso_comisiones' => false, 'tienda_default_id' => self::NORTE, 'created_at' => now()],
            ['id' => self::JESSICA, 'nombre' => 'Jessica', 'rol' => 'vendedor', 'acceso_comisiones' => false, 'tienda_default_id' => null, 'created_at' => now()],
            ['id' => self::GLADYS, 'nombre' => 'Gladys', 'rol' => 'vendedor', 'acceso_comisiones' => false, 'tienda_default_id' => self::EDEN, 'created_at' => now()],
            ['id' => self::JEFE, 'nombre' => 'Jefe', 'rol' => 'supervisor', 'acceso_comisiones' => true, 'tienda_default_id' => null, 'created_at' => now()],
        ]);

        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();
        $this->prestarleASqliteLoQueEsDeMysql();

        $this->orden(self::NORTE, self::PAOLA, 8_000_000);
        $this->orden(self::NORTE, self::MARTHA, 6_000_000);
        $this->deJessica = $this->orden(self::NORTE, self::JESSICA, 4_000_000);
        $this->orden(self::EDEN, self::GLADYS, 15_000_000);

        // El 21 de octubre: septiembre ya se puede pagar.
        $this->travelTo(Carbon::parse('2026-10-21 10:00', 'America/Bogota'));
        app(ComisionController::class)->ponerAlDia();
    }

    private function orden(int $tienda, int $vendedor, float $valor): int
    {
        $orden = Orden::create(['tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'estado' => 'entregado', 'valor_total' => $valor]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => '2026-09-10 15:00:00']);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'precio_unitario' => $valor]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => '2026-09-10 15:00:00']);
        ComisionController::crearParaOrden($orden->fresh());

        return $orden->id;
    }

    private function jefe()
    {
        return $this->actingAs(Usuario::find(self::JEFE));
    }

    /** Lo listo por persona en septiembre: [nombre => monto]. */
    private function listo(): array
    {
        app(ComisionController::class)->ponerAlDia();

        return collect($this->jefe()->getJson('/api/comisiones/por-pagar?mes=2026-09')->assertOk()->json('personas'))
            ->mapWithKeys(fn ($p) => [$p['vendedor_nombre'] => $p['lista']])->all();
    }

    private function marcar(bool $fuera, ?string $motivo = 'Ya se le pagó aparte')
    {
        return $this->jefe()->patchJson("/api/comisiones/ordenes/{$this->deJessica}/fuera-del-pool",
            ['fuera' => $fuera, 'motivo' => $motivo]);
    }

    public function test_sin_marca_la_venta_de_alguien_de_afuera_arma_el_pool(): void
    {
        // 18M − 10M = 8M ÷ 1,19 × 5 % = 336.134, la mitad cada una; Jessica su 5 % aparte.
        $l = $this->listo();
        $this->assertEqualsWithDelta(168_067, $l['Paola'], 1);
        $this->assertEqualsWithDelta(168_067, $l['Martha'], 1);
        $this->assertEqualsWithDelta(168_067, $l['Jessica'], 1);
    }

    public function test_marcada_no_suma_al_pool_ni_paga_comision_y_la_otra_tienda_no_cambia(): void
    {
        $eden = $this->listo()['Gladys'];

        $this->marcar(true)->assertOk()->assertJsonPath('fuera_del_pool', true);

        // 14M − 10M = 4M ÷ 1,19 × 5 % = 168.067, la mitad cada una; Jessica nada.
        $l = $this->listo();
        $this->assertEqualsWithDelta(84_034, $l['Paola'], 1);
        $this->assertEqualsWithDelta(84_034, $l['Martha'], 1);
        $this->assertArrayNotHasKey('Jessica', $l);
        $this->assertSame($eden, $l['Gladys']);

        // La orden sigue siendo venta: solo su comisión queda en cero.
        $this->assertSame('entregado', Orden::find($this->deJessica)->estado);
        $this->assertEquals(0, Comision::where('orden_id', $this->deJessica)->value('monto_comision'));

        // Y la meta de Norte ya no la cuenta.
        $this->assertEquals(14_000_000, ComisionController::avanceDeMetas()[self::NORTE . '_2026-09']['cuenta']);

        // Queda pedido un recálculo y anotado el motivo.
        $this->assertSame('pedido', ComisionController::estadoDelRecalculo()['estado']);
        $this->assertSame('Ya se le pagó aparte', Orden::find($this->deJessica)->fuera_del_pool_motivo);
    }

    public function test_desmarcarla_la_devuelve_al_pool(): void
    {
        $this->marcar(true)->assertOk();
        $this->marcar(false, null)->assertOk()->assertJsonPath('fuera_del_pool', false);

        $l = $this->listo();
        $this->assertEqualsWithDelta(168_067, $l['Paola'], 1);
        $this->assertEqualsWithDelta(168_067, $l['Jessica'], 1);
        $this->assertNull(Orden::find($this->deJessica)->fuera_del_pool_motivo);
    }

    public function test_pide_motivo_y_solo_lo_hace_un_supervisor_con_acceso(): void
    {
        $this->marcar(true, null)->assertStatus(422);
        $this->marcar(true, '')->assertStatus(422);

        $this->actingAs(Usuario::find(self::PAOLA))
            ->patchJson("/api/comisiones/ordenes/{$this->deJessica}/fuera-del-pool", ['fuera' => true, 'motivo' => 'x'])
            ->assertStatus(403);

        $this->assertFalse((bool) Orden::find($this->deJessica)->fuera_del_pool);
    }

    public function test_con_una_comision_ya_pagada_no_se_puede(): void
    {
        Comision::where('orden_id', $this->deJessica)->update(['estado' => 'pagada', 'monto_comision' => 168_067, 'fecha_pago' => now()]);

        $this->marcar(true)->assertStatus(422);
        $this->assertFalse((bool) Orden::find($this->deJessica)->fuera_del_pool);
    }
}
