<?php

namespace Tests\Feature;

use App\Models\TipoProceso;
use App\Models\Usuario;
use App\Services\TrabajoDelTaller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Nómina → Trabajo del taller: la hoja de lo que hizo cada trabajador, sacada
 * de Producción en vez de llenarla él a mano (usuario, 2026-11-27).
 *
 * Reglas que se cuidan: cada paso terminado del periodo cuenta completo para
 * cada uno de los que figuran; uno devuelto y rehecho cuenta una vez, para
 * quien lo terminó; garantías y restauraciones también; Despacho no; el
 * periodo es en hora de Bogotá; solo quien tiene acceso a Nómina.
 */
class TrabajoDelTallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('acceso_nomina')->default(false); $t->timestamp('created_at')->nullable();
        });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->integer('cantidad')->default(1);
            $t->string('variante_detalle')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->unsignedInteger('piezas_juego')->nullable();
            $t->boolean('es_pieza_suelta')->default(false); $t->boolean('es_restauracion')->default(false);
            $t->json('specs_personalizacion')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->string('destino')->default('orden');
            $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedInteger('cantidad')->default(1); $t->string('estado')->default('en_proceso');
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal'); $t->unsignedTinyInteger('orden')->default(1);
            $t->string('estado')->default('pendiente'); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->json('trabajadores')->nullable();
            $t->unsignedBigInteger('garantia_id')->nullable();
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
            $t->timestamp('asignado_at')->nullable(); $t->decimal('horas', 8, 2)->nullable(); $t->timestamps();
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1);
        });
        Schema::create('configuracion', function (Blueprint $t) { $t->string('clave')->primary(); $t->text('valor'); });

        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Jefa', 'rol' => 'supervisor', 'acceso_nomina' => true],
            ['id' => 2, 'nombre' => 'Jhon', 'rol' => 'tapicero', 'acceso_nomina' => false],
            ['id' => 3, 'nombre' => 'Pedro', 'rol' => 'tapicero', 'acceso_nomina' => false],
        ]);
        DB::table('productos')->insert([
            ['id' => 1, 'nombre' => 'Sofá Telavid'],
            ['id' => 2, 'nombre' => 'Cama Bali'],
        ]);
        DB::table('tipos_proceso')->insert([
            ['clave' => 'tapizado', 'nombre' => 'Tapizado'],
            ['clave' => 'lijado', 'nombre' => 'Lijado'],
            ['clave' => 'despacho', 'nombre' => 'Despacho'],
        ]);
        TipoProceso::olvidarCache();
    }

    protected function tearDown(): void
    {
        TipoProceso::olvidarCache();
        parent::tearDown();
    }

    /** Un paso terminado (fecha en UTC) de una pieza de venta, con sus trabajadores. */
    private function paso(string $proceso, string $terminadoUtc, array $trabajadores, array $item = [], array $paso = []): int
    {
        $itemId = DB::table('orden_items')->insertGetId(array_merge(
            ['orden_id' => 1, 'producto_id' => 1, 'cantidad' => 1, 'variante_detalle' => 'Taupe'], $item));
        $prodId = DB::table('produccion')->insertGetId(['orden_item_id' => $itemId]);

        return $this->pasoDe($prodId, $proceso, $terminadoUtc, $trabajadores, $paso);
    }

    private function pasoDe(int $prodId, string $proceso, ?string $terminadoUtc, array $trabajadores, array $paso = []): int
    {
        $id = DB::table('produccion_pasos')->insertGetId(array_merge([
            'produccion_id' => $prodId, 'tipo_proceso' => $proceso,
            'estado' => $terminadoUtc ? 'completado' : 'en_proceso', 'completado_at' => $terminadoUtc,
        ], $paso));
        foreach ($trabajadores as $u) {
            DB::table('paso_trabajadores')->insert(['paso_id' => $id, 'usuario_id' => $u]);
        }

        return $id;
    }

    private function de(int $usuarioId, string $desde = '2026-11-01', string $hasta = '2026-11-15'): ?array
    {
        return TrabajoDelTaller::porPersona($desde, $hasta)->firstWhere('usuario_id', $usuarioId);
    }

    public function test_cada_trabajador_del_paso_lo_tiene_completo(): void
    {
        $this->paso('tapizado', '2026-11-05 15:00:00', [2, 3], ['cantidad' => 4]);

        $this->assertSame(4.0, $this->de(2)['piezas']);
        $this->assertSame(4.0, $this->de(3)['piezas']);
        $this->assertNull($this->de(1));
    }

    public function test_los_iguales_se_juntan_en_un_renglon_y_se_separan_por_paso(): void
    {
        $this->paso('tapizado', '2026-11-03 15:00:00', [2], ['cantidad' => 2]);
        $this->paso('tapizado', '2026-11-04 15:00:00', [2], ['cantidad' => 1]);
        $this->paso('tapizado', '2026-11-04 16:00:00', [2], ['variante_detalle' => 'Gris']);
        $this->paso('lijado',   '2026-11-04 17:00:00', [2], ['producto_id' => 2, 'variante_detalle' => '1.40']);

        $jhon = $this->de(2);
        $this->assertSame(5.0, $jhon['piezas']);
        $this->assertSame(3, $jhon['trabajos']);
        $this->assertSame(['Lijado', 'Tapizado'], array_column($jhon['grupos'], 'proceso'));

        $tapizado = $jhon['grupos'][1]['renglones'];
        $this->assertSame([['Sofá Telavid', 'Gris', 1.0], ['Sofá Telavid', 'Taupe', 3.0]],
            array_map(fn ($r) => [$r['producto'], $r['version'], (float) $r['cantidad']], $tapizado));
    }

    public function test_solo_cuenta_lo_terminado_en_el_periodo_en_hora_de_bogota(): void
    {
        // 15 nov a las 8 p. m. en Bogotá = 16 nov 01:00 UTC: es de la primera quincena.
        $this->paso('tapizado', '2026-11-16 01:00:00', [2]);
        // 16 nov a las 7 a. m. en Bogotá: de la segunda.
        $this->paso('tapizado', '2026-11-16 12:00:00', [2]);
        // Uno sin terminar no cuenta.
        $prod = DB::table('produccion')->insertGetId(['producto_id' => 1, 'destino' => 'reserva']);
        $this->pasoDe($prod, 'tapizado', null, [2]);

        $this->assertSame(1.0, $this->de(2)['piezas']);
        $this->assertSame(1.0, $this->de(2, '2026-11-16', '2026-11-30')['piezas']);
    }

    public function test_despacho_no_sale_y_garantia_restauracion_y_reserva_si(): void
    {
        $this->paso('despacho', '2026-11-05 15:00:00', [2]);
        $this->paso('tapizado', '2026-11-05 15:00:00', [2], [], ['garantia_id' => 9]);
        $this->paso('tapizado', '2026-11-05 15:00:00', [2], ['es_restauracion' => true, 'variante_detalle' => null]);
        $prod = DB::table('produccion')->insertGetId(['producto_id' => 2, 'destino' => 'reserva', 'cantidad' => 3,
            'variante_detalle' => '1.60']);
        $this->pasoDe($prod, 'lijado', '2026-11-05 15:00:00', [2]);

        $jhon = $this->de(2);
        $this->assertSame(['Lijado', 'Tapizado'], array_column($jhon['grupos'], 'proceso'));
        $this->assertSame([['Cama Bali', '1.60', null, 3.0]],
            array_map(fn ($r) => [$r['producto'], $r['version'], $r['marca'], (float) $r['cantidad']], $jhon['grupos'][0]['renglones']));
        $this->assertEqualsCanonicalizing(['garantía', 'restauración'], array_column($jhon['grupos'][1]['renglones'], 'marca'));
    }

    public function test_un_paso_devuelto_y_rehecho_cuenta_una_vez_para_quien_lo_termino(): void
    {
        // Así queda en la base: una sola fila del paso, con quien lo cerró al final.
        $this->paso('tapizado', '2026-11-10 15:00:00', [3]);

        $this->assertNull($this->de(2));
        $this->assertSame(1.0, $this->de(3)['piezas']);
    }

    public function test_solo_quien_tiene_nomina_lo_ve_y_saca_la_hoja(): void
    {
        $this->paso('tapizado', '2026-11-05 15:00:00', [2, 3], ['cantidad' => 2]);
        $q = '?desde=2026-11-01&hasta=2026-11-15';

        $this->actingAs(Usuario::find(2))->getJson('/api/nomina/trabajo-taller' . $q)->assertForbidden();

        $this->actingAs(Usuario::find(1))->getJson('/api/nomina/trabajo-taller' . $q)
            ->assertOk()
            ->assertJsonPath('periodo', '1 al 15 de noviembre de 2026')
            ->assertJsonCount(2, 'personas');

        $this->actingAs(Usuario::find(1))->getJson('/api/nomina/trabajo-taller?desde=2026-11-01&hasta=2026-11-30')
            ->assertJsonPath('periodo', 'Noviembre de 2026');

        // Por ciclo: la quincena que contiene el 20 de noviembre.
        $this->actingAs(Usuario::find(1))->getJson('/api/nomina/trabajo-taller?periodicidad=quincenal&fecha=2026-11-20')
            ->assertJsonPath('desde', '2026-11-16')->assertJsonPath('hasta', '2026-11-30')
            ->assertJsonPath('periodo', '16 al 30 de noviembre de 2026');

        $todas = $this->actingAs(Usuario::find(1))->get('/api/nomina/trabajo-taller/pdf' . $q);
        $todas->assertOk();
        $this->assertStringStartsWith('%PDF', $todas->getContent());
        if ($dir = getenv('GUARDAR_PDF')) {
            file_put_contents($dir . '/trabajo_taller.pdf', $todas->getContent());
        }

        // La de alguien que no trabajó: sale con los renglones a mano.
        $this->actingAs(Usuario::find(1))->get('/api/nomina/trabajo-taller/pdf' . $q . '&usuario_id=1')->assertOk();

        // El PDF pone un límite de tiempo: que no mate las pruebas siguientes.
        set_time_limit(0);
    }
}
