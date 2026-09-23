<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las fotos del chat de la orden no subían nunca: el chat las mandaba a la
 * carpeta "chat-ordenes", que no estaba en la lista de las permitidas, y el
 * servidor las rechazaba con cualquier foto. Igual las devoluciones y
 * "regresar al taller".
 */
class SubirFotoACarpetasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });

        Http::fake(['api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/x/foto.jpg'])]);
    }

    private function subir(string $carpeta, UploadedFile $foto)
    {
        $u = Usuario::create(['nombre' => 'Paola', 'email' => 'p@d.com', 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);

        return $this->actingAs($u)->postJson('/api/upload/foto', ['foto' => $foto, 'folder' => $carpeta]);
    }

    public function test_el_chat_de_la_orden_sube_su_foto(): void
    {
        $this->subir('chat-ordenes', UploadedFile::fake()->image('chat.jpg', 1200, 900))
            ->assertOk()->assertJson(['url' => 'https://res.cloudinary.com/x/foto.jpg']);
    }

    public function test_devoluciones_y_regresar_al_taller_tambien(): void
    {
        $this->subir('devoluciones', UploadedFile::fake()->image('d.jpg'))->assertOk();
        $this->subir('produccion', UploadedFile::fake()->image('p.jpg'))->assertOk();
    }

    public function test_una_foto_de_iphone_en_heic_entra(): void
    {
        // El archivo falso viene vacío y no se puede reenviar a Cloudinary: lo
        // que se prueba es que la validación ya no lo rechaza.
        $this->subir('chat-ordenes', UploadedFile::fake()->create('IMG_0001.heic', 2000, 'image/heic'))
            ->assertJsonMissingValidationErrors('foto');
    }

    public function test_una_carpeta_que_no_existe_se_sigue_rechazando(): void
    {
        $this->subir('cualquiera', UploadedFile::fake()->image('x.jpg'))->assertStatus(422);
    }

    public function test_mas_de_10_mb_dice_por_que(): void
    {
        $this->subir('chat-ordenes', UploadedFile::fake()->image('grande.jpg')->size(11000))
            ->assertStatus(422)->assertJsonFragment(['La foto pesa más de 10 MB.']);
    }
}
