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
 * Cuando el pago se atrasa y se juntan dos meses.
 *
 * El caso: El Edén (mensual, meta de 10M) con Juan solo en el equipo y un
 * anticipo de $200.000 al mes. Septiembre no se pagó el 20 de octubre; el 21
 * de noviembre ya toca octubre. Septiembre tiene que verse, ir primero y
 * descontar su propio anticipo, no el de octubre.
 */
class ComisionesAtrasadasTest extends TestCase
{
    private const EDEN = 1;
    private const JUAN = 1, JEFE = 50;

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
        \Illuminate\Support\Facades\Queue::fake();
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert(['id' => self::EDEN, 'nombre' => 'Decasa El Edén', 'comisiones_compartidas' => true]);
        foreach (['2026-09', '2026-10'] as $mes) {
            DB::table('metas_tienda')->insert([
                'tienda_id' => self::EDEN, 'mes' => $mes, 'meta' => 10_000_000,
                'divisor_asesores' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => self::EDEN, 'mes' => $mes, 'vendedor_id' => self::JUAN,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('usuarios')->insert([
            ['id' => self::JUAN, 'nombre' => 'Juan', 'rol' => 'vendedor', 'acceso_comisiones' => false,
             'tienda_default_id' => self::EDEN, 'created_at' => now()],
            ['id' => self::JEFE, 'nombre' => 'Jefe', 'rol' => 'supervisor', 'acceso_comisiones' => true,
             'tienda_default_id' => null, 'created_at' => now()],
        ]);
        DB::table('comision_anticipos_config')->insert([
            'vendedor_id' => self::JUAN, 'desde_mes' => '2026-09', 'monto' => 200_000, 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();
        $this->prestarleASqliteLoQueEsDeMysql();

        // Septiembre: 20M contra 10M → (10M ÷ 1,19) × 5 % = 420.168.
        // Octubre:    15M contra 10M → ( 5M ÷ 1,19) × 5 % = 210.084.
        $this->orden('2026-09-10', 20_000_000);
        $this->orden('2026-10-10', 15_000_000);

        // El 21 de noviembre: septiembre debía pagarse el 20 de octubre.
        $this->travelTo(Carbon::parse('2026-11-21 10:00', 'America/Bogota'));
        app(ComisionController::class)->ponerAlDia();
    }

    private function orden(string $fecha, float $valor): void
    {
        $orden = Orden::create(['tienda_id' => self::EDEN, 'vendedor_id' => self::JUAN,
            'estado' => 'entregado', 'valor_total' => $valor]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => $fecha . ' 15:00:00']);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'precio_unitario' => $valor]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => $fecha . ' 15:00:00']);

        ComisionController::crearParaOrden($orden->fresh());
    }

    private function jefe()
    {
        return $this->actingAs(Usuario::find(self::JEFE));
    }

    public function test_listas_trae_los_dos_meses_con_septiembre_primero_y_marcado_atrasado(): void
    {
        $r = $this->jefe()->getJson('/api/comisiones/por-pagar')->assertOk()->json();

        $this->assertSame('2026-10', $r['mes_que_toca']);
        $this->assertCount(1, $r['personas']);
        $juan = $r['personas'][0];
        $this->assertTrue($juan['atrasada']);
        $this->assertSame(['2026-09', '2026-10'], array_column($juan['bloques'], 'mes'));

        [$sep, $oct] = $juan['bloques'];
        $this->assertTrue($sep['atrasada']);
        $this->assertSame('2026-10-20', $sep['se_cobra_el']);
        $this->assertFalse($oct['atrasada']);
        $this->assertEqualsWithDelta(420_168, $sep['lista'], 2);
        $this->assertEqualsWithDelta(210_084, $oct['lista'], 2);

        // Cada mes descuenta su anticipo, no los dos el de septiembre.
        $this->assertEquals(200_000, $sep['se_descuenta']);
        $this->assertEquals(200_000, $oct['se_descuenta']);
        $this->assertEquals(400_000, $juan['se_descuenta']);
        $this->assertEqualsWithDelta(630_252 - 400_000, $juan['entregar'], 3);

        $this->assertSame('2026-09', $r['atrasados'][0]['mes']);
        $this->assertCount(1, $r['atrasados']);
    }

    public function test_un_solo_mes_si_se_pide(): void
    {
        $r = $this->jefe()->getJson('/api/comisiones/por-pagar?mes=2026-10')->assertOk()->json();

        $this->assertSame(['2026-10'], array_column($r['personas'][0]['bloques'], 'mes'));
    }

