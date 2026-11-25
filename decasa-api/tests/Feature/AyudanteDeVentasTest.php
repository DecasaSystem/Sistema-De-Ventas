<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Http\Controllers\OrdenController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Quien ayuda en ventas sin ser de ventas (usuario, 2026-11-25).
 *
 * "En esta empresa a veces los mismos trabajadores que no son vendedores
 * ayudan a cerrar ventas o incluso las hacen." Un conductor o alguien del
 * taller con "Apto para compartir ventas" sale en la lista de co-vendedor; con
 * "Apto para ventas", también puede ser el vendedor. Decisiones del usuario:
 *
 *   - No tiene tienda: cobra su 5 % directo (÷ 1,19, la mitad pagada y el día
 *     de pago, igual que un vendedor sin meta).
 *   - Su parte NO suma a la meta ni al pool de ninguna tienda: nada se paga dos
 *     veces. La mitad del vendedor va a su tienda como siempre.
 *   - Solo órdenes desde ComisionController::CORTE_AYUDANTES.
 *
 * Antes, compartida con alguien sin tienda, a él no se le abría renglón y su
 * mitad no la cobraba nadie.
 */
class AyudanteDeVentasTest extends TestCase
{
    private const MES = '2026-12';

    private const NORTE = 1, VIRTUAL = 3;

