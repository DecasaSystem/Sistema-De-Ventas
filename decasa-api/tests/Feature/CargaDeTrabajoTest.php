<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El tablero de Carga de trabajo (`GET /api/carga-trabajo`).
 *
 * Un día del taller armado a mano: una orden lista para entregar y vencida,
 * otra con un sofá personalizado en tapicería y un cojín de stock que ya se
 * puede llevar, un diseño especial que nadie ha arrancado, una pieza para la
 * Reserva, y ruido que NO es trabajo (una orden cancelada con su pieza y un
 * borrador). El tablero tiene que contar lo mismo que dirían las listas.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CargaDeTrabajoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 10 a. m. en Bogotá del 28 de octubre.
        Carbon::setTestNow('2026-10-28 15:00:00');

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('ve_todas_ordenes')->default(false); $t->boolean('apto_produccion')->default(false);
            $t->boolean('facturacion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('en_produccion'); $t->string('contacto_nombre')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->string('numero_anulado')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->integer('cantidad')->default(1); $t->unsignedInteger('cantidad_entregada')->default(0);
            $t->unsignedInteger('cantidad_en_garantia')->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('es_restauracion')->default(false);
            $t->boolean('producto_unico')->default(false); $t->boolean('retapizar')->default(false);
            $t->boolean('fabricar_pedido')->default(false); $t->unsignedInteger('piezas_juego')->nullable();
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->string('destino')->nullable();
            $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedInteger('cantidad')->default(1);
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente');
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal'); $t->unsignedTinyInteger('orden')->default(1);
            $t->string('estado')->default('pendiente'); $t->unsignedBigInteger('garantia_id')->nullable();
            $t->timestamp('iniciado_at')->nullable(); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->json('trabajadores')->nullable();
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('color')->nullable();
            $t->boolean('activo')->default(true); $t->unsignedTinyInteger('orden')->default(1);
            $t->string('descripcion')->nullable(); $t->timestamps();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });

        $this->sembrar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sembrar(): void
    {
        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Norte'], ['id' => 2, 'nombre' => 'El Edén']]);
        DB::table('clientes')->insert([
            ['id' => 1, 'nombre' => 'Ana'], ['id' => 2, 'nombre' => 'Beto'], ['id' => 3, 'nombre' => 'Carla'],
        ]);
        DB::table('productos')->insert([['id' => 7, 'nombre' => 'Cojín'], ['id' => 9, 'nombre' => 'Sofá Bali'], ['id' => 11, 'nombre' => 'Cama Roma']]);
        DB::table('tipos_proceso')->insert([
            ['id' => 1, 'clave' => 'ebanisteria', 'nombre' => 'Ebanistería', 'orden' => 1, 'activo' => true],
            ['id' => 2, 'clave' => 'tapiceria',   'nombre' => 'Tapicería',   'orden' => 2, 'activo' => true],
            ['id' => 3, 'clave' => 'despacho',    'nombre' => 'Despacho',    'orden' => 9, 'activo' => true],
        ]);
        DB::table('usuarios')->insert([
            ['id' => 50, 'nombre' => 'Tapicero 1', 'rol' => 'trabajador', 'activo' => true],
            ['id' => 51, 'nombre' => 'Tapicero 2', 'rol' => 'trabajador', 'activo' => true],
            ['id' => 52, 'nombre' => 'Retirado',   'rol' => 'trabajador', 'activo' => false],
        ]);
        DB::table('proceso_trabajadores')->insert([
            ['usuario_id' => 50, 'tipo_proceso_id' => 2], ['usuario_id' => 51, 'tipo_proceso_id' => 2],
            ['usuario_id' => 52, 'tipo_proceso_id' => 2],
        ]);

        // A — Norte, lista para entregar y vencida (prometida el 25).
        DB::table('ordenes')->insert(['id' => 1, 'cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'listo_entrega', 'numero_orden' => 4401]);
        DB::table('orden_items')->insert(['id' => 1, 'orden_id' => 1, 'producto_id' => 7, 'cantidad' => 2, 'fecha_entrega_prom' => '2026-10-25']);

        // B — Norte, sofá personalizado en tapicería + cojín de stock (ya se puede llevar).
        DB::table('ordenes')->insert(['id' => 2, 'cliente_id' => 2, 'tienda_id' => 1, 'estado' => 'en_produccion', 'numero_orden' => 4402]);
        DB::table('orden_items')->insert([
            ['id' => 2, 'orden_id' => 2, 'producto_id' => 9, 'cantidad' => 1, 'es_personalizado' => true, 'fecha_entrega_prom' => '2026-11-02'],
            ['id' => 3, 'orden_id' => 2, 'producto_id' => 7, 'cantidad' => 1, 'es_personalizado' => false, 'fecha_entrega_prom' => '2026-10-30'],
        ]);
        DB::table('produccion')->insert(['id' => 1, 'orden_item_id' => 2, 'estado' => 'en_proceso',
            'fecha_inicio' => '2026-10-15', 'fecha_compromiso' => '2026-11-02']);
        DB::table('produccion_pasos')->insert([
            ['id' => 1, 'produccion_id' => 1, 'tipo_proceso' => 'ebanisteria', 'orden' => 1, 'estado' => 'completado',
             'iniciado_at' => '2026-10-16 13:00:00', 'completado_at' => '2026-10-20 13:00:00'],
            ['id' => 2, 'produccion_id' => 1, 'tipo_proceso' => 'tapiceria', 'orden' => 2, 'estado' => 'en_proceso',
             'iniciado_at' => '2026-10-26 15:00:00', 'completado_at' => null],
            ['id' => 3, 'produccion_id' => 1, 'tipo_proceso' => 'despacho', 'orden' => 3, 'estado' => 'pendiente',
             'iniciado_at' => null, 'completado_at' => null],
        ]);

        // C — El Edén, diseño especial que nadie ha arrancado desde el 20.
        DB::table('ordenes')->insert(['id' => 3, 'cliente_id' => 3, 'tienda_id' => 2, 'estado' => 'en_produccion', 'numero_orden' => 4403]);
        DB::table('orden_items')->insert(['id' => 4, 'orden_id' => 3, 'producto_id' => null, 'nombre_custom' => 'Mesa a medida',
            'cantidad' => 1, 'es_personalizado' => true, 'fecha_entrega_prom' => '2026-11-20']);
        DB::table('produccion')->insert(['id' => 2, 'orden_item_id' => 4, 'estado' => 'pendiente',
            'fecha_inicio' => '2026-10-20', 'fecha_compromiso' => '2026-11-20']);

        // Ruido: una orden cancelada con su pieza a medias y un borrador.
        DB::table('ordenes')->insert([
            ['id' => 4, 'cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'cancelado', 'numero_orden' => 4404],
            ['id' => 5, 'cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'borrador', 'numero_orden' => null],
        ]);
        DB::table('orden_items')->insert([
            ['id' => 5, 'orden_id' => 4, 'producto_id' => 9, 'cantidad' => 1, 'es_personalizado' => true, 'fecha_entrega_prom' => '2026-10-01'],
            ['id' => 6, 'orden_id' => 5, 'producto_id' => 9, 'cantidad' => 1, 'es_personalizado' => true, 'fecha_entrega_prom' => '2026-10-01'],
        ]);
        DB::table('produccion')->insert(['id' => 3, 'orden_item_id' => 5, 'estado' => 'en_proceso', 'fecha_inicio' => '2026-09-20']);
        DB::table('produccion_pasos')->insert(['id' => 4, 'produccion_id' => 3, 'tipo_proceso' => 'ebanisteria',
            'orden' => 1, 'estado' => 'en_proceso', 'iniciado_at' => '2026-09-21 13:00:00']);

        // Para la Reserva: 3 camas en ebanistería, con alguien asignado.
        DB::table('produccion')->insert(['id' => 4, 'orden_item_id' => null, 'destino' => 'reserva', 'producto_id' => 11,
            'cantidad' => 3, 'estado' => 'en_proceso', 'fecha_inicio' => '2026-10-27']);
        DB::table('produccion_pasos')->insert(['id' => 5, 'produccion_id' => 4, 'tipo_proceso' => 'ebanisteria',
            'orden' => 1, 'estado' => 'en_proceso', 'iniciado_at' => '2026-10-27 15:00:00']);
        DB::table('paso_trabajadores')->insert(['paso_id' => 5, 'usuario_id' => 50]);

        // Lo que ebanistería cerró en las últimas cuatro semanas (más el paso 1 de B): 4 en 28 días.
        foreach (['2026-10-05', '2026-10-10', '2026-10-22'] as $i => $dia) {
            DB::table('produccion_pasos')->insert(['id' => 10 + $i, 'produccion_id' => 99, 'tipo_proceso' => 'ebanisteria',
                'orden' => 1, 'estado' => 'completado',
                'iniciado_at' => Carbon::parse("$dia 13:00:00")->subDays(2), 'completado_at' => "$dia 13:00:00"]);
        }
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
                                've_todas_ordenes' => true, 'created_at' => now()]);
    }

    public function test_cuenta_las_ordenes_abiertas_y_lo_que_hay_por_entregar(): void
    {
        $r = $this->actingAs($this->supervisor())->getJson('/api/carga-trabajo')->assertOk()->json('ordenes');

        // Ni la cancelada ni el borrador son trabajo.
        $this->assertSame(3, $r['abiertas']);
        $this->assertSame(1, $r['por_estado']['listo_entrega']);
        $this->assertSame(2, $r['por_estado']['en_produccion']);

        // A entera y el cojín de B se pueden llevar hoy; solo A está completa.
        $this->assertSame(2, $r['para_entregar']['con_algo_listo']);
        $this->assertSame(1, $r['para_entregar']['todo_listo']);
        $this->assertSame([1, 2], array_column($r['para_entregar']['lista'], 'id'), 'Lo vencido va primero');
        $this->assertSame(1, $r['para_entregar']['lista'][1]['unidades_listas']);

        // B (personalizado) y C (diseño especial) tienen trabajo de taller; C sin arrancar.
        $this->assertSame(2, $r['con_taller']);
        $this->assertSame(1, $r['con_taller_sin_arrancar']);
        $this->assertSame([3], array_column($r['sin_arrancar'], 'id'));
        $this->assertSame(1, $r['por_tipo']['personalizado']['ordenes']);
        $this->assertSame(1, $r['por_tipo']['diseno_especial']['ordenes']);

        // Fecha de la orden = lo más lejano que falta: B el 2 de nov.
        $this->assertSame(1, $r['atrasadas']);
        $this->assertSame(['vencidas' => 1, 'esta_semana' => 1, 'proxima_semana' => 0, 'este_mes' => 1, 'mas_adelante' => 0, 'sin_fecha' => 0], $r['calendario']);
    }

    public function test_cuenta_las_piezas_del_taller_y_la_cola_de_cada_paso(): void
    {
        $r = $this->actingAs($this->supervisor())->getJson('/api/carga-trabajo')->assertOk()->json('produccion');

        // B (1), C (1) y la Reserva (3). La de la orden cancelada no.
        $this->assertSame(['piezas' => 3, 'unidades' => 5], $r['en_taller_total']);
        $this->assertSame(['piezas' => 1, 'unidades' => 1], $r['sin_arrancar']);
        $this->assertSame(['piezas' => 2, 'unidades' => 4], $r['empezadas']);
        $this->assertSame(1, $r['sin_responsable'], 'El sofá está en tapicería sin nadie apuntado');

        $pasos = collect($r['pasos'])->keyBy('clave');
        $this->assertSame(['ebanisteria', 'tapiceria', 'despacho'], array_keys($pasos->all()), 'Despacho va de último');

        $this->assertSame(1, $pasos['ebanisteria']['en_proceso']);
        $this->assertSame(3, $pasos['ebanisteria']['unidades_en_proceso']);
        $this->assertSame(4, $pasos['ebanisteria']['hechos_recientes']);
        $this->assertSame(1.0, (float) $pasos['ebanisteria']['ritmo_semana']);
        $this->assertSame(2.0, (float) $pasos['ebanisteria']['dias_normales']);

        $this->assertSame(1, $pasos['tapiceria']['en_proceso']);
        $this->assertSame(1, $pasos['tapiceria']['sin_responsable']);
        $this->assertSame(2.0, (float) $pasos['tapiceria']['dias_promedio_actual']);
        $this->assertSame(2, $pasos['tapiceria']['personas'], 'El retirado no cuenta');

        // El despacho del sofá ya está puesto: le llega.
        $this->assertSame(0, $pasos['despacho']['en_proceso']);
        $this->assertSame(1, $pasos['despacho']['en_cola']);
        $this->assertSame(1, $pasos['despacho']['siguiente']);

        $c = collect($r['piezas'])->firstWhere('id', 2);
        $this->assertSame('sin_arrancar', $c['etapa']);
        $this->assertSame('diseno_especial', $c['tipo']);
        $this->assertSame(8, $c['dias_esperando']);

        $this->assertSame('reserva', collect($r['piezas'])->firstWhere('id', 4)['tipo']);
    }

    public function test_por_tienda_deja_fuera_lo_de_otras_y_la_reserva(): void
    {
        $r = $this->actingAs($this->supervisor())->getJson('/api/carga-trabajo?tienda_id=2')->assertOk()->json();

        $this->assertSame(1, $r['ordenes']['abiertas']);
        $this->assertSame([2], array_column($r['produccion']['piezas'], 'id'));
    }

    public function test_un_vendedor_sin_acceso_a_produccion_no_lo_ve(): void
    {
        $v = Usuario::create(['nombre' => 'Vende', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
                              'tienda_default_id' => 1, 'created_at' => now()]);

        $this->actingAs($v)->getJson('/api/carga-trabajo')->assertForbidden();
    }

    public function test_quien_ve_produccion_si_lo_ve(): void
    {
        $u = Usuario::create(['nombre' => 'Taller', 'email' => 't@d.com', 'password' => 'x', 'rol' => 'trabajador',
                              'acceso_produccion' => true, 'created_at' => now()]);

        $this->actingAs($u)->getJson('/api/carga-trabajo')->assertOk()->assertJsonPath('ordenes.abiertas', 3);
    }
}
