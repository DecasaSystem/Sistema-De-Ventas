<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La barra de abajo y el menú "Más" (2026-11-17): se guardan en la cuenta,
 * "Más" se puede dejar vacío y lo que ya va en la barra no se repite en "Más".
 * Una app vieja que solo manda la barra no le borra el "Más".
 */
class BarraDeAbajoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->json('nav_favoritos')->nullable();
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        (require base_path('database/migrations/2026_11_17_000004_nav_mas_en_usuarios.php'))->up();
    }

    public function test_guarda_la_barra_y_lo_que_va_en_mas(): void
    {
        $u = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);

        $this->actingAs($u)->patchJson('/api/auth/mi-nav', [
            'nav_favoritos' => ['dashboard', 'ordenes', 'nomina'],
            'nav_mas'       => ['inventario', 'nomina', 'despacho', 'inventario'],
        ])->assertOk();
        $u->refresh();
        $this->assertSame(['dashboard', 'ordenes', 'nomina'], $u->nav_favoritos);
        $this->assertSame(['inventario', 'despacho'], $u->nav_mas, 'sin repetidos ni lo de la barra');

        // Una app vieja solo manda la barra: "Más" no se toca.
        $this->actingAs($u)->patchJson('/api/auth/mi-nav', ['nav_favoritos' => ['dashboard']])->assertOk();
        $this->assertSame(['inventario', 'despacho'], $u->fresh()->nav_mas);

        // "Más" vacío se puede.
        $this->actingAs($u)->patchJson('/api/auth/mi-nav', ['nav_favoritos' => ['dashboard'], 'nav_mas' => []])->assertOk();
        $this->assertSame([], $u->fresh()->nav_mas);

        // Volver a la de siempre: los dos en null.
        $this->actingAs($u)->patchJson('/api/auth/mi-nav', ['nav_favoritos' => null, 'nav_mas' => null])->assertOk();
        $this->assertNull($u->fresh()->nav_favoritos);
        $this->assertNull($u->fresh()->nav_mas);
    }
}
