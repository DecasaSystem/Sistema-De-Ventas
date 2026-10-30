<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use App\Models\TiendaAsesor;
use App\Models\TiendaReemplazo;
use App\Models\Usuario;
use App\Support\ReglasComision;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las reglas de comisión con fecha (Fase 1 de docs/plan-comisiones-personalizables.md).
 *
 * Dueño, 2026-10-27: las cambia solo quien tiene el permiso aparte; por tienda
 * y por persona (gana la más específica); hacia un mes pasado solo si ese mes
 * no tiene pagos y con confirmación. Cada mes se calcula con lo que regía
 * entonces y lo pagado no se mueve.
 *
 * Mismo escenario que FeriaSinMetaTest: Norte (con meta y equipo, Paola) y una
 * feria sin meta (Laura). Las cifras esperadas están sacadas a mano.
 */
class ReglasDeComisionTest extends TestCase
{
    private const MES   = '2026-10';
    private const NORTE = 1;
    private const FERIA = 2;
    private const PAOLA = 10;
    private const LAURA = 20;
    private const JEFE  = 30;   // supervisor con el permiso de reglas
    private const OTRO  = 31;   // supervisor sin el permiso

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_comisiones')->default(false); $t->boolean('acceso_reglas_comision')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('es_independientes')->default(false);
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
            $t->boolean('notificado_lista')->default(false); $t->json('reglas_usadas')->nullable(); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable(); $t->string('canal')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('en_produccion'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->boolean('sin_descontar_iva')->default(false);
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
        // La tabla de la migración 2026_11_02_000001_reglas_de_comision.
        Schema::create('comision_reglas', function (Blueprint $t) {
            $t->id(); $t->string('clave', 50); $t->string('alcance', 10); $t->unsignedBigInteger('alcance_id')->nullable();
            $t->json('valor')->nullable(); $t->char('desde_mes', 7); $t->string('nota', 200)->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamps();
            $t->unique(['clave', 'alcance', 'alcance_id', 'desde_mes']);
        });

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::FERIA, 'nombre' => 'Feria Expo', 'activa' => true, 'comisiones_compartidas' => false],
        ]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => self::NORTE, 'mes' => self::MES, 'meta' => 40_000_000,
            'divisor_asesores' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[self::PAOLA, 'Paola', 'vendedor', self::NORTE, false], [self::LAURA, 'Laura', 'vendedor', self::FERIA, false],
                  [self::JEFE, 'Jefe', 'supervisor', null, true], [self::OTRO, 'Otro', 'supervisor', null, false]] as [$id, $nombre, $rol, $tienda, $reglas]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => $rol, 'tienda_default_id' => $tienda,
                'acceso_comisiones' => $rol === 'supervisor', 'acceso_reglas_comision' => $reglas,
                'email' => "$id@d.com", 'password' => 'x', 'created_at' => now(),
            ]);
        }
        DB::table('tienda_asesores_comision')->insert([
            'tienda_id' => self::NORTE, 'mes' => self::MES, 'vendedor_id' => self::PAOLA,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();
        ComisionController::olvidarQuienComparte();
        $this->prestarleASqliteLoQueEsDeMysql();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<array{0:string,1:float}> $pagos [metodo, monto] */
    private function orden(int $vendedor, int $tienda, float $valor, array $pagos, string $mes = self::MES, bool $restauracion = false, string $dia = '15'): int
    {
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'tienda_vendedor_id' => $tienda,
            'canal' => 'fisica', 'estado' => 'en_produccion', 'valor_total' => $valor,
            'created_at' => "{$mes}-{$dia} 15:00:00", 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert(['orden_id' => $id, 'es_restauracion' => $restauracion, 'precio_unitario' => $valor]);
        foreach ($pagos as [$metodo, $monto]) {
            DB::table('pagos')->insert(['orden_id' => $id, 'monto' => $monto, 'metodo' => $metodo, 'tipo' => 'anticipo', 'created_at' => now()]);
        }
        ComisionController::crearParaOrden(Orden::with('pagos')->find($id));

        return $id;
    }

    private function regla(string $clave, mixed $valor, string $desde, string $alcance = 'general', ?int $id = null): void
    {
        DB::table('comision_reglas')->insert([
            'clave' => $clave, 'alcance' => $alcance, 'alcance_id' => $id,
            'valor' => $valor === null ? null : json_encode($valor), 'desde_mes' => $desde,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ReglasComision::olvidarCache();
    }

    /** [orden_id => fila de la pantalla] del mes. */
    private function porOrden(string $mes = self::MES): array
    {
        $out = [];
        foreach (app(ComisionController::class)->resumenDelMes($mes) as $f) {
            foreach ($f['ordenes'] as $o) $out[$o['orden_id']] = $o;
        }

        return $out;
    }

    public function test_sin_reglas_todo_se_calcula_como_siempre(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        // 1.190.000 ÷ 1,19 × 5 % = 50.000
        $id = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);

        $this->assertEquals(50_000, $this->porOrden()[$id]['monto_comision']);
    }

    public function test_una_regla_rige_desde_su_mes_y_no_mueve_los_anteriores(): void
    {
        Carbon::setTestNow('2026-12-21 10:00:00');
        $this->regla('pct_individual', 0.06, '2026-11');

        $octubre   = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]], '2026-10');
        $noviembre = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]], '2026-11');

        $this->assertEquals(50_000, $this->porOrden('2026-10')[$octubre]['monto_comision'], 'octubre sigue al 5 %');
        $this->assertEquals(60_000, $this->porOrden('2026-11')[$noviembre]['monto_comision'], 'noviembre al 6 %');
    }

    public function test_gana_la_mas_especifica_y_una_nula_vuelve_a_la_de_arriba(): void
    {
        Carbon::setTestNow('2026-12-21 10:00:00');
        $this->regla('pct_individual', 0.06, '2026-10');
        $this->regla('pct_individual', 0.07, '2026-10', 'tienda', self::FERIA);
        $this->regla('pct_individual', 0.08, '2026-10', 'usuario', self::LAURA);
        // Desde noviembre Laura ya no tiene excepción: rige la de la feria.
        $this->regla('pct_individual', null, '2026-11', 'usuario', self::LAURA);

        $octubre   = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]], '2026-10');
        $noviembre = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]], '2026-11');

        $this->assertEquals(80_000, $this->porOrden('2026-10')[$octubre]['monto_comision'], 'la de la persona');
        $this->assertEquals(70_000, $this->porOrden('2026-11')[$noviembre]['monto_comision'], 'la de la tienda');
        $this->assertSame(0.07, ReglasComision::valor('pct_individual', '2026-11', self::FERIA, self::LAURA));
        $this->assertSame(0.06, ReglasComision::valor('pct_individual', '2026-11', 99, 99), 'otra tienda: la general');
    }

    public function test_la_meta_y_el_pool_de_una_tienda_siguen_sus_reglas_tambien_en_sql(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        // 50 M pagados + 10 M con el 30 % pagado; meta 40 M.
        $this->orden(self::PAOLA, self::NORTE, 50_000_000, [['efectivo', 50_000_000]]);
        $this->orden(self::PAOLA, self::NORTE, 10_000_000, [['efectivo', 3_000_000]]);

        // Como siempre: solo cuenta lo que tiene la mitad → 10 M ÷ 1,19 × 5 % = 420.168.
        $this->assertEquals(420_168, collect($this->porOrden())->sum('monto_comision'));
        $this->assertEquals(50_000_000, ComisionController::avanceDeMetas()[self::NORTE . '_' . self::MES]['cuenta']);

        // En Norte cuenta todo lo vendido para la meta (las sumas en SQL usan
        // la regla de la tienda) → 20 M ÷ 1,19 × 5 % = 840.336.
        $this->regla('meta_req_pagado_pct', 0, '2026-10', 'tienda', self::NORTE);
        $this->assertEquals(60_000_000, ComisionController::avanceDeMetas()[self::NORTE . '_' . self::MES]['cuenta']);
        $this->assertEquals(840_336, collect($this->porOrden())->sum('monto_comision'));

        // Y el pool de Norte al 10 % → 1.680.672.
        $this->regla('pct_pool', 0.10, '2026-10', 'tienda', self::NORTE);
        $this->assertEquals(1_680_672, collect($this->porOrden())->sum('monto_comision'));
    }

    public function test_solo_quien_tiene_el_permiso_cambia_reglas(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $regla = ['clave' => 'pct_individual', 'alcance' => 'tienda', 'alcance_id' => self::FERIA, 'valor' => 0.07, 'desde_mes' => '2026-10'];

        $this->actingAs(Usuario::find(self::OTRO))->getJson('/api/comisiones/reglas')
            ->assertOk()->assertJsonPath('puede_editar', false);
        $this->actingAs(Usuario::find(self::OTRO))->postJson('/api/comisiones/reglas', $regla)->assertStatus(403);

        $this->actingAs(Usuario::find(self::JEFE))->postJson('/api/comisiones/reglas', $regla)->assertStatus(201);
        $this->assertSame(0.07, ReglasComision::valor('pct_individual', '2026-10', self::FERIA));

        // La misma fila con otro valor se reemplaza, no se duplica.
        $this->actingAs(Usuario::find(self::JEFE))->postJson('/api/comisiones/reglas', ['valor' => 0.065] + $regla)->assertOk();
        $this->assertSame(1, DB::table('comision_reglas')->count());
        ReglasComision::olvidarCache();
        $this->assertSame(0.065, ReglasComision::valor('pct_individual', '2026-10', self::FERIA));

        // Pide el recálculo de siempre.
        $this->assertSame('pedido', ComisionController::estadoDelRecalculo()['estado']);
    }

    public function test_valores_y_niveles_que_no_sirven_se_frenan(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $jefe = $this->actingAs(Usuario::find(self::JEFE));

        $jefe->postJson('/api/comisiones/reglas', ['clave' => 'pct_pool', 'alcance' => 'general', 'valor' => 1.5, 'desde_mes' => '2026-10'])->assertStatus(422);
        $jefe->postJson('/api/comisiones/reglas', ['clave' => 'iva_divisor', 'alcance' => 'tienda', 'alcance_id' => self::NORTE, 'valor' => 1.19, 'desde_mes' => '2026-10'])->assertStatus(422);
        $jefe->postJson('/api/comisiones/reglas', ['clave' => 'dia_pago', 'alcance' => 'general', 'valor' => 40, 'desde_mes' => '2026-10'])->assertStatus(422);
        $jefe->postJson('/api/comisiones/reglas', ['clave' => 'franquicia_metodos', 'alcance' => 'general', 'valor' => ['bitcoin'], 'desde_mes' => '2026-10'])->assertStatus(422);
        $jefe->postJson('/api/comisiones/reglas', ['clave' => 'pct_pool', 'alcance' => 'general', 'valor' => null, 'desde_mes' => '2026-10'])->assertStatus(422);
        $jefe->postJson('/api/comisiones/reglas', ['clave' => 'pct_individual', 'alcance' => 'usuario', 'alcance_id' => 999, 'valor' => 0.06, 'desde_mes' => '2026-10'])->assertStatus(422);

        $this->assertSame(0, DB::table('comision_reglas')->count());
    }

    public function test_hacia_atras_solo_sin_pagos_y_con_confirmacion(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);
        $jefe = $this->actingAs(Usuario::find(self::JEFE));
        $regla = ['clave' => 'pct_individual', 'alcance' => 'tienda', 'alcance_id' => self::FERIA, 'valor' => 0.06, 'desde_mes' => '2026-10'];

        // Octubre no tiene pagos: pide confirmación y con ella pasa.
        $jefe->postJson('/api/comisiones/reglas', $regla)->assertStatus(409)->assertJsonPath('requiere_confirmacion', true);
        $jefe->postJson('/api/comisiones/reglas', $regla + ['confirmar' => true])->assertStatus(201);
        $this->assertEquals(60_000, $this->porOrden()[DB::table('ordenes')->value('id')]['monto_comision']);

        // Se le paga octubre: desde ahí ya no se puede tocar octubre.
        $jefe->postJson('/api/comisiones/pagar-listas', ['vendedor_id' => self::LAURA, 'mes' => '2026-10'])->assertOk();
        $jefe->postJson('/api/comisiones/reglas', ['valor' => 0.09, 'confirmar' => true] + $regla)->assertStatus(422);
        $id = DB::table('comision_reglas')->value('id');
        $jefe->deleteJson("/api/comisiones/reglas/{$id}", ['confirmar' => true])->assertStatus(422);

        // Una regla de otra tienda sí se puede: lo pagado es de la feria.
        $jefe->postJson('/api/comisiones/reglas', ['alcance_id' => self::NORTE, 'confirmar' => true] + $regla)->assertStatus(201);
    }

    public function test_el_pago_guarda_las_reglas_con_que_se_pago(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->regla('pct_individual', 0.07, '2026-10', 'tienda', self::FERIA);
        $id = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);

        $this->actingAs(Usuario::find(self::JEFE))
            ->postJson('/api/comisiones/pagar-listas', ['vendedor_id' => self::LAURA, 'mes' => self::MES])
            ->assertOk()->assertJsonPath('monto', 70_000);

        $foto = json_decode(DB::table('comisiones')->where('orden_id', $id)->value('reglas_usadas'), true);
        $this->assertSame(0.07, $foto['pct_individual']);
        $this->assertSame(20, $foto['dia_pago']);
        $this->assertCount(count(ReglasComision::CATALOGO), $foto);

        // Cambiar la regla después no mueve lo pagado.
        $this->regla('pct_individual', 0.09, '2026-10', 'usuario', self::LAURA);
        $this->assertEquals(70_000, $this->porOrden()[$id]['monto_comision']);
    }

    public function test_el_simulador_muestra_cuanto_cambia_cada_uno_sin_guardar_nada(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);
        $this->orden(self::PAOLA, self::NORTE, 50_000_000, [['efectivo', 50_000_000]]);
        $renglones = DB::table('comisiones')->count();

        $r = $this->actingAs(Usuario::find(self::JEFE))->postJson('/api/comisiones/reglas/simular', [
            'clave' => 'pct_individual', 'alcance' => 'tienda', 'alcance_id' => self::FERIA,
            'valor' => 0.07, 'desde_mes' => '2026-12', 'mes' => self::MES,
        ])->assertOk();

        $laura = collect($r->json('personas'))->firstWhere('vendedor_id', self::LAURA);
        $paola = collect($r->json('personas'))->firstWhere('vendedor_id', self::PAOLA);
        $this->assertEquals([50_000, 70_000, 20_000], [$laura['antes'], $laura['despues'], $laura['diferencia']]);
        $this->assertEquals(0, $paola['diferencia'], 'la regla es de la feria');
        $this->assertSame(1, $r->json('cambian'));
        $this->assertNull($r->json('aviso'));

        // Nada quedó: ni la regla ni renglones del resumen.
        $this->assertSame(0, DB::table('comision_reglas')->count());
        $this->assertSame($renglones, DB::table('comisiones')->count());
        $this->assertSame(0.05, ReglasComision::valor('pct_individual', self::MES, self::FERIA));

        // Una regla que toca lo guardado en cada venta lo avisa.
        $this->actingAs(Usuario::find(self::JEFE))->postJson('/api/comisiones/reglas/simular', [
            'clave' => 'franquicia_pct', 'alcance' => 'general', 'valor' => 0.04, 'desde_mes' => self::MES,
        ])->assertOk()->assertJsonPath('cambian', 0)->assertJsonPath('aviso', fn ($a) => is_string($a));

        // Sin el permiso, no.
        $this->actingAs(Usuario::find(self::OTRO))->postJson('/api/comisiones/reglas/simular', [
            'clave' => 'pct_individual', 'alcance' => 'general', 'valor' => 0.07, 'desde_mes' => self::MES,
        ])->assertStatus(403);
    }

    /** Dos independientes (Henry y Flabio) y una restauración de Henry de $1.000.000. */
    private function bolsonDeDos(): array
    {
        foreach ([[40, 'Henry'], [41, 'Flabio']] as [$id, $nombre]) {
            DB::table('usuarios')->insert(['id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor', 'independiente' => true,
                'email' => "$id@d.com", 'password' => 'x', 'created_at' => now()]);
        }
        \App\Services\ComisionIndependientes::olvidarCache();
        $this->orden(40, self::FERIA, 1_000_000, [['efectivo', 1_000_000]], self::MES, restauracion: true);

        $cuenta = \App\Services\ComisionIndependientes::delMes(self::MES);
        $de = fn ($id) => collect($cuenta['independientes'])->firstWhere('vendedor_id', $id)['comision_restauraciones'];

        return [$de(40), $de(41)];
    }

    public function test_bolson_hoy_cada_uno_cobra_el_completo(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        // 1.000.000 × 5 % = 50.000 para cada uno (la empresa paga 100.000).
        $this->assertEquals([50_000, 50_000], $this->bolsonDeDos());
    }

    public function test_bolson_que_se_reparte_entre_los_que_entran(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->regla('bolson_modo', 'se_reparte', '2026-10');
        // El 5 % (50.000) partido entre los dos.
        $this->assertEquals([25_000, 25_000], $this->bolsonDeDos());
    }

    public function test_sacar_a_alguien_del_bolson(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->regla('entra_al_bolson', false, '2026-10', 'usuario', 40);
        $this->regla('bolson_modo', 'se_reparte', '2026-10');
        // Henry no entra: aunque la hizo él, el bolsón es de Flabio solo.
        $this->assertEquals([0, 50_000], $this->bolsonDeDos());
    }

    public function test_una_opcion_que_no_existe_se_frena(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->actingAs(Usuario::find(self::JEFE))->postJson('/api/comisiones/reglas', [
            'clave' => 'bolson_modo', 'alcance' => 'general', 'valor' => 'por_ventas', 'desde_mes' => '2026-10',
        ])->assertStatus(422);
        $this->actingAs(Usuario::find(self::JEFE))->getJson('/api/comisiones/reglas')
            ->assertOk()->assertJsonPath('catalogo.' . array_search('bolson_modo', array_keys(ReglasComision::CATALOGO)) . '.opciones.1.valor', 'se_reparte');
    }

    public function test_la_parte_de_la_compartida_por_persona(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        // Cuando Laura registra una compartida con Paola, Laura se lleva el 60 %.
        $this->regla('compartida_parte_vendedor', 0.6, '2026-10', 'usuario', self::LAURA);
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => self::FERIA, 'vendedor_id' => self::LAURA, 'tienda_vendedor_id' => self::FERIA,
            'es_compartida' => true, 'covendedor_id' => self::PAOLA, 'canal' => 'fisica',
            'estado' => 'en_produccion', 'valor_total' => 1_000_000, 'created_at' => self::MES . '-15 15:00:00', 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert(['orden_id' => $id, 'precio_unitario' => 1_000_000]);
        DB::table('pagos')->insert(['orden_id' => $id, 'monto' => 1_000_000, 'metodo' => 'efectivo', 'created_at' => now()]);
        ComisionController::crearParaOrden(Orden::with('pagos')->find($id));

        $base = DB::table('comisiones')->where('orden_id', $id)->pluck('valor_orden', 'vendedor_id');
        $this->assertEquals(600_000, $base[self::LAURA]);
        $this->assertEquals(400_000, $base[self::PAOLA], 'el covendedor se lleva el resto');

        // Y recalcular no lo deshace.
        ComisionController::sincronizarValorOrden(Orden::with('pagos')->find($id));
        $this->assertEquals(400_000, DB::table('comisiones')->where('orden_id', $id)->where('vendedor_id', self::PAOLA)->value('valor_orden'));
    }

    public function test_una_tienda_que_cuenta_las_restauraciones_como_ventas(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        // Norte: 40 M de venta + 11,9 M de restauración; meta 40 M.
        $venta = $this->orden(self::PAOLA, self::NORTE, 40_000_000, [['efectivo', 40_000_000]]);
        $rest  = $this->orden(self::PAOLA, self::NORTE, 11_900_000, [['efectivo', 11_900_000]], self::MES, restauracion: true);

        // Como siempre: la venta llega justo a la meta (pool 0) y la
        // restauración se paga aparte: 11.900.000 × 5 % = 595.000.
        $o = $this->porOrden();
        $this->assertEquals(0, $o[$venta]['monto_comision']);
        $this->assertEquals(595_000, $o[$rest]['monto_comision']);
        $this->assertSame('restauracion_5', $o[$rest]['forma_pago']);
        $this->assertEquals(40_000_000, ComisionController::avanceDeMetas()[self::NORTE . '_' . self::MES]['cuenta']);

        // Norte la cuenta como venta: suma a la meta y entra al pool, sin
        // pagarse aparte. Pool = 11.900.000 ÷ 1,19 × 5 % = 500.000.
        $this->regla('restauracion_suma_meta', true, '2026-10', 'tienda', self::NORTE);
        $this->assertEquals(51_900_000, ComisionController::avanceDeMetas()[self::NORTE . '_' . self::MES]['cuenta']);
        $o = $this->porOrden();
        $this->assertEquals(500_000, $o[$venta]['monto_comision'] + $o[$rest]['monto_comision']);
        $this->assertSame('pool', $o[$rest]['forma_pago']);
    }

    /**
     * Norte con dos en el equipo (Paola y NN). Manuela, de la feria, cubre a
     * Paola del 16 al 31 de octubre y vende 11,9 M el 20. Paola vendió 51,9 M
     * el 15. Meta 40 M.
     *
     * @return array{0:int, 1:int}  [orden de Paola, orden de Manuela]
     */
    private function manuelaCubreAPaola(): array
    {
        DB::table('usuarios')->insert([
            ['id' => 11, 'nombre' => 'NN', 'rol' => 'vendedor', 'tienda_default_id' => self::NORTE,
             'acceso_comisiones' => false, 'acceso_reglas_comision' => false, 'email' => '11@d.com', 'password' => 'x', 'created_at' => now()],
            ['id' => 12, 'nombre' => 'Manuela', 'rol' => 'vendedor', 'tienda_default_id' => self::FERIA,
             'acceso_comisiones' => false, 'acceso_reglas_comision' => false, 'email' => '12@d.com', 'password' => 'x', 'created_at' => now()],
        ]);
        DB::table('tienda_asesores_comision')->insert([
            'tienda_id' => self::NORTE, 'mes' => self::MES, 'vendedor_id' => 11, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tienda_reemplazos')->insert([
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo', 'usuario_id' => 12, 'reemplaza_a_id' => self::PAOLA,
            'desde' => '2026-10-16', 'hasta' => '2026-10-31', 'created_at' => now(), 'updated_at' => now(),
        ]);
        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();

        return [
            $this->orden(self::PAOLA, self::NORTE, 51_900_000, [['efectivo', 51_900_000]]),
            $this->orden(12, self::NORTE, 11_900_000, [['efectivo', 11_900_000]], self::MES, false, '20'),
        ];
    }

    public function test_quien_reemplaza_entra_al_pool_por_dias_como_siempre(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        [, $manuela] = $this->manuelaCubreAPaola();

        // Sin la regla: sus ventas suman a la meta y ella va por el pool.
        $this->assertEquals(63_800_000, ComisionController::avanceDeMetas()[self::NORTE . '_' . self::MES]['cuenta']);
        $this->assertSame('pool', $this->porOrden()[$manuela]['forma_pago']);
    }

    public function test_quien_reemplaza_cobra_un_porcentaje_de_lo_que_vendio(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        [$paola, $manuela] = $this->manuelaCubreAPaola();
        $this->regla('reparto_reemplazo', 'sus_ventas', '2026-10', 'tienda', self::NORTE);
        $this->regla('pct_reemplazo', 0.03, '2026-10', 'tienda', self::NORTE);

        // Lo de Manuela no suma a la meta: solo los 51,9 M de Paola.
        $this->assertEquals(51_900_000, ComisionController::avanceDeMetas()[self::NORTE . '_' . self::MES]['cuenta']);

        $filas = collect(app(ComisionController::class)->resumenDelMes(self::MES));
        $o = $this->porOrden();

        // Manuela: 11.900.000 ÷ 1,19 × 3 % = 300.000, lista (pagó todo y llegó el 20).
        $this->assertSame('reemplazo_ventas', $o[$manuela]['forma_pago']);
        $this->assertEquals(300_000, $o[$manuela]['monto_comision']);
        $this->assertSame('lista', $o[$manuela]['estado']);

        // Pool = (51,9 M − 40 M) ÷ 1,19 × 5 % = 500.000, entre Paola (15 días:
        // perdió los que la cubrieron) y NN (31). Manuela no reparte.
        $this->assertEquals(round(500_000 * 15 / 46), $o[$paola]['monto_comision']);
        $nn = $filas->firstWhere('vendedor_id', 11);
        $this->assertEquals(round(500_000 * 31 / 46), $nn['comision_total']);
        $this->assertNull($filas->first(fn ($f) => $f['vendedor_id'] === 12 && collect($f['ordenes'])->contains('forma_pago', 'parte_pool')));
    }

    public function test_cambiar_el_dia_de_pago_corre_la_fecha_de_lo_que_falta(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $id = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);
        $this->assertSame('2026-11-20', DB::table('comisiones')->where('orden_id', $id)->value('fecha_disponible'));

        $this->actingAs(Usuario::find(self::JEFE))->postJson('/api/comisiones/reglas', [
            'clave' => 'dia_pago', 'alcance' => 'tienda', 'alcance_id' => self::FERIA, 'valor' => 5, 'desde_mes' => '2026-10',
        ])->assertStatus(201);

        $this->assertSame('2026-11-05', DB::table('comisiones')->where('orden_id', $id)->value('fecha_disponible'));
    }

    // ── Pieza 8: pool por tramos y bono (dueño, 2026-10-27) ──────────────

    /** Norte (meta 40 M, Paola sola en el equipo) vende $50 M, pagados. Lo que le toca a Paola. */
    private function poolDeNorteCon(float $vendido): float
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $id = $this->orden(self::PAOLA, self::NORTE, $vendido, [['efectivo', $vendido]]);

        return (float) $this->porOrden()[$id]['monto_comision'];
    }

    public function test_pool_de_siempre_con_la_formula_por_defecto(): void
    {
        // 10.000.000 ÷ 1,19 × 5 % = 420.168
        $this->assertEquals(420_168, $this->poolDeNorteCon(50_000_000));
    }

    public function test_pool_por_tramos_que_suman(): void
    {
        $this->regla('formula_pool', 'tramos', '2026-10', 'tienda', self::NORTE);
        $this->regla('tramos_pool', [['desde' => 1, 'pct' => 0.05], ['desde' => 1.2, 'pct' => 0.06]], '2026-10', 'tienda', self::NORTE);

        // 40–48 M al 5 %: 8.000.000 ÷ 1,19 × 5 % = 336.134,45
        // 48–50 M al 6 %: 2.000.000 ÷ 1,19 × 6 % = 100.840,34  → 436.975
        $this->assertEquals(436_975, $this->poolDeNorteCon(50_000_000));

        $d = ComisionController::desglosePool(47_600_000, 40_000_000, self::NORTE, self::MES);
        $this->assertCount(1, $d['lineas']);
        $this->assertEquals(0.06, $d['siguiente']['pct']);
        $this->assertEquals(400_000, $d['siguiente']['falta']);   // le faltan $400.000 para el 6 %

        // El asistente lo explica por tramos.
        $this->assertStringContainsString('por tramos', app(ComisionController::class)->avanceMetaParaAgente(self::NORTE, self::MES)['nota']);
    }

    public function test_el_resumen_de_la_tienda_trae_la_cuenta_solo_con_tramos_o_bono(): void
    {
        $resumenNorte = function () {
            foreach (app(ComisionController::class)->resumenDelMes(self::MES) as $f) {
                if ((int) $f['vendedor_id'] === self::PAOLA) return $f['resumen_tienda']['pool'] ?? null;
            }
        };

        $this->poolDeNorteCon(50_000_000);
        $this->assertArrayNotHasKey('cuenta', $resumenNorte());   // el pool de siempre: nada nuevo

        $this->regla('formula_pool', 'tramos', '2026-10', 'tienda', self::NORTE);
        $this->regla('tramos_pool', [['desde' => 1, 'pct' => 0.05], ['desde' => 1.2, 'pct' => 0.06]], '2026-10', 'tienda', self::NORTE);
        $this->regla('bono_meta', 300_000, '2026-10', 'tienda', self::NORTE);
        $cuenta = $resumenNorte()['cuenta'];
        $this->assertSame(['tramo', 'tramo', 'bono'], array_column($cuenta['lineas'], 'tipo'));
        $this->assertEquals([336_134, 100_840, 300_000], array_column($cuenta['lineas'], 'monto'));
    }

    public function test_el_bono_va_encima_y_solo_con_la_meta_cumplida(): void
    {
        $this->regla('bono_meta', 300_000, '2026-10', 'tienda', self::NORTE);

        // 420.168 del pool + 300.000 de bono.
        $this->assertEquals(720_168, $this->poolDeNorteCon(50_000_000));
        // Sin llegar a la meta no hay pool ni bono.
        $this->assertEquals(0, ComisionController::poolDe(39_900_000, 40_000_000, self::NORTE, self::MES));
        // Justo en la meta: sin excedente, solo el bono.
        $this->assertEquals(300_000, ComisionController::poolDe(40_000_000, 40_000_000, self::NORTE, self::MES));
    }

    public function test_tramos_que_no_sirven_y_tiendas_trimestrales_se_frenan(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $jefe = Usuario::find(self::JEFE);
        $tramos = fn (array $t) => ['clave' => 'tramos_pool', 'alcance' => 'general', 'valor' => $t, 'desde_mes' => '2026-11'];

        // Por debajo de la meta no hay pool: el primero empieza en el 100 %.
        $this->actingAs($jefe)->postJson('/api/comisiones/reglas', $tramos([['desde' => 0.8, 'pct' => 0.03], ['desde' => 1, 'pct' => 0.05]]))
            ->assertStatus(422)->assertJsonPath('message', 'El primer tramo empieza en el 100 % de la meta.');
        $this->actingAs($jefe)->postJson('/api/comisiones/reglas', $tramos([['desde' => 1, 'pct' => 0.5]]))->assertStatus(422);
        $this->actingAs($jefe)->postJson('/api/comisiones/reglas', $tramos([['desde' => 1, 'pct' => 0.05], ['desde' => 1.2, 'pct' => 0.06]]))
            ->assertStatus(201);

        // En una tienda que liquida por trimestre, no.
        Schema::table('tiendas', fn (Blueprint $t) => $t->string('comision_periodicidad')->default('mensual'));
        DB::table('tiendas')->where('id', self::FERIA)->update(['comision_periodicidad' => 'trimestral']);
        ComisionController::olvidarPeriodicidades();
        $this->actingAs($jefe)->postJson('/api/comisiones/reglas', [
            'clave' => 'bono_meta', 'alcance' => 'tienda', 'alcance_id' => self::FERIA, 'valor' => 300_000, 'desde_mes' => '2026-11',
        ])->assertStatus(422);
    }

    // ── Fase 3: los textos dicen lo que rige ─────────────────────────────

    public function test_las_reglas_vigentes_para_los_textos(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->regla('reparto_pool', 'iguales', '2026-10', 'tienda', self::NORTE);
        $this->regla('pct_individual', 0.07, '2026-11', 'usuario', self::LAURA);

        $r = $this->actingAs(Usuario::find(self::JEFE))->getJson('/api/comisiones/reglas/vigentes?mes=2026-10')->assertOk();
        $this->assertEquals(0.05, $r->json('general.pct_pool'));
        $this->assertSame('dias', $r->json('general.reparto_pool'));
        $this->assertCount(1, $r->json('excepciones'));
        $this->assertSame('Decasa Norte', $r->json('excepciones.0.alcance_nombre'));
        $this->assertSame('Partes iguales', $r->json('excepciones.0.valor_texto'));

        // La de Laura rige desde noviembre.
        $this->actingAs(Usuario::find(self::JEFE))->getJson('/api/comisiones/reglas/vigentes?mes=2026-11')
            ->assertOk()->assertJsonCount(2, 'excepciones')->assertJsonPath('excepciones.1.valor_texto', '7%');

        // Sin Comisiones: la regla general sí (la Ayuda es de todos), a quién
        // se le paga distinto no.
        $this->actingAs(Usuario::find(self::PAOLA))->getJson('/api/comisiones/reglas/vigentes?mes=2026-10')
            ->assertOk()->assertJsonPath('general.pct_individual', 0.05)->assertJsonCount(0, 'excepciones');
    }

    public function test_el_asistente_explica_con_las_reglas_del_mes(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $controlador = app(ComisionController::class);

        // Con las de siempre dice lo mismo que antes.
        $this->assertStringContainsString('÷ 1,19 × 5%', $controlador->avanceMetaParaAgente(self::FERIA, self::MES)['nota']);
        $this->assertStringContainsString('según los días', $controlador->avanceMetaParaAgente(self::NORTE, self::MES)['nota']);

        $this->regla('pct_individual', 0.06, '2026-10', 'tienda', self::FERIA);
        $this->assertStringContainsString('÷ 1,19 × 6%', $controlador->avanceMetaParaAgente(self::FERIA, self::MES)['nota']);
        $this->regla('iva_en_venta', false, '2026-10', 'tienda', self::FERIA);
        $nota = $controlador->avanceMetaParaAgente(self::FERIA, self::MES)['nota'];
        $this->assertStringContainsString('× 6%', $nota);
        $this->assertStringNotContainsString('÷', $nota);

        $this->regla('reparto_pool', 'iguales', '2026-10', 'tienda', self::NORTE);
        $this->assertStringContainsString('en partes iguales', $controlador->avanceMetaParaAgente(self::NORTE, self::MES)['nota']);

        // Las reglas del prompt del chat, con la excepción de Norte.
        $prompt = (fn () => $this->reglasDeComisiones())->call(app(\App\Services\AgentService::class));
        $this->assertStringContainsString('(ventas de la tienda QUE YA TIENEN EL 50% PAGADO − meta) ÷ 1,19 × 5%', $prompt);
        $this->assertStringContainsString('la franquicia del 5,5% no se comisiona', $prompt);
        $this->assertStringContainsString('tienda Decasa Norte: Cómo se reparte el pool = Partes iguales', $prompt);

        $this->regla('pct_pool', 0.06, '2026-10');
        $prompt = (fn () => $this->reglasDeComisiones())->call(app(\App\Services\AgentService::class));
        $this->assertStringContainsString('− meta) ÷ 1,19 × 6%', $prompt);
    }
}
