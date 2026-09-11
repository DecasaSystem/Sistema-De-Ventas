<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\TiendaAsesor;
use App\Models\TiendaReemplazo;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un reemplazo mueve TODO lo que se reparte, no solo el pool.
 *
 * El caso real, septiembre de 2026 en Decasa Norte: Paola se fue de
 * vacaciones el 1 y Manuela la cubre. El pool la dejó por fuera —se calcula
 * al vuelo—, pero el 5% que un independiente le dejó a la tienda ya estaba
 * escrito en filas, una por persona, y ahí Paola seguía cobrando su parte.
 * Y Manuela, que es supervisora con sede en Norte pero no es del equipo,
 * entraba al reparto del abono aunque no estuviera cubriendo a nadie.
 *
 * Tres cosas se fijan aquí:
 *   - el abono se reparte entre el EQUIPO (el mismo que reparte el pool),
 *   - registrar, corregir o quitar un reemplazo rehace lo ya repartido,
 *   - una persona no puede estar en dos sitios a la vez.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReemplazoRehaceLosRepartosTest extends TestCase
{
    private const MES = '2026-09';
    private const NORTE = 1, SEDE_INDEP = 8;
    private const PAOLA = 16, MANUELA = 17, MARTA = 29, NN = 30, HENRY = 24, JEFA = 99;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_comisiones')->default(false);
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
            $t->string('estado')->default('en_produccion'); $t->decimal('valor_total', 15, 2)->default(0);
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

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::SEDE_INDEP, 'nombre' => 'Independientes', 'activa' => true, 'es_independientes' => true],
        ]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => self::NORTE, 'mes' => self::MES, 'meta' => 40_000_000,
            'divisor_asesores' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Como en la vida real: Manuela es supervisora con sede en Norte,
        // pero el equipo de comisiones son Paola, Marta y NN.
        foreach ([
            [self::PAOLA,   'Paola Andrea', 'vendedor',   self::NORTE, false],
            [self::MANUELA, 'Manuela',      'supervisor', self::NORTE, false],
            [self::MARTA,   'Marta',        'vendedor',   self::NORTE, false],
            [self::NN,      'NN',           'vendedor',   self::NORTE, false],
            [self::HENRY,   'Henry',        'vendedor',   self::SEDE_INDEP, true],
            [self::JEFA,    'Jefa',         'supervisor', null, false],
        ] as [$id, $nombre, $rol, $tienda, $indep]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => $rol, 'independiente' => $indep,
                'tienda_default_id' => $tienda, 'acceso_comisiones' => $id === self::JEFA,
                'email' => "$id@d.com", 'password' => 'x', 'created_at' => now(),
            ]);
        }
        foreach ([self::PAOLA, self::MARTA, self::NN] as $quien) {
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => self::NORTE, 'mes' => self::MES, 'vendedor_id' => $quien,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();
        ComisionController::olvidarQuienComparte();
        $this->prestarleASqliteLoQueEsDeMysql();
    }

    /** Henry (independiente) comparte una restauración con Norte. */
    private function abonoDeHenry(string $dia, float $valor = 1_000_000): Orden
    {
        $orden = Orden::create([
            'tienda_id' => self::SEDE_INDEP, 'vendedor_id' => self::HENRY,
            'tienda_abonada_id' => self::NORTE,
            'estado' => 'en_produccion', 'valor_total' => $valor,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => self::MES . "-$dia 15:00:00"]);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'es_restauracion' => true, 'precio_unitario' => $valor]);

        ComisionController::crearParaOrden($orden->fresh());

        return $orden->fresh();
    }

    /** [nombre => base repartida] de las filas de abono de una orden. */
    private function repartoDe(Orden $orden): array
    {
        $nombres = DB::table('usuarios')->pluck('nombre', 'id')->all();

        return Comision::where('orden_id', $orden->id)
            ->where('origen', ComisionController::ORIGEN_ABONO)
            ->get()
            ->mapWithKeys(fn ($c) => [$nombres[$c->vendedor_id] => (float) $c->valor_orden])
            ->all();
    }

    private function comoJefa()
    {
        return $this->actingAs(Usuario::find(self::JEFA));
    }

    public function test_el_abono_se_reparte_entre_el_equipo_no_entre_la_planta(): void
    {
        $orden = $this->abonoDeHenry('07');

        $reparto = $this->repartoDe($orden);

        // Tres partes: el equipo. Manuela tiene sede en Norte pero no es
        // del equipo, y no está cubriendo a nadie.
        $this->assertEqualsCanonicalizing(['Paola Andrea', 'Marta', 'NN'], array_keys($reparto));
        $this->assertEquals(333_333, $reparto['Marta']);
    }

    public function test_registrar_el_reemplazo_rehace_el_abono_ya_repartido(): void
    {
        // La orden entra ANTES de que alguien registre las vacaciones.
        $orden = $this->abonoDeHenry('07');
        $this->assertArrayHasKey('Paola Andrea', $this->repartoDe($orden));

        $this->comoJefa()->postJson('/api/comisiones/reemplazos', [
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::PAOLA,
            'desde' => self::MES . '-01', 'hasta' => self::MES . '-30',
        ])->assertStatus(201);

        $reparto = $this->repartoDe($orden);

        // Paola no estaba; Manuela ocupa su puesto. Siguen siendo tres.
        $this->assertEqualsCanonicalizing(['Manuela', 'Marta', 'NN'], array_keys($reparto));
        $this->assertEquals(333_333, $reparto['Manuela']);
    }

    public function test_corregir_la_fecha_de_vuelta_rehace_lo_de_esos_dias(): void
    {
        $id = TiendaReemplazo::create([
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::PAOLA,
            'desde' => self::MES . '-01', 'hasta' => self::MES . '-30',
        ])->id;
        TiendaReemplazo::olvidarCache();

        $del7  = $this->abonoDeHenry('07');
        $del20 = $this->abonoDeHenry('20');
        $this->assertArrayHasKey('Manuela', $this->repartoDe($del20));

        // Paola volvió el 15, no el 30.
        $this->comoJefa()->putJson("/api/comisiones/reemplazos/$id", ['hasta' => self::MES . '-14'])
            ->assertOk();

        // La del 7 sigue igual: ese día Manuela estaba cubriendo.
        $this->assertEqualsCanonicalizing(['Manuela', 'Marta', 'NN'], array_keys($this->repartoDe($del7)));
        // La del 20 vuelve a ser de Paola: ya estaba de regreso.
        $this->assertEqualsCanonicalizing(['Paola Andrea', 'Marta', 'NN'], array_keys($this->repartoDe($del20)));
    }

    public function test_quitar_el_reemplazo_le_devuelve_su_parte_a_quien_cubrian(): void
    {
        $id = TiendaReemplazo::create([
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::PAOLA,
            'desde' => self::MES . '-01', 'hasta' => self::MES . '-30',
        ])->id;
        TiendaReemplazo::olvidarCache();

        $orden = $this->abonoDeHenry('07');

        $this->comoJefa()->deleteJson("/api/comisiones/reemplazos/$id")->assertOk();

        $this->assertEqualsCanonicalizing(['Paola Andrea', 'Marta', 'NN'], array_keys($this->repartoDe($orden)));
    }

    public function test_quien_sale_del_equipo_sale_de_los_repartos_del_mes(): void
    {
        $orden = $this->abonoDeHenry('07');

        $fila = TiendaAsesor::where('tienda_id', self::NORTE)->where('vendedor_id', self::NN)->first();
        $this->comoJefa()->deleteJson("/api/comisiones/asesores-asignados/{$fila->id}?mes=" . self::MES)
            ->assertOk();

        $reparto = $this->repartoDe($orden);

        $this->assertEqualsCanonicalizing(['Paola Andrea', 'Marta'], array_keys($reparto));
        $this->assertEquals(500_000, $reparto['Marta']);
    }

    public function test_lo_ya_pagado_no_se_rehace(): void
    {
        $orden = $this->abonoDeHenry('07');
        Comision::where('orden_id', $orden->id)->where('vendedor_id', self::PAOLA)
            ->update(['estado' => 'pagada', 'monto_comision' => 16_667]);

        $this->comoJefa()->postJson('/api/comisiones/reemplazos', [
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::PAOLA,
            'desde' => self::MES . '-01', 'hasta' => self::MES . '-30',
        ])->assertStatus(201);

        // Paola sigue ahí: esa plata ya salió.
        $this->assertArrayHasKey('Paola Andrea', $this->repartoDe($orden));
        $this->assertArrayHasKey('Manuela', $this->repartoDe($orden));
    }

    public function test_nadie_puede_estar_en_dos_sitios_a_la_vez(): void
    {
        $this->comoJefa()->postJson('/api/comisiones/reemplazos', [
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::PAOLA,
            'desde' => self::MES . '-01', 'hasta' => self::MES . '-30',
        ])->assertStatus(201);

        // El mismo reemplazo otra vez, aunque sea por otros días que se cruzan.
        $this->comoJefa()->postJson('/api/comisiones/reemplazos', [
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::MARTA,
            'desde' => self::MES . '-20', 'hasta' => self::MES . '-25',
        ])->assertStatus(422);

        // Y a Paola no la pueden cubrir dos personas al tiempo.
        $this->comoJefa()->postJson('/api/comisiones/reemplazos', [
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::NN, 'reemplaza_a_id' => self::PAOLA,
            'desde' => self::MES . '-10', 'hasta' => self::MES . '-12',
        ])->assertStatus(422);

        // Después de que vuelve, sí.
        $this->comoJefa()->postJson('/api/comisiones/reemplazos', [
            'tienda_id' => self::NORTE, 'tipo' => 'reemplazo',
            'usuario_id' => self::MANUELA, 'reemplaza_a_id' => self::MARTA,
            'desde' => '2026-10-01', 'hasta' => '2026-10-05',
        ])->assertStatus(201);

        $this->assertSame(2, TiendaReemplazo::count());
    }
}