    public function test_pagar_octubre_con_septiembre_sin_pagar_se_frena(): void
    {
        $this->jefe()->postJson('/api/comisiones/pagar-listas', [
            'vendedor_id' => self::JUAN, 'mes' => '2026-10', 'tienda_id' => self::EDEN,
        ])->assertStatus(409)->assertJsonPath('meses_anteriores', ['2026-09']);

        $this->assertSame(0, Comision::where('estado', 'pagada')->count());

        // Pagado septiembre, octubre ya se puede pagar suelto.
        $this->jefe()->postJson('/api/comisiones/pagar-listas', [
            'vendedor_id' => self::JUAN, 'mes' => '2026-09', 'tienda_id' => self::EDEN,
        ])->assertOk();
        $this->jefe()->postJson('/api/comisiones/pagar-listas', [
            'vendedor_id' => self::JUAN, 'mes' => '2026-10', 'tienda_id' => self::EDEN,
        ])->assertOk();
    }

    public function test_pagar_los_dos_meses_va_del_mas_viejo_al_mas_nuevo_con_un_recibo_por_mes(): void
    {
        // Llegan al revés: igual se paga septiembre primero.
        $r = $this->jefe()->postJson('/api/comisiones/pagar-meses', [
            'vendedor_id' => self::JUAN,
            'bloques' => [['mes' => '2026-10', 'tienda_id' => self::EDEN], ['mes' => '2026-09', 'tienda_id' => self::EDEN]],
        ])->assertOk()->json();

        $this->assertSame(['2026-09', '2026-10'], array_column($r['recibos'], 'mes'));
        [$sep, $oct] = $r['recibos'];
        $this->assertEquals(200_000, $sep['anticipos_descontados']);
        $this->assertEquals(200_000, $oct['anticipos_descontados']);
        $this->assertEqualsWithDelta(220_168, $sep['neto'], 2);
        $this->assertEqualsWithDelta(10_084, $oct['neto'], 2);
        $this->assertEqualsWithDelta(230_252, $r['neto'], 3);
        $this->assertSame('Decasa El Edén', $sep['tienda_nombre']);

        // Debe lo de noviembre, que es de la comisión de noviembre.
        $this->assertEquals(0, AnticiposComision::debeHasta(self::JUAN, '2026-10'));
        $this->assertSame(0, Comision::where('estado', '!=', 'pagada')->where('mes_venta', '<=', '2026-10')
            ->where('valor_orden', '>', 0)->count());

        // Y ya no queda nada en Listas.
        $this->assertSame([], $this->jefe()->getJson('/api/comisiones/por-pagar')->json('personas'));
    }

    public function test_pagar_meses_sin_el_mas_viejo_se_frena(): void
    {
        $this->jefe()->postJson('/api/comisiones/pagar-meses', [
            'vendedor_id' => self::JUAN, 'bloques' => [['mes' => '2026-10', 'tienda_id' => self::EDEN]],
        ])->assertStatus(409);

        $this->assertSame(0, Comision::where('estado', 'pagada')->count());
    }

    public function test_el_aviso_del_20_dice_lo_que_quedo_atrasado(): void
    {
        // La tarea de la mañana ya mandó el aviso: se borra para mirarlo de nuevo.
        DB::table('notificaciones')->delete();

        $this->travelTo(Carbon::parse('2026-11-20 07:00', 'America/Bogota'));
        $aviso = app(ComisionController::class)->avisarDiaDePago();

        $this->assertSame('2026-10', $aviso['mes']);
        $mensaje = DB::table('notificaciones')->value('mensaje');
        $this->assertStringContainsString('Además quedan sin pagar', $mensaje);
        $this->assertStringContainsString('septiembre de 2026', $mensaje);
    }

    // ── Abono: una parte de un mes que ya está listo ─────────────────────────
    //
    // El caso del dueño (2026-10-19): pasó el día de pago y a los vendedores
    // les dieron un abono, no todo. Septiembre: 420.168 de comisión y 200.000
    // de anticipo fijo → faltaban 220.168. Le dan 100.000 de abono.

    private function abonar(float $monto, string $mes = '2026-09')
    {
        return $this->jefe()->postJson('/api/comisiones/abonos', [
            'vendedor_id' => self::JUAN, 'mes' => $mes, 'monto' => $monto, 'nota' => 'Abono del 18 de octubre',
        ]);
    }

