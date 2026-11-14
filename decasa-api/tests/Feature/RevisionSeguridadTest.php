<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\UrlsPermitidas;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo que se cerró en la revisión de seguridad del 2026-11-14.
 *
 * - Una cuenta desactivada seguía entrando con la sesión que ya tenía (60 días).
 * - Los canales en tiempo real eran públicos: cualquiera con la clave de
 *   Reverb escuchaba avisos, chats y conversaciones de WhatsApp sin sesión.
 * - Cualquier sesión cambiaba fechas de entrega, precios de productos y veía
 *   las conversaciones de Redes.
 * - El PDF descargaba direcciones que no eran fotos.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RevisionSeguridadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('facturacion')->default(false); $t->boolean('acceso_redes')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
    }

    private function usuario(string $rol, array $mas = []): Usuario
    {
        return Usuario::create(['nombre' => ucfirst($rol), 'rol' => $rol] + $mas);
    }

    public function test_una_cuenta_desactivada_no_entra_con_la_sesion_que_ya_tenia(): void
    {
        $u = $this->usuario('vendedor');
        $token = $u->createToken('decasa-token')->plainTextToken;

        $u->update(['activo' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/redes/conversaciones')
            ->assertStatus(401);
    }

    public function test_fechas_de_entrega_solo_las_asigna_un_supervisor(): void
    {
        Sanctum::actingAs($this->usuario('conductor'));

        $this->patchJson('/api/ordenes/1/fechas-entrega', ['items' => [['id' => 1, 'fecha' => '2026-12-01']]])
            ->assertStatus(403);
    }

    public function test_el_precio_de_un_producto_solo_lo_cambia_un_supervisor(): void
    {
        Sanctum::actingAs($this->usuario('vendedor'));

        $this->patchJson('/api/productos/1', ['precio_base' => 1])->assertStatus(403);
    }

    public function test_redes_pide_el_permiso_de_redes(): void
    {
        Sanctum::actingAs($this->usuario('conductor'));

        $this->getJson('/api/redes/conversaciones')->assertStatus(403);
        $this->postJson('/api/redes/conversaciones/1/tomar')->assertStatus(403);
    }

    /** Con un broadcaster de verdad (Reverb), las reglas de channels.php se aplican. */
    private function conReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key'    => 'clave-prueba',
            'broadcasting.connections.reverb.secret' => 'secreto-prueba',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_los_avisos_de_una_persona_solo_los_escucha_ella(): void
    {
        $this->conReverb();
        $yo   = $this->usuario('vendedor');
        $otro = $this->usuario('supervisor');
        Sanctum::actingAs($yo);

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-notificaciones.{$yo->id}"])
            ->assertOk();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-notificaciones.{$otro->id}"])
            ->assertStatus(403);
    }

    public function test_sin_sesion_no_se_escucha_ningun_canal(): void
    {
        $this->conReverb();

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-ordenes'])
            ->assertStatus(401);
    }

    public function test_redes_en_vivo_solo_con_el_permiso(): void
    {
        $this->conReverb();
        Sanctum::actingAs($this->usuario('conductor'));
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-redes'])
            ->assertStatus(403);

        Sanctum::actingAs($this->usuario('vendedor', ['acceso_redes' => true]));
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-redes'])
            ->assertOk();
    }

    public function test_todos_los_eventos_van_por_canal_privado(): void
    {
        foreach (glob(app_path('Events/*.php')) as $archivo) {
            $codigo = file_get_contents($archivo);
            $this->assertStringNotContainsString('new Channel(', $codigo, basename($archivo) . ' usa un canal público.');
        }
    }

    public function test_el_pdf_solo_descarga_fotos_de_cloudinary_o_s3(): void
    {
        $this->assertTrue(UrlsPermitidas::esFoto('https://res.cloudinary.com/decasa/image/upload/x.jpg'));
        $this->assertTrue(UrlsPermitidas::esFoto('https://bucket.s3.amazonaws.com/x.png'));

        $this->assertFalse(UrlsPermitidas::esFoto('https://s3.ataque.com/x.png'));
        $this->assertFalse(UrlsPermitidas::esFoto('https://res.cloudinary.com.ataque.com/x.png'));
        $this->assertFalse(UrlsPermitidas::esFoto('http://res.cloudinary.com/x.png'));
        $this->assertFalse(UrlsPermitidas::esFoto('/var/www/html/.env'));
        $this->assertFalse(UrlsPermitidas::esFoto('http://169.254.169.254/latest/meta-data'));
    }

    public function test_los_avisos_push_solo_van_a_servicios_de_navegador(): void
    {
        $this->assertTrue(UrlsPermitidas::esServicioPush('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertTrue(UrlsPermitidas::esServicioPush('https://web.push.apple.com/abc'));
        $this->assertTrue(UrlsPermitidas::esServicioPush('https://updates.push.services.mozilla.com/wpush/v2/abc'));
        $this->assertTrue(UrlsPermitidas::esServicioPush('https://wns2-bl2p.notify.windows.com/w/?token=abc'));

        $this->assertFalse(UrlsPermitidas::esServicioPush('https://ataque.com/push'));
        $this->assertFalse(UrlsPermitidas::esServicioPush('http://localhost:8080/x'));
    }
}
