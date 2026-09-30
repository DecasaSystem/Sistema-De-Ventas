<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cada carpeta a la que la app manda fotos la tiene que aceptar el servidor.
 *
 * Pasó dos veces: una pantalla nueva mandaba su carpeta ("chat-ordenes",
 * después "soportes" para pedir un cambio de dinero), el servidor no la tenía
 * en la lista y rechazaba cualquier foto con un 422. Aquí se leen las
 * carpetas del código de la app y se sube una foto a cada una.
 */
class SubirFotoCarpetasTest extends TestCase
{
    /** Las carpetas que usa la app: append('folder', 'x') en el frontend. */
    private function carpetasDeLaApp(): array
    {
        $src = base_path('../decasa-app/src');
        if (! is_dir($src)) $this->markTestSkipped('No está el código de la app al lado.');

        $carpetas = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($it as $f) {
            if (! preg_match('/\.(vue|js)$/', $f->getFilename())) continue;
            preg_match_all("/append\\(\\s*'folder'\\s*,\\s*'([a-z-]+)'/", file_get_contents($f->getPathname()), $m);
            array_push($carpetas, ...$m[1]);
        }
        return array_values(array_unique($carpetas));
    }

    public function test_el_servidor_acepta_todas_las_carpetas_que_usa_la_app(): void
    {
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable(); $t->timestamps();
        });
        $u = Usuario::forceCreate(['nombre' => 'Paola', 'rol' => 'vendedor']);
        Http::fake(['api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/x/foto.jpg'])]);

        $carpetas = $this->carpetasDeLaApp();
        $this->assertContains('soportes', $carpetas);

        foreach ($carpetas as $carpeta) {
            $this->actingAs($u)->post('/api/upload/foto', [
                'foto'   => UploadedFile::fake()->image('soporte.jpg'),
                'folder' => $carpeta,
            ], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('url', 'https://res.cloudinary.com/x/foto.jpg');
        }
    }
}