    public function test_un_abono_baja_lo_que_falta_en_listas_y_al_pagar_se_entrega_el_resto(): void
    {
        $this->abonar(100_000)->assertOk()->assertJsonPath('queda', 120_168);

        $sep = $this->jefe()->getJson('/api/comisiones/por-pagar')->json('personas.0.bloques.0');
        $this->assertEqualsWithDelta(420_168, $sep['lista'], 2);   // la comisión no cambia
        $this->assertEquals(300_000, $sep['se_descuenta']);        // anticipo + abono
        $this->assertEquals(100_000, $sep['abonos']);
        $this->assertEqualsWithDelta(120_168, $sep['entregar'], 2);

        // El anticipo fijo de septiembre sigue ahí: el abono no lo pisa, ni al revés.
        AnticiposComision::asegurarHasta('2026-11');
        $this->assertEquals([100_000, 200_000], DB::table('comision_anticipos')->where('tipo', 'anticipo')
            ->where('mes', '2026-09')->orderBy('monto')->pluck('monto')->map(fn ($m) => (float) $m)->all());

        $recibo = $this->jefe()->postJson('/api/comisiones/pagar-listas', [
            'vendedor_id' => self::JUAN, 'mes' => '2026-09', 'tienda_id' => self::EDEN,
        ])->assertOk()->json();
        $this->assertEqualsWithDelta(420_168, $recibo['monto'], 2);
        $this->assertEquals(300_000, $recibo['anticipos_descontados']);
        $this->assertEqualsWithDelta(120_168, $recibo['neto'], 2);

        // Octubre descuenta solo su anticipo: el abono era de septiembre.
        $oct = $this->jefe()->getJson('/api/comisiones/por-pagar')->json('personas.0.bloques.0');
        $this->assertSame('2026-10', $oct['mes']);
        $this->assertEquals(200_000, $oct['se_descuenta']);
        $this->assertEquals(0, $oct['abonos']);
    }

    public function test_el_abono_no_puede_pasar_de_lo_que_falta_ni_ser_de_un_mes_sin_nada_listo(): void
    {
        $this->abonar(220_169)->assertStatus(422);
        $this->abonar(100_000, '2026-08')->assertStatus(422);

        // Dos abonos del mismo mes suman, y el segundo tiene de tope lo que quedó.
        $this->abonar(100_000)->assertOk();
        $this->abonar(120_169)->assertStatus(422);
        $this->abonar(120_168)->assertOk()->assertJsonPath('queda', 0);
    }

    public function test_un_abono_por_error_se_quita_mientras_no_se_haya_pagado(): void
    {
        $id = $this->abonar(100_000)->json('id');

        $this->jefe()->deleteJson("/api/comisiones/abonos/{$id}")->assertOk();
        $this->assertEquals(200_000, $this->jefe()->getJson('/api/comisiones/por-pagar')
            ->json('personas.0.bloques.0.se_descuenta'));

        $id = $this->abonar(100_000)->json('id');
        $this->jefe()->postJson('/api/comisiones/pagar-listas', [
            'vendedor_id' => self::JUAN, 'mes' => '2026-09', 'tienda_id' => self::EDEN,
        ])->assertOk();
        $this->jefe()->deleteJson("/api/comisiones/abonos/{$id}")->assertStatus(422);

        // El anticipo fijo no se quita por aquí.
        $fijo = DB::table('comision_anticipos')->where('clave', AnticiposComision::claveDe(self::JUAN, '2026-10'))->value('id');
        $this->jefe()->deleteJson("/api/comisiones/abonos/{$fijo}")->assertStatus(404);
    }

    public function test_un_vendedor_no_registra_abonos(): void
    {
        $this->actingAs(Usuario::find(self::JUAN))->postJson('/api/comisiones/abonos', [
            'vendedor_id' => self::JUAN, 'mes' => '2026-09', 'monto' => 100_000,
        ])->assertStatus(403);
    }

    public function test_en_finanzas_el_abono_sale_de_caja_el_dia_que_se_dio(): void
    {
        $this->abonar(100_000)->assertOk();   // 21 de noviembre

        $caja = \App\Services\Finanzas\FuenteComisiones::cajaPorMes('2026-09', '2026-11');
        $this->assertEquals(200_000, $caja['2026-09']['anticipos']);
        $this->assertEquals(300_000, $caja['2026-11']['anticipos']);   // anticipo de noviembre + abono
    }

    public function test_el_historial_de_anticipos_marca_el_abono(): void
    {
        $this->abonar(100_000)->assertOk();

        $movs = collect($this->jefe()->getJson('/api/comisiones/anticipos')->json('0.movimientos'));
        $abono = $movs->firstWhere('es_abono', true);
        $this->assertEquals(100_000, $abono['monto']);
        $this->assertSame('2026-09', $abono['mes']);
        $this->assertArrayNotHasKey('clave', $abono);
    }
}
