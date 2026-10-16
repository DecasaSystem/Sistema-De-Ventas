<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
use App\Models\Usuario;
use App\Support\AjustesSistema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gestión → Ajustes: apagar las puntuaciones del taller.
 *
 * El dueño pidió poder quitar lo de calificar a los trabajadores cada vez que
 * una pieza pasa de un paso a otro (2026-10-16). Lo que importa:
 *  - sin tocar nada, se sigue calificando como siempre;
 *  - apagado, el servidor no guarda tiempo, estrellas ni opinión aunque la
 *    pantalla (una app vieja abierta) los mande, pero sí quién lo hizo;
 *  - solo un supervisor lo cambia.
 *
 * El esquema se monta a mano, como en SepararRestauracionesTest.
 */
class AjustesCalificarTallerTest extends TestCase
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
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false); });
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

    private function paso(): ProduccionPaso
    {
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'en_produccion', 'valor_total' => 1000000]);
        $item  = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 1, 'cantidad' => 1,
                                    'precio_unitario' => 1000000, 'es_personalizado' => true]);
        $prod  = Produccion::create(['orden_item_id' => $item->id, 'estado' => 'en_proceso',
                                     'fecha_inicio' => now()->toDateString()]);

        return ProduccionPaso::create([
            'produccion_id' => $prod->id, 'tipo_proceso' => 'tapizado', 'linea' => 'normal',
            'orden' => 1, 'estado' => 'en_proceso', 'iniciado_at' => now(),
        ]);
    }

    private function usuario(string $nombre, string $rol = 'trabajador'): Usuario
    {
        $u = Usuario::create(['nombre' => $nombre, 'email' => "$nombre@d.com", 'password' => 'x',
                              'rol' => $rol, 'apto_produccion' => true, 'created_at' => now()]);
        DB::table('proceso_trabajadores')->insert([
            'usuario_id' => $u->id, 'tipo_proceso_id' => 1, 'linea' => 'ambas',
        ]);

        return $u;
    }

    private function terminar(Usuario $quien, ProduccionPaso $paso)
    {
        return $this->actingAs($quien)->patchJson("/api/produccion/pasos/{$paso->id}/completar", [
            'trabajadores' => [[
                'usuario_id' => $quien->id, 'tiempo' => 3, 'unidad' => 'hora',
                'calidad' => 4, 'comentario' => 'Quedó bien',
            ]],
        ]);
    }

    public function test_sin_tocar_nada_se_sigue_calificando(): void
    {
        $paso   = $this->paso();
        $monica = $this->usuario('Monica');

        $this->getJson('/api/ajustes-sistema')->assertUnauthorized();
        $this->actingAs($monica)->getJson('/api/ajustes-sistema')
            ->assertOk()->assertJsonPath('calificar_trabajadores_taller', true);

        $this->terminar($monica, $paso)->assertOk();

        $fila = DB::table('paso_trabajadores')->where('paso_id', $paso->id)->first();
        $this->assertSame(4, (int) $fila->calidad);
        $this->assertEquals(3, (float) $fila->horas);
        $this->assertSame('Quedó bien', $fila->comentario);
        $this->assertNotNull($fila->calificado_por);
    }

    public function test_apagado_solo_se_guarda_quien_lo_hizo(): void
    {
        AjustesSistema::definir(AjustesSistema::CALIFICAR_TALLER, false);

        $paso   = $this->paso();
        $monica = $this->usuario('Monica');

        // Una app abierta desde antes manda estrellas igual: no se guardan.
        $this->terminar($monica, $paso)->assertOk();

        $this->assertSame('completado', $paso->fresh()->estado);
        $fila = DB::table('paso_trabajadores')->where('paso_id', $paso->id)->first();
        $this->assertSame($monica->id, (int) $fila->usuario_id);
        $this->assertNull($fila->calidad);
        $this->assertNull($fila->horas);
        $this->assertNull($fila->comentario);
        $this->assertNull($fila->calificado_por);
    }

    public function test_solo_un_supervisor_lo_cambia(): void
    {
        $monica = $this->usuario('Monica');
        $jefe   = $this->usuario('Jefe', 'supervisor');

        $this->actingAs($monica)->patchJson('/api/ajustes-sistema', ['calificar_trabajadores_taller' => false])
            ->assertForbidden();
        $this->assertTrue(AjustesSistema::calificaTaller());

        $this->actingAs($jefe)->patchJson('/api/ajustes-sistema', ['calificar_trabajadores_taller' => false])
            ->assertOk()->assertJsonPath('calificar_trabajadores_taller', false);
        $this->assertFalse(AjustesSistema::calificaTaller());

        // Y volver a encenderlo cuesta un clic.
        $this->actingAs($jefe)->patchJson('/api/ajustes-sistema', ['calificar_trabajadores_taller' => true])
            ->assertOk()->assertJsonPath('calificar_trabajadores_taller', true);
    }
}
