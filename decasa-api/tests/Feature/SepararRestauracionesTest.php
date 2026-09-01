<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las restauraciones y los muebles nuevos, cada uno con su encargado.
 *
 * Los pasos son los mismos —tapizar es tapizar—, pero no siempre los hace la
 * misma gente: el taller quiere que el ebanista lleve TODAS las
 * restauraciones y otra persona lo nuevo. Antes "estar en tapizado" era una
 * sola cosa y le llegaba todo a todos.
 *
 * Lo que se comprueba aquí es lo que de verdad duele si se rompe:
 *  - apagado, nada cambia (es un interruptor, y se puede volver atrás);
 *  - encendido, cada quien ve solo lo suyo y no puede cerrar lo del otro;
 *  - quien quedó en "las dos" sigue viéndolo todo.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class SepararRestauracionesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('apto_produccion')->default(false);
            $t->boolean('no_usa_programa')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('en_produccion');
            $t->decimal('valor_total', 12, 2)->default(0); $t->unsignedInteger('numero_orden')->nullable();
            $t->timestamp('listo_entrega_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(true);
            $t->boolean('es_restauracion')->default(false); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal');
            $t->unsignedTinyInteger('orden')->default(1); $t->string('estado')->default('pendiente');
            $t->timestamp('iniciado_at')->nullable(); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->json('trabajadores')->nullable();
            $t->unsignedInteger('rechazos')->default(0); $t->text('ultimo_rechazo')->nullable();
            $t->unsignedBigInteger('rechazado_por_id')->nullable(); $t->timestamp('rechazado_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1); $t->string('descripcion')->nullable();
            $t->string('color')->default('slate'); $t->timestamps();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('asignado_por')->nullable(); $t->timestamp('asignado_at')->nullable();
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable();
            $t->text('comentario')->nullable(); $t->unsignedBigInteger('calificado_por')->nullable();
            $t->timestamp('calificado_at')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 1, 'nombre' => 'Sofá 3 puestos', 'categoria' => 'sofás']);
        DB::table('tipos_proceso')->insert([
            'id' => 1, 'clave' => 'tapizado', 'nombre' => 'Tapizado', 'activo' => true, 'orden' => 1,
        ]);

        TipoProceso::olvidarCache();
    }

    protected function tearDown(): void
    {
        TipoProceso::olvidarCache();
        parent::tearDown();
    }

    /** Una pieza en el taller, con el tapizado ya arrancado. */
    private function pieza(bool $esRestauracion): ProduccionPaso
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'en_produccion', 'valor_total' => 1000000]);

        $item = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 1, 'cantidad' => 1,
                                   'precio_unitario' => 1000000, 'es_personalizado' => true,
                                   'es_restauracion' => $esRestauracion]);

        $prod = Produccion::create(['orden_item_id' => $item->id, 'estado' => 'en_proceso',
                                    'fecha_inicio' => now()->toDateString()]);

        return ProduccionPaso::create([
            'produccion_id' => $prod->id,
            'tipo_proceso'  => 'tapizado',
            'linea'         => TipoProceso::lineaDe($esRestauracion),
            'orden'         => 1,
            'estado'        => 'en_proceso',
            'iniciado_at'   => now(),
        ]);
    }

    /** Alguien encargado del tapizado, en la línea que se le diga. */
    private function encargado(string $nombre, string $linea): Usuario
    {
        $u = Usuario::create(['nombre' => $nombre, 'email' => "$nombre@d.com", 'password' => 'x',
                              'rol' => 'trabajador', 'apto_produccion' => true, 'created_at' => now()]);

        DB::table('proceso_trabajadores')->insert([
            'usuario_id' => $u->id, 'tipo_proceso_id' => 1, 'linea' => $linea,
        ]);

        return $u;
    }

    private function misPasos(Usuario $u): array
    {
        return collect($this->actingAs($u)->getJson('/api/produccion/mis-pasos')->assertOk()->json())
            ->pluck('id')->all();
    }

    public function test_apagado_el_tapizado_le_llega_a_todos_igual_que_antes(): void
    {
        $nueva  = $this->pieza(esRestauracion: false);
        $vieja  = $this->pieza(esRestauracion: true);
        $quien  = $this->encargado('Monica', 'normal');

        // El interruptor está apagado: la línea de cada quien no decide nada,
        // aunque esté puesta. Volver atrás no puede depender de re-repartir.
        $suyos = $this->misPasos($quien);

        $this->assertContains($nueva->id, $suyos);
        $this->assertContains($vieja->id, $suyos);
    }

    public function test_encendido_cada_quien_ve_solo_su_linea(): void
    {
        TipoProceso::definirSeparacion(true);

        $nueva = $this->pieza(esRestauracion: false);
        $vieja = $this->pieza(esRestauracion: true);

        $monica  = $this->encargado('Monica', 'normal');
        $henry   = $this->encargado('Henry', 'restauracion');

        $this->assertSame([$nueva->id], $this->misPasos($monica));
        $this->assertSame([$vieja->id], $this->misPasos($henry));
    }

    public function test_quien_quedo_en_las_dos_las_sigue_viendo(): void
    {
        TipoProceso::definirSeparacion(true);

        $nueva = $this->pieza(esRestauracion: false);
        $vieja = $this->pieza(esRestauracion: true);
        $todo  = $this->encargado('Admin', 'ambas');

        $suyos = $this->misPasos($todo);

        $this->assertContains($nueva->id, $suyos);
        $this->assertContains($vieja->id, $suyos);
    }

    public function test_no_se_puede_cerrar_un_paso_de_la_otra_linea(): void
    {
        TipoProceso::definirSeparacion(true);

        $vieja  = $this->pieza(esRestauracion: true);
        $monica = $this->encargado('Monica', 'normal');

        // Que no salga en la lista no basta: la pantalla pudo quedar abierta
        // desde antes de repartir, o llegar por una notificación vieja.
        $this->actingAs($monica)
            ->patchJson("/api/produccion/pasos/{$vieja->id}/completar", [
                'trabajadores' => [['usuario_id' => $monica->id, 'tiempo' => 3, 'unidad' => 'hora']],
            ])
            ->assertStatus(403);

        $this->assertSame('en_proceso', $vieja->fresh()->estado);
    }

    public function test_el_encargado_de_restauraciones_si_cierra_la_suya(): void
    {
        TipoProceso::definirSeparacion(true);

        $vieja = $this->pieza(esRestauracion: true);
        $henry = $this->encargado('Henry', 'restauracion');

        $this->actingAs($henry)
            ->patchJson("/api/produccion/pasos/{$vieja->id}/completar", [
                'trabajadores' => [['usuario_id' => $henry->id, 'tiempo' => 1, 'unidad' => 'dia']],
            ])
            ->assertOk();

        $this->assertSame('completado', $vieja->fresh()->estado);
    }

    public function test_al_arrancar_una_pieza_sus_pasos_nacen_con_su_linea(): void
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'en_produccion', 'valor_total' => 500000]);
        $item  = OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Mueble del cliente',
                                    'cantidad' => 1, 'precio_unitario' => 500000,
                                    'es_personalizado' => true, 'es_restauracion' => true]);
        $prod  = Produccion::create(['orden_item_id' => $item->id, 'estado' => 'pendiente',
                                     'fecha_inicio' => now()->toDateString()]);

        $jefa = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                 'rol' => 'supervisor', 'gestiona_produccion' => true,
                                 'acceso_produccion' => true, 'created_at' => now()]);

        $this->actingAs($jefa)
            ->patchJson("/api/produccion/{$prod->id}", [
                'estado' => 'en_proceso',
                'pasos'  => [['tipo_proceso' => 'tapizado', 'orden' => 1]],
            ])
            ->assertOk();

        // Todos los pasos, incluido el de despacho que se añade solo.
        $lineas = ProduccionPaso::where('produccion_id', $prod->id)->pluck('linea')->unique()->all();
        $this->assertSame(['restauracion'], $lineas);
    }
}