    private const PAOLA = 1, MARTA = 2, NN = 3,  // Norte, meta $40 M
                  MANUELA = 6,                   // Tienda Virtual, sin meta
                  HENRY = 9,                     // independiente (ebanista)
                  CARLOS = 20,                   // conductor, apto para compartir
                  PEDRO = 21,                    // tapicero con sede en Norte, apto para ventas
                  JORGE = 22;                    // conductor sin marcas

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('apto_comisiones')->default(false); $t->boolean('apto_compartir_ventas')->default(false);
            $t->boolean('no_usa_programa')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
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
            $t->string('canal')->nullable(); $t->unsignedBigInteger('tienda_vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0);
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
            $t->decimal('deficit_inicial', 15, 2)->default(0);
            $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0);
            $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::NORTE,   'nombre' => 'Decasa Norte',   'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::VIRTUAL, 'nombre' => 'Tienda Virtual', 'activa' => true, 'comisiones_compartidas' => false],
        ]);
        foreach (['2026-11', self::MES] as $mes) {
            DB::table('metas_tienda')->insert(['tienda_id' => self::NORTE, 'mes' => $mes, 'meta' => 40_000_000,
                'divisor_asesores' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }

        $gente = [
            [self::PAOLA, 'Paola', 'vendedor', self::NORTE, []],
            [self::MARTA, 'Marta', 'vendedor', self::NORTE, []],
            [self::NN, 'NN', 'vendedor', self::NORTE, []],
            [self::MANUELA, 'Manuela', 'vendedor', self::VIRTUAL, []],
            [self::HENRY, 'Henry', 'ebanista', null, ['independiente' => true, 'apto_comisiones' => true]],
            [self::CARLOS, 'Carlos', 'conductor', null, ['apto_compartir_ventas' => true]],
            [self::PEDRO, 'Pedro', 'tapicero', self::NORTE, ['apto_comisiones' => true]],
            [self::JORGE, 'Jorge', 'conductor', null, []],
        ];
        foreach ($gente as [$id, $nombre, $rol, $tienda, $extra]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => $rol, 'tienda_default_id' => $tienda,
                'created_at' => now(), 'updated_at' => now(),
            ] + $extra);
        }
        foreach (['2026-11', self::MES] as $mes) {
            foreach ([self::PAOLA, self::MARTA, self::NN] as $quien) {
                DB::table('tienda_asesores_comision')->insert([
                    'tienda_id' => self::NORTE, 'mes' => $mes, 'vendedor_id' => $quien,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        ComisionController::olvidarAyudantes();

        $this->prestarleASqliteLoQueEsDeMysql();
    }

    /** Una orden ya cobrada entera, con sus comisiones creadas. */
    private function orden(int $vendedor, int $tienda, float $valor, ?int $covendedor = null,
                           string $dia = self::MES . '-15', bool $restauracion = false): Orden
    {
        $orden = Orden::create([
            'tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'estado' => 'entregado',
            'valor_total' => $valor,
            'es_compartida' => $covendedor !== null, 'covendedor_id' => $covendedor,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => $dia . ' 15:00:00']);
        DB::table('orden_items')->insert([
            'orden_id' => $orden->id, 'es_restauracion' => $restauracion, 'precio_unitario' => $valor,
        ]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => $valor, 'metodo' => 'efectivo', 'created_at' => now()]);

        ComisionController::crearParaOrden($orden->fresh());
        ComisionController::sincronizarValorOrden($orden->fresh());

        return $orden->fresh();
    }

    /** [nombre => ['monto' => , 'forma' => , 'ayuda' => ]] del cálculo del mes, después del día de pago. */
    private function calculo(string $hoy = '2027-01-25'): array
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
            $f = $enriquecer->invoke($ctrl, $c, $metas, $totTienda, $totVendedor, $poolsTrim, \Carbon\Carbon::parse($hoy));
            $quien = $nombres[$c->vendedor_id];
            $out[$quien]['monto'] = ($out[$quien]['monto'] ?? 0) + (float) $f['monto_comision'];
            $out[$quien]['forma'] = $f['forma_pago'];
            $out[$quien]['ayuda'] = $f['ayuda_en_ventas'];
            $out[$quien]['estado'] = $f['estado_calculado'];
        }

        return $out;
    }

    private function vendidoEnNorte(string $mes = self::MES): float
    {
        return (float) (ComisionController::avanceDeMetas()[self::NORTE . '_' . $mes]['vendido'] ?? 0);
    }

    public function test_compartida_con_un_conductor_el_cobra_su_5_y_su_mitad_no_suma_a_la_meta(): void
    {
        // Paola vende $60 M con Carlos (conductor, sin tienda): $30 M de cada uno.
        $this->orden(self::PAOLA, self::NORTE, 60_000_000, self::CARLOS);

        $this->assertSame(1, Comision::where('vendedor_id', self::CARLOS)->count(), 'antes no se le abría renglón');

        // A la meta de Norte solo le llegan los $30 M de Paola: no llega a $40 M.
        $this->assertEqualsWithDelta(30_000_000, $this->vendidoEnNorte(), 1);

        $c = $this->calculo();
        // Carlos: 30.000.000 ÷ 1,19 × 5 % = $1.260.504, sin pool.
        $this->assertEqualsWithDelta(1_260_504, $c['Carlos']['monto'], 2);
        $this->assertSame('sin_meta_5', $c['Carlos']['forma']);
        $this->assertTrue($c['Carlos']['ayuda']);
        $this->assertSame('lista', $c['Carlos']['estado']);
        // Norte no llegó a la meta: Paola no cobra pool.
        $this->assertEqualsWithDelta(0, $c['Paola']['monto'], 1);
        $this->assertFalse($c['Paola']['ayuda']);
    }

    public function test_con_la_meta_cumplida_el_pool_sale_solo_de_la_mitad_de_la_tienda(): void
    {
        // $100 M: $50 M de Paola a Norte, $50 M de Carlos por fuera.
        $this->orden(self::PAOLA, self::NORTE, 100_000_000, self::CARLOS);

        $this->assertEqualsWithDelta(50_000_000, $this->vendidoEnNorte(), 1);

        $c = $this->calculo();
        // Pool = (50 − 40) M ÷ 1,19 × 5 % = $420.168, ÷ 3 = $140.056.
        $this->assertEqualsWithDelta(140_056, $c['Paola']['monto'], 2);
        // Carlos: 50.000.000 ÷ 1,19 × 5 % = $2.100.840. Nada se paga dos veces.
        $this->assertEqualsWithDelta(2_100_840, $c['Carlos']['monto'], 2);
    }

    public function test_el_que_vende_solo_cobra_su_5_y_no_suma_a_la_meta(): void
    {
        // Pedro (tapicero con sede en Norte y "Apto para ventas") hace la orden en Norte.
        $this->orden(self::PEDRO, self::NORTE, 10_000_000);
        // Y Paola vende $45 M: Norte llega a la meta solo con lo de ella.
        $this->orden(self::PAOLA, self::NORTE, 45_000_000);

        $this->assertEqualsWithDelta(45_000_000, $this->vendidoEnNorte(), 1);

        $c = $this->calculo();
        $this->assertEqualsWithDelta(420_168, $c['Pedro']['monto'], 2);   // 10 M ÷ 1,19 × 5 %
        $this->assertSame('sin_meta_5', $c['Pedro']['forma']);
        // Pool (45 − 40) M ÷ 1,19 × 5 % = $210.084, ÷ 3 = $70.028.
        $this->assertEqualsWithDelta(70_028, $c['Paola']['monto'], 2);
    }

    public function test_aunque_tenga_sede_en_la_tienda_no_entra_al_pool(): void
    {
        // Pedro tiene Norte de sede, pero no es del equipo ni de ventas.
        $this->orden(self::PAOLA, self::NORTE, 100_000_000, self::PEDRO);

        $this->assertSame(self::NORTE, (int) Comision::where('vendedor_id', self::PEDRO)->value('tienda_id'));
        $this->assertEqualsWithDelta(50_000_000, $this->vendidoEnNorte(), 1);
        $this->assertEqualsWithDelta(2_100_840, $this->calculo()['Pedro']['monto'], 2);
    }

    public function test_una_venta_digital_su_renglon_va_a_la_tienda_del_vendedor(): void
    {
        // Manuela (Tienda Virtual) vende por Instagram con Carlos.
        $orden = Orden::create([
            'tienda_id' => self::NORTE, 'vendedor_id' => self::MANUELA, 'canal' => 'instagram',
            'estado' => 'entregado', 'valor_total' => 4_000_000, 'es_compartida' => true,
            'covendedor_id' => self::CARLOS,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => self::MES . '-15 15:00:00']);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'precio_unitario' => 4_000_000]);
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => 4_000_000, 'metodo' => 'efectivo', 'created_at' => now()]);
        ComisionController::crearParaOrden($orden->fresh());

        $this->assertSame(self::VIRTUAL, (int) Comision::where('vendedor_id', self::CARLOS)->value('tienda_id'));
        $this->assertEqualsWithDelta(84_034, $this->calculo()['Carlos']['monto'], 2); // 2 M ÷ 1,19 × 5 %
    }

    public function test_una_restauracion_compartida_no_espera_la_meta_de_la_tienda(): void
    {
        // Carlos ayuda con una restauración de $2 M: 5 % de su mitad, sin quitar IVA.
        $this->orden(self::PAOLA, self::NORTE, 2_000_000, self::CARLOS, restauracion: true);

        $c = $this->calculo();
        $this->assertEqualsWithDelta(50_000, $c['Carlos']['monto'], 1);
        $this->assertSame('restauracion_5', $c['Carlos']['forma']);
    }

    public function test_antes_del_corte_todo_sigue_como_estaba(): void
    {
        // El 20 de noviembre, antes de la regla: a Carlos (sin tienda) no se le abría renglón.
        $this->orden(self::PAOLA, self::NORTE, 60_000_000, self::CARLOS, '2026-11-20');
        $this->assertSame(0, Comision::where('vendedor_id', self::CARLOS)->count());

        // Y Pedro (con sede en Norte) sí, con su mitad sumándole a Norte.
        $this->orden(self::PAOLA, self::NORTE, 20_000_000, self::PEDRO, '2026-11-21');
        $this->assertSame(1, Comision::where('vendedor_id', self::PEDRO)->count());
        $this->assertEqualsWithDelta(30_000_000 + 20_000_000, $this->vendidoEnNorte('2026-11'), 1);
    }

    public function test_quien_puede_vender_y_compartir(): void
    {
        $u = fn ($id) => Usuario::find($id);

        $this->assertTrue($u(self::PAOLA)->puedeVender());
        $this->assertTrue($u(self::HENRY)->puedeVender(), 'independiente aunque sea ebanista');
        $this->assertTrue($u(self::PEDRO)->puedeVender(), 'apto para ventas');
        $this->assertFalse($u(self::CARLOS)->puedeVender());
        $this->assertTrue($u(self::CARLOS)->puedeCompartirVentas());
        $this->assertFalse($u(self::JORGE)->puedeCompartirVentas());

        $porQue = new \ReflectionMethod(OrdenController::class, 'porQueNoSeComparteCon');
        $this->assertNull($porQue->invoke(null, self::CARLOS));
        $this->assertStringContainsString('Apto para compartir ventas', $porQue->invoke(null, self::JORGE));
    }

    public function test_listas_dice_por_que_cobra_individual(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 100_000_000, self::CARLOS);
        \Carbon\Carbon::setTestNow('2027-01-25 12:00:00');

        $resumen = app(ComisionController::class)->resumenDelMes(self::MES);
        $carlos = $resumen->first(fn ($t) => (int) $t['vendedor_id'] === self::CARLOS);
        $paola  = $resumen->first(fn ($t) => (int) $t['vendedor_id'] === self::PAOLA);

        $this->assertNotNull($carlos, 'Carlos sale en el resumen del mes');
        $this->assertTrue($carlos['ordenes'][0]['ayuda_en_ventas']);
        $this->assertEqualsWithDelta(2_100_840, collect($carlos['ordenes'])->sum('monto_comision'), 2);

        $porQue = new \ReflectionMethod(ComisionController::class, 'porQueDelBloque');
        $this->assertSame('ayuda_en_ventas', $porQue->invoke(null, $carlos, self::MES)['motivo_individual']);
        // Paola va por el pool: no tiene motivo de individual.
        $this->assertNull($porQue->invoke(null, $paola, self::MES)['motivo_individual']);

        \Carbon\Carbon::setTestNow();
    }

    public function test_la_migracion_agrega_la_columna_apagada_y_se_puede_correr_dos_veces(): void
    {
        $migracion = require database_path('migrations/2026_11_26_000001_apto_para_compartir_ventas.php');

        $migracion->down();
        $this->assertFalse(Schema::hasColumn('usuarios', 'apto_compartir_ventas'));

        $migracion->up();
        $migracion->up();   // la segunda no hace nada
        $this->assertTrue(Schema::hasColumn('usuarios', 'apto_compartir_ventas'));
        $this->assertSame(0, DB::table('usuarios')->where('apto_compartir_ventas', true)->count(), 'nadie queda marcado');
    }

    public function test_la_ficha_guarda_la_marca(): void
    {
        DB::table('usuarios')->where('id', self::PAOLA)->update(['rol' => 'supervisor']);
        Schema::table('usuarios', function (Blueprint $t) {
            $t->string('email')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('clave')->nullable(); $t->string('arquetipo')->nullable();
            $t->json('modulos_venta')->nullable(); $t->timestamps();
        });
        DB::table('roles')->insert(['id' => 7, 'nombre' => 'Conductor', 'clave' => 'conductor', 'arquetipo' => 'conductor']);
        DB::table('usuarios')->where('id', self::JORGE)->update(['rol_id' => 7, 'email' => 'jorge@d.com']);

        $this->actingAs(Usuario::find(self::PAOLA), 'sanctum')
            ->putJson('/api/usuarios/' . self::JORGE, ['nombre' => 'Jorge', 'email' => 'jorge@d.com', 'apto_compartir_ventas' => true])
            ->assertOk()
            ->assertJsonPath('apto_compartir_ventas', true);

        $this->assertTrue(Usuario::find(self::JORGE)->puedeCompartirVentas());
    }

    public function test_la_lista_de_asesores(): void
    {
        $this->actingAs(Usuario::find(self::PAOLA), 'sanctum');

        // La de siempre (equipos de Bonificaciones): solo los de ventas.
        $ids = collect($this->getJson('/api/asesores')->assertOk()->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([self::MARTA, self::NN, self::MANUELA], $ids);

        // Para Nueva orden y Editar orden: también los ayudantes y Henry.
        $lista = collect($this->getJson('/api/asesores?con_ayudantes=1')->assertOk()->json())->keyBy('id');
        $this->assertEqualsCanonicalizing(
            [self::MARTA, self::NN, self::MANUELA, self::HENRY, self::CARLOS, self::PEDRO],
            $lista->keys()->all()
        );
        $this->assertFalse($lista[self::CARLOS]['puede_vender']);
        $this->assertTrue($lista[self::CARLOS]['puede_compartir']);
        $this->assertTrue($lista[self::CARLOS]['ayuda_en_ventas']);
        $this->assertTrue($lista[self::PEDRO]['puede_vender']);
        $this->assertFalse($lista[self::HENRY]['ayuda_en_ventas']);
        $this->assertFalse($lista[self::MARTA]['ayuda_en_ventas']);
    }
}
