<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Entrar con Google.
 *
 * Aquí adentro hay caja, nómina y costos, así que el botón de Google no crea
 * cuentas ni abre la puerta a cualquiera con un correo: sólo deja entrar a
 * quien ya es usuario del programa, con los mismos candados que la contraseña.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class LoginGoogleTest extends TestCase
{
    private const CLIENT_ID = '123-decasa.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);

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
            $t->unsignedBigInteger('perfil_alterno_id')->nullable();
            $t->string('firma_url')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable(); });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
    }

    private function vendedora(array $extra = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre'   => 'Mónica',
            'email'    => 'monica@decasa.com',
            'password' => Hash::make('secreta123'),
            'rol'      => 'vendedor',
        ], $extra));
    }

    /** Lo que responde Google cuando el token es bueno. */
    private function googleResponde(array $campos = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(array_merge([
                'aud'            => self::CLIENT_ID,
                'sub'            => 'google-9988',
                'email'          => 'monica@decasa.com',
                'email_verified' => 'true',
            ], $campos), 200),
        ]);
    }

    public function test_entra_quien_ya_es_usuario(): void
    {
        $usuario = $this->vendedora();
        $this->googleResponde();

        $res = $this->postJson('/api/auth/google', ['credential' => 'token-de-google'])->assertOk();

        $this->assertNotEmpty($res->json('token'));
        $this->assertSame($usuario->id, $res->json('id'));
        $this->assertSame('Mónica', $res->json('nombre'));
        // La cuenta de Google queda anotada la primera vez.
        $this->assertSame('google-9988', $usuario->fresh()->google_id);
    }

    /**
     * El candado que sostiene todo lo demás: un token válido de OTRA
     * aplicación también lo firma Google. Sin comprobar para quién es,
     * cualquiera con un token sacado de otro sitio entraría a la caja.
     */
    public function test_un_token_de_otra_aplicacion_no_entra(): void
    {
        $this->vendedora();
        $this->googleResponde(['aud' => '999-otra-app.apps.googleusercontent.com']);

        $this->postJson('/api/auth/google', ['credential' => 'token-ajeno'])->assertStatus(401);
    }

    public function test_un_correo_sin_verificar_no_entra(): void
    {
        $this->vendedora();
        $this->googleResponde(['email_verified' => 'false']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(401);
    }

    public function test_un_token_que_google_rechaza_no_entra(): void
    {
        $this->vendedora();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->postJson('/api/auth/google', ['credential' => 'inventado'])->assertStatus(401);
    }

    /** Tener cuenta de Google no es tener cuenta en el programa. */
    public function test_un_correo_desconocido_no_crea_usuario(): void
    {
        $this->googleResponde(['email' => 'cualquiera@gmail.com']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(403);
        $this->assertSame(0, Usuario::count());
    }

    public function test_a_quien_esta_inactivo_no_le_sirve_google(): void
    {
        $this->vendedora(['activo' => false]);
        $this->googleResponde();

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(403);
    }

    /** El de fábrica no entra al programa: tampoco por esta puerta. */
    public function test_quien_no_usa_el_programa_no_entra(): void
    {
        $this->vendedora(['no_usa_programa' => true]);
        $this->googleResponde();

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(403);
    }

    /** Un correo que cambió de dueño no hereda la cuenta del anterior. */
    public function test_otra_cuenta_de_google_con_el_mismo_correo_no_entra(): void
    {
        $this->vendedora(['google_id' => 'google-1111']);
        $this->googleResponde(['sub' => 'google-2222']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(403);
    }

    public function test_sin_configurar_el_boton_no_sirve(): void
    {
        config(['services.google.client_id' => null]);
        $this->vendedora();

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(503);
    }

    /** Entrar con contraseña sigue funcionando igual. */
    public function test_la_contrasena_sigue_entrando(): void
    {
        $usuario = $this->vendedora();

        $res = $this->postJson('/api/auth/login', [
            'email' => 'monica@decasa.com', 'password' => 'secreta123',
        ])->assertOk();

        $this->assertSame($usuario->id, $res->json('id'));
        $this->assertNotEmpty($res->json('token'));
    }
}
