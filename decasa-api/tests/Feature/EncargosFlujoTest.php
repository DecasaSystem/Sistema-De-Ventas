<?php

namespace Tests\Feature;

use App\Models\Encargo;
use App\Models\EncargoRevision;
use App\Models\NominaAjuste;
use App\Models\Usuario;
use App\Services\RevisionEncargos;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El flujo de encargos de punta a punta, porque acá se mueve plata: lo que se
 * marca como perdido termina en un descuento de nómina.
 *
 * El esquema se monta a mano en vez de correr las migraciones: el historial
 * completo no pasa en SQLite (hay migraciones viejas con `ALTER ... MODIFY`,
 * que es sintaxis de MySQL). Se crean solo las tablas de las que depende el
 * módulo y encima se corre la migración de encargos de verdad, que es la que
 * interesa probar.
 */
class EncargosFlujoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('nomina_pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('periodicidad')->default('quincenal');
            $t->date('fecha_inicio'); $t->date('fecha_fin'); $t->timestamps();
        });
        Schema::create('nomina_ajustes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
            $t->date('fecha'); $t->string('nombre'); $t->decimal('monto', 12, 2); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->id(); $t->string('clave')->unique(); $t->text('valor')->nullable(); $t->timestamp('updated_at')->nullable();
        });

        (require database_path('migrations/2026_09_04_000001_encargos_de_los_trabajadores.php'))->up();
        (require database_path('migrations/2026_09_05_000001_quien_hace_las_revisiones_de_encargos.php'))->up();
    }

    private function trabajador(array $attrs = []): Usuario
    {
        return Usuario::create($attrs + [
            'nombre' => 'Adrián', 'rol' => 'lijador', 'activo' => true,
            'no_usa_programa' => true, 'lleva_encargos' => true, 'created_at' => now(),
        ]);
    }

    private function jefe(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Juan David', 'email' => 'jd@decasa.com', 'password' => 'x',
            'rol' => 'supervisor', 'activo' => true,
            'acceso_encargos' => true, 'revisa_encargos' => true, 'created_at' => now(),
        ]);
    }

    public function test_entregar_prende_el_modulo_y_suma_al_valor_repartido(): void
    {
        $jefe  = $this->jefe();
        $nuevo = Usuario::create(['nombre' => 'Nuevo', 'rol' => 'lijador', 'activo' => true, 'created_at' => now()]);

        $this->actingAs($jefe)->postJson('/api/encargos', [
            'usuario_id' => $nuevo->id, 'nombre' => 'Taladro Bosch', 'cantidad' => 2,
            'valor_unitario' => 300000, 'fecha_entrega' => '2026-08-01',
        ])->assertCreated();

        // Se le entregó algo, así que el módulo se le prende solo: no hace
        // falta acordarse de marcar la casilla en su ficha primero.
        $this->assertTrue($nuevo->fresh()->lleva_encargos);

        $r = $this->actingAs($jefe)->getJson('/api/encargos/trabajadores')->assertOk();
        $this->assertEquals(600000, $r->json('valor_total'));
        $this->assertSame(2, $r->json('trabajadores.0.piezas'));
    }

    public function test_la_revista_tiene_que_cuadrar_con_lo_que_tiene_a_cargo(): void
    {
        $jefe = $this->jefe();
        $t    = $this->trabajador();
        $e    = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 2,
                                 'valor_unitario' => 300000, 'fecha_entrega' => '2026-08-01']);

        // 1 + 0 + 0 no son las 2 que tiene.
        $this->actingAs($jefe)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25',
            'items' => [['encargo_id' => $e->id, 'cantidad_ok' => 1, 'cantidad_danada' => 0, 'cantidad_perdida' => 0]],
        ])->assertStatus(422);

        $this->assertSame(0, EncargoRevision::count());
    }

    public function test_no_se_puede_contar_a_medias(): void
    {
        $jefe = $this->jefe();
        $t    = $this->trabajador();
        $uno  = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 1, 'fecha_entrega' => '2026-08-01']);
        Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Martillo', 'cantidad' => 1, 'fecha_entrega' => '2026-08-01']);

        $this->actingAs($jefe)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25',
            'items' => [['encargo_id' => $uno->id, 'cantidad_ok' => 1, 'cantidad_danada' => 0, 'cantidad_perdida' => 0]],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Falta contar: Martillo. La revisión tiene que cubrir todo lo que tiene a cargo.']);
    }

    public function test_lo_perdido_se_descuenta_de_lo_que_tiene_y_se_le_cobra(): void
    {
        $jefe = $this->jefe();
        $t    = $this->trabajador();
        $e    = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 2,
                                 'valor_unitario' => 300000, 'fecha_entrega' => '2026-08-01']);

        $this->actingAs($jefe)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25', 'descontar' => true,
            'items' => [['encargo_id' => $e->id, 'cantidad_ok' => 1, 'cantidad_danada' => 0, 'cantidad_perdida' => 1]],
        ])->assertCreated();
        $this->assertEquals(300000, EncargoRevision::first()->descuento_total);

        $e->refresh();
        // Le queda uno a cargo: si no se le restara, en la próxima revista se
        // le volvería a contar —y a cobrar— el que ya se perdió.
        $this->assertSame(1, $e->cantidad);
        $this->assertSame('a_cargo', $e->estado);

        $ajuste = NominaAjuste::first();
        $this->assertSame('-300000.00', $ajuste->monto);
        $this->assertSame($t->id, $ajuste->usuario_id);
    }

    public function test_perder_todo_cierra_el_encargo_sin_borrar_cuantas_eran(): void
    {
        $jefe = $this->jefe();
        $t    = $this->trabajador();
        $e    = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 2,
                                 'valor_unitario' => 300000, 'fecha_entrega' => '2026-08-01']);

        $this->actingAs($jefe)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25', 'descontar' => false,
            'items' => [['encargo_id' => $e->id, 'cantidad_ok' => 0, 'cantidad_danada' => 0, 'cantidad_perdida' => 2]],
        ])->assertCreated();

        $e->refresh();
        $this->assertSame('perdido', $e->estado);
        $this->assertSame(2, $e->cantidad);
        $this->assertSame('2026-08-25', $e->cerrado_en->toDateString());
        // Se pidió no cobrarlo: queda el registro de la pérdida, sin descuento.
        $this->assertSame(0, NominaAjuste::count());
    }

    public function test_lo_danado_sigue_a_su_cargo_marcado(): void
    {
        $jefe = $this->jefe();
        $t    = $this->trabajador();
        $e    = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 2, 'fecha_entrega' => '2026-08-01']);

        $this->actingAs($jefe)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25',
            'items' => [['encargo_id' => $e->id, 'cantidad_ok' => 1, 'cantidad_danada' => 1, 'cantidad_perdida' => 0]],
        ])->assertCreated();

        $e->refresh();
        $this->assertSame(2, $e->cantidad);
        $this->assertSame(1, $e->cantidad_danada);
        $this->assertSame('a_cargo', $e->estado);
    }

    public function test_si_el_ciclo_ya_se_pago_la_revista_se_guarda_igual_y_avisa(): void
    {
        $jefe = $this->jefe();
        $t    = $this->trabajador();
        $e    = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 1,
                                 'valor_unitario' => 300000, 'fecha_entrega' => '2026-08-01']);

        \App\Models\NominaPago::create([
            'usuario_id' => $t->id, 'fecha_inicio' => '2026-08-16', 'fecha_fin' => '2026-08-31',
        ]);

        $r = $this->actingAs($jefe)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25', 'descontar' => true,
            'items' => [['encargo_id' => $e->id, 'cantidad_ok' => 0, 'cantidad_danada' => 0, 'cantidad_perdida' => 1]],
        ])->assertCreated();

        // La revista no se pierde por no poder cobrar: eso sería tirar el
        // conteo entero.
        $this->assertSame(1, EncargoRevision::count());
        $this->assertSame(0, NominaAjuste::count());
        $this->assertStringContainsString('ya se le pagó', $r->json('aviso'));
    }

    public function test_cada_cuanto_toca_revisar(): void
    {
        $t = $this->trabajador();

        // Sin nada a cargo no hay revista pendiente.
        $this->assertSame('sin_encargos', RevisionEncargos::estadoDe($t)['estado']);

        // Nunca revisado: el reloj corre desde que se le entregó.
        Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 1,
                         'fecha_entrega' => now()->subDays(40)->toDateString()]);
        $t->refresh();
        $this->assertSame('vencida', RevisionEncargos::estadoDe($t)['estado']);

        // Con su propio ritmo, todavía no le toca.
        $t->update(['encargo_revision_dias' => 180]);
        $this->assertSame('al_dia', RevisionEncargos::estadoDe($t->fresh())['estado']);
    }

    public function test_nadie_ve_los_encargos_de_otro_sin_el_permiso(): void
    {
        $t     = $this->trabajador();
        $curioso = Usuario::create(['nombre' => 'Curioso', 'email' => 'c@d.com', 'password' => 'x',
                                    'rol' => 'vendedor', 'activo' => true, 'created_at' => now()]);

        $this->actingAs($curioso)->getJson("/api/encargos/trabajadores/{$t->id}")->assertStatus(403);
        // Pero la suya sí, sin necesitar permiso de administrar.
        $this->actingAs($curioso)->getJson('/api/encargos/mios')->assertOk();
        // Y no puede entregar ni revisar.
        $this->actingAs($curioso)->postJson('/api/encargos', [
            'usuario_id' => $t->id, 'nombre' => 'X', 'cantidad' => 1, 'fecha_entrega' => '2026-08-01',
        ])->assertStatus(403);
    }

    public function test_quien_solo_mira_no_entrega_ni_revisa(): void
    {
        $t     = $this->trabajador();
        $e     = Encargo::create(['usuario_id' => $t->id, 'nombre' => 'Taladro', 'cantidad' => 1, 'fecha_entrega' => '2026-08-01']);
        $mirón = Usuario::create(['nombre' => 'Mirón', 'email' => 'm@d.com', 'password' => 'x', 'rol' => 'vendedor',
                                  'activo' => true, 'acceso_encargos' => true, 'created_at' => now()]);

        // Ve quién tiene qué...
        $this->actingAs($mirón)->getJson('/api/encargos/trabajadores')->assertOk();
        $this->actingAs($mirón)->getJson("/api/encargos/trabajadores/{$t->id}")
            ->assertOk()->assertJsonPath('puede_revisar', false);

        // ...pero no toca nada.
        $this->actingAs($mirón)->postJson('/api/encargos', [
            'usuario_id' => $t->id, 'nombre' => 'X', 'cantidad' => 1, 'fecha_entrega' => '2026-08-01',
        ])->assertStatus(403);
        $this->actingAs($mirón)->postJson('/api/encargos/revisiones', [
            'usuario_id' => $t->id, 'fecha' => '2026-08-25',
            'items' => [['encargo_id' => $e->id, 'cantidad_ok' => 1, 'cantidad_danada' => 0, 'cantidad_perdida' => 0]],
        ])->assertStatus(403);
    }

    public function test_se_designa_desde_el_modulo_quien_hace_los_checks(): void
    {
        $jefe  = $this->jefe();
        $nuevo = Usuario::create(['nombre' => 'Encargada', 'email' => 'e@d.com', 'password' => 'x',
                                  'rol' => 'vendedor', 'activo' => true, 'created_at' => now()]);
        $fabrica = $this->trabajador(['nombre' => 'De fábrica']);

        $this->actingAs($jefe)->putJson('/api/encargos/revisores', [
            // El de fábrica se ignora: no entra al programa, así que nunca
            // vería el aviso ni podría abrir la revista.
            'usuario_ids' => [$nuevo->id, $fabrica->id],
        ])->assertOk()->assertJsonCount(1, 'revisores');

        $nuevo->refresh();
        $this->assertTrue($nuevo->revisa_encargos);
        // Quien revisa tiene que poder abrir el módulo: el acceso va implícito.
        $this->assertTrue($nuevo->acceso_encargos);
        $this->assertFalse($fabrica->fresh()->revisa_encargos);

        // Se manda la lista completa, así que el que no viene deja de serlo —
        // pero conserva el acceso de mirar, que es lo menos sorprendente.
        $this->assertFalse($jefe->fresh()->revisa_encargos);
        $this->assertTrue($jefe->fresh()->acceso_encargos);
    }
}
