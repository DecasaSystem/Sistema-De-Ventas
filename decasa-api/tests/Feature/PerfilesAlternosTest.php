<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Una cuenta alterna con hasta tres personas más (cuatro perfiles en total),
 * y la lista —con su orden— se guarda en la cuenta, no en el aparato.
 *
 * Antes era un solo `perfil_alterno_id`: en un mostrador donde se turnan
 * tres o cuatro personas eso obligaba a cerrar sesión igual.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class PerfilesAlternosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('google_id')->nullable(); $t->string('rol')->nullable();
            $t->unsignedBigInteger('rol_id')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('no_usa_programa')->default(false); $t->boolean('facturacion')->default(false);
            $t->boolean('independiente')->default(false); $t->boolean('acceso_redes')->default(false);
            $t->boolean('acceso_comisiones')->default(false); $t->boolean('recarga_telas')->default(false);
            $t->boolean('acceso_telas')->default(false); $t->boolean('acceso_surtir')->default(false);
            $t->boolean('acceso_costos')->default(false); $t->boolean('acceso_proveedores')->default(false);
            $t->boolean('acceso_despacho')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_reserva')->default(false);
            $t->boolean('acceso_nomina')->default(false); $t->boolean('acceso_compras')->default(false);
            $t->boolean('acceso_encargos')->default(false); $t->boolean('revisa_encargos')->default(false);
            $t->boolean('lleva_encargos')->default(false); $t->boolean('ve_todas_ordenes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->string('firma_url')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable(); $t->boolean('comisiones_compartidas')->default(false); });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('perfiles_alternos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('alterno_id');
            $t->unsignedTinyInteger('posicion')->default(0); $t->timestamp('autorizado_at')->nullable(); $t->timestamps();
            $t->unique(['usuario_id', 'alterno_id']);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });

        foreach (['Mónica', 'Andrés', 'Laura', 'Camila', 'Pedro'] as $i => $n) {
            Usuario::create(['nombre' => $n, 'email' => strtolower($n) . '@decasa.com', 'password' => Hash::make('x'), 'rol' => 'vendedor']);
        }
    }

    private function yo(): Usuario
    {
        return Usuario::find(1);
    }

    public function test_guarda_hasta_tres_y_los_devuelve_en_orden(): void
    {
        $this->actingAs($this->yo())
            ->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [3, 2, 4]])
            ->assertOk()->assertJson(['usuario_ids' => [3, 2, 4]]);

        $me = $this->actingAs($this->yo())->getJson('/api/auth/me')->assertOk()->json();
        $this->assertSame([3, 2, 4], array_column($me['perfiles_alternos'], 'id'), 'en el orden en que se agregaron');
        $this->assertSame('Laura', $me['perfiles_alternos'][0]['nombre']);
        $this->assertSame('laura@decasa.com', $me['perfiles_alternos'][0]['email']);
    }

    public function test_quitar_al_del_medio_deja_a_los_demas_donde_iban(): void
    {
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [3, 2, 4]])->assertOk();
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [3, 4]])->assertOk();

        $me = $this->actingAs($this->yo())->getJson('/api/auth/me')->assertOk()->json();
        $this->assertSame([3, 4], array_column($me['perfiles_alternos'], 'id'));

        // Lista vacía: se quitan todos.
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => []])->assertOk();
        $this->assertSame([], $this->actingAs($this->yo())->getJson('/api/auth/me')->json('perfiles_alternos'));
    }

    public function test_no_acepta_mas_de_tres_ni_a_uno_mismo_ni_repetidos(): void
    {
        $this->actingAs($this->yo())
            ->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [2, 3, 4, 5]])
            ->assertStatus(422);

        $this->actingAs($this->yo())
            ->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [2, 1]])
            ->assertStatus(422);

        $this->actingAs($this->yo())
            ->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [2, 2]])
            ->assertStatus(422);

        $this->actingAs($this->yo())
            ->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [999]])
            ->assertStatus(422);
    }

    /*
     * Los perfiles ya no viven solo en el aparato: Mónica entra desde otro
     * celular (o el navegador borró sus datos) y los compañeros que ya
     * autorizaron le aparecen listos, sin volver a escribir contraseñas.
     */

    private function sesiones(array $tengo = []): array
    {
        return $this->actingAs($this->yo())
            ->postJson('/api/auth/perfiles-alternos/sesiones', ['tengo' => $tengo])
            ->assertOk()->json();
    }

    public function test_anotar_a_alguien_no_le_abre_la_sesion_sin_que_el_autorice(): void
    {
        // Sin esto, cualquiera anotaba al supervisor y se quedaba con su sesión.
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [2]])->assertOk();

        $r = $this->sesiones();
        $this->assertSame([], $r['sesiones']);
        $this->assertFalse($r['perfiles_alternos'][0]['autorizado']);
    }

    public function test_quien_autorizo_se_abre_solo_en_otro_aparato(): void
    {
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [3, 2]])->assertOk();
        // Laura y Andrés escribieron su contraseña en el PC: con su sesión autorizan.
        $this->actingAs(Usuario::find(3))->postJson('/api/auth/perfiles-alternos/confirmar', ['principal_id' => 1])->assertOk();
        $this->actingAs(Usuario::find(2))->postJson('/api/auth/perfiles-alternos/confirmar', ['principal_id' => 1])->assertOk();

        // En el celular, sin nada guardado: llegan los dos, en orden y con token.
        $r = $this->sesiones();
        $this->assertSame([3, 2], array_column($r['sesiones'], 'id'));
        $this->assertNotEmpty($r['sesiones'][0]['token']);
        $this->assertSame('laura@decasa.com', $r['sesiones'][0]['email']);
        $this->assertTrue($r['perfiles_alternos'][0]['autorizado']);

        // Un aparato que ya tiene a Laura no recibe otro token suyo.
        $this->assertSame([2], array_column($this->sesiones([3])['sesiones'], 'id'));

        // El token entregado sirve para entrar como Laura.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $r['sesiones'][0]['token'])
            ->getJson('/api/auth/me')->assertOk()->assertJson(['id' => 3]);
    }

    public function test_no_se_autoriza_un_vinculo_que_la_principal_no_anoto(): void
    {
        $this->actingAs(Usuario::find(3))
            ->postJson('/api/auth/perfiles-alternos/confirmar', ['principal_id' => 1])
            ->assertStatus(404);
        $this->assertSame([], $this->sesiones()['perfiles_alternos']);
    }

    public function test_reordenar_conserva_la_autorizacion(): void
    {
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [3]])->assertOk();
        $this->actingAs(Usuario::find(3))->postJson('/api/auth/perfiles-alternos/confirmar', ['principal_id' => 1])->assertOk();
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [2, 3]])
            ->assertOk()->assertJsonPath('perfiles_alternos.1.autorizado', true);

        $this->assertSame([3], array_column($this->sesiones()['sesiones'], 'id'));
    }

    public function test_desactivado_o_con_contrasena_nueva_ya_no_se_abre_solo(): void
    {
        $this->actingAs($this->yo())->patchJson('/api/auth/mis-perfiles-alternos', ['usuario_ids' => [3, 2]])->assertOk();
        $this->actingAs(Usuario::find(3))->postJson('/api/auth/perfiles-alternos/confirmar', ['principal_id' => 1])->assertOk();
        $this->actingAs(Usuario::find(2))->postJson('/api/auth/perfiles-alternos/confirmar', ['principal_id' => 1])->assertOk();

        // A Andrés lo desactivaron: mismo candado que al entrar.
        Usuario::find(2)->update(['activo' => false]);
        // Laura cambió su contraseña: retira el permiso que había dado.
        $this->actingAs(Usuario::find(3))->patchJson('/api/auth/mi-cuenta', [
            'password_actual' => 'x', 'password_nuevo' => 'nueva-clave', 'password_nuevo_confirmation' => 'nueva-clave',
        ])->assertOk();

        $r = $this->sesiones();
        $this->assertSame([], $r['sesiones']);
        $this->assertFalse($r['perfiles_alternos'][0]['autorizado'], 'Laura sigue anotada, pero sin autorizar');
    }
}
