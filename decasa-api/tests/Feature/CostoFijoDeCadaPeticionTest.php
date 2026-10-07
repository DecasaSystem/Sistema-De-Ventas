<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que paga CADA llamada a la API antes de hacer su trabajo.
 *
 * En producción cada consulta a la base cuesta ~200 ms (está lejos del
 * servidor), y la API pagaba consultas que no hacían falta en todas las
 * peticiones: 8 del límite de peticiones, que había quedado guardando su
 * contador en la base, y un UPDATE de `last_used_at` del token. Medido: una
 * ruta que solo devuelve un texto tardaba 2,1 s. Ver docs/plan-rendimiento.md.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CostoFijoDeCadaPeticionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('google_id')->nullable(); $t->string('rol')->nullable();
            $t->unsignedBigInteger('rol_id')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('no_usa_programa')->default(false); $t->boolean('facturacion')->default(false);
            $t->boolean('independiente')->default(false); $t->boolean('ve_todas_ordenes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->string('firma_url')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable(); });
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
            $t->unsignedTinyInteger('posicion')->default(0); $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });

        Usuario::create(['nombre' => 'Mónica', 'email' => 'monica@decasa.com', 'password' => Hash::make('x'), 'rol' => 'vendedor']);
    }

    /** Las consultas que hace una petición, por texto. */
    private function consultasDe(callable $peticion): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });
        $peticion();
        return $sql;
    }

    public function test_el_limite_de_peticiones_no_guarda_su_contador_en_la_base(): void
    {
        // Lo que tenía producción en el panel: la caché por defecto en la base.
        // El límite tiene que ir a archivos de todas formas.
        $this->assertNotSame('database', config('cache.limiter'));
        $this->assertNotNull(config('cache.limiter'), 'sin valor caería en la caché por defecto');

        $sql = $this->consultasDe(fn () => $this->getJson('/api/push/vapid-key')->assertOk());

        $this->assertSame([], $sql, 'una ruta que solo devuelve un texto no debe tocar la base');
    }

    public function test_el_ultimo_uso_del_token_no_se_escribe_en_cada_peticion(): void
    {
        $token = Usuario::find(1)->createToken('app')->plainTextToken;
        $conToken = fn () => $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        // La primera vez se anota: el token nunca se había usado.
        $primera = $this->consultasDe($conToken);
        $this->assertCount(1, array_filter($primera, fn ($s) => str_contains($s, 'update "personal_access_tokens"')));

        // Un minuto después ya no: lo anotado todavía sirve.
        $this->travel(1)->minutes();
        $this->app['auth']->forgetGuards();
        $segunda = $this->consultasDe($conToken);
        $this->assertCount(0, array_filter($segunda, fn ($s) => str_contains($s, 'personal_access_tokens') && str_starts_with(strtolower($s), 'update')));

        // Pasado el intervalo se vuelve a anotar.
        $this->travel(11)->minutes();
        $this->app['auth']->forgetGuards();
        $tercera = $this->consultasDe($conToken);
        $this->assertCount(1, array_filter($tercera, fn ($s) => str_contains($s, 'update "personal_access_tokens"')));
    }

    public function test_el_token_sigue_funcionando_y_cerrar_sesion_lo_borra(): void
    {
        $token = Usuario::find(1)->createToken('app')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJson(['nombre' => 'Mónica']);
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_cada_respuesta_dice_cuanto_tardo_y_cuanto_fue_la_base(): void
    {
        $token = Usuario::find(1)->createToken('app')->plainTextToken;

        $cabecera = $this->withToken($token)->getJson('/api/auth/me')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/^app;dur=\d+, db;dur=\d+;desc="\d+ consultas"$/', (string) $cabecera);
    }
}
