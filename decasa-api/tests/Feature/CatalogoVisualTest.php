<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Catálogos visuales: subir las páginas de un catálogo y verlas desde el link
 * público, sin descargar nada.
 *
 * Cubre lo que puede romperse en silencio: el slug único que sale del nombre,
 * el orden de las páginas, y que la parte pública no muestre lo apagado ni lo
 * vacío.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CatalogoVisualTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('catalogos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('slug')->unique();
            $t->string('descripcion')->nullable(); $t->string('portada_url')->nullable();
            $t->boolean('activo')->default(true); $t->unsignedInteger('orden')->default(0);
            $t->timestamps();
        });
        Schema::create('catalogo_paginas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('catalogo_id');
            $t->string('imagen_url'); $t->string('nota')->nullable();
            $t->unsignedInteger('orden')->default(0); $t->timestamps();
        });
    }

    private function jefe(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
            'rol' => 'supervisor', 'created_at' => now(),
        ]);
    }

    private function img(string $n): string
    {
        return "https://res.cloudinary.com/demo/image/upload/pag{$n}.jpg";
    }

    public function test_crear_catalogo_deriva_un_slug_unico_del_nombre(): void
    {
        $jefe = $this->jefe();

        $a = $this->actingAs($jefe)->postJson('/api/catalogos-visuales', ['nombre' => 'Salas y Sofás'])
            ->assertCreated()->json();
        $b = $this->actingAs($jefe)->postJson('/api/catalogos-visuales', ['nombre' => 'Salas y Sofás'])
            ->assertCreated()->json();

        $this->assertSame('salas-y-sofas', $a['slug']);
        $this->assertSame('salas-y-sofas-2', $b['slug']);
    }

    public function test_las_paginas_se_agregan_al_final_y_se_pueden_reordenar(): void
    {
        $jefe = $this->jefe();
        $id = $this->actingAs($jefe)->postJson('/api/catalogos-visuales', ['nombre' => 'Comedores'])->json('id');

        $this->actingAs($jefe)->postJson("/api/catalogos-visuales/{$id}/paginas", [
            'imagenes' => [$this->img('1'), $this->img('2'), $this->img('3')],
        ])->assertOk();

        $cat = $this->actingAs($jefe)->getJson("/api/catalogos-visuales/{$id}")->json();
        $this->assertEquals(
            [$this->img('1'), $this->img('2'), $this->img('3')],
            array_column($cat['paginas'], 'imagen_url'),
        );

        // Se le da la vuelta al orden.
        $ids = array_reverse(array_column($cat['paginas'], 'id'));
        $cat = $this->actingAs($jefe)->patchJson("/api/catalogos-visuales/{$id}/paginas/orden", ['orden' => $ids])->json();
        $this->assertEquals(
            [$this->img('3'), $this->img('2'), $this->img('1')],
            array_column($cat['paginas'], 'imagen_url'),
        );
    }

    public function test_el_publico_ve_las_paginas_en_orden_y_no_ve_lo_apagado(): void
    {
        $jefe = $this->jefe();
        $id = $this->actingAs($jefe)->postJson('/api/catalogos-visuales', ['nombre' => 'Relojes'])->json('id');
        $this->actingAs($jefe)->postJson("/api/catalogos-visuales/{$id}/paginas", [
            'imagenes' => [$this->img('1'), $this->img('2')],
        ]);

        $slug = 'relojes';
        $pub = $this->getJson("/api/c/{$slug}")->assertOk()->json();
        $this->assertEquals([$this->img('1'), $this->img('2')], array_column($pub['paginas'], 'imagen_url'));

        // La portada pública lo lista...
        $this->getJson('/api/c')->assertOk()->assertJsonPath('catalogos.0.slug', 'relojes');

        // ...y al apagarlo desaparece de ambos lados.
        $this->actingAs($jefe)->patchJson("/api/catalogos-visuales/{$id}", ['activo' => false])->assertOk();
        $this->getJson("/api/c/{$slug}")->assertNotFound();
        $this->getJson('/api/c')->assertOk()->assertJsonCount(0, 'catalogos');
    }

    public function test_un_catalogo_sin_paginas_no_sale_en_la_portada_publica(): void
    {
        $jefe = $this->jefe();
        $this->actingAs($jefe)->postJson('/api/catalogos-visuales', ['nombre' => 'Vacío'])->assertCreated();

        $this->getJson('/api/c')->assertOk()->assertJsonCount(0, 'catalogos');
    }

    public function test_solo_un_supervisor_administra_catalogos(): void
    {
        $vendedor = Usuario::create([
            'nombre' => 'Ana', 'email' => 'a@d.com', 'password' => 'x',
            'rol' => 'vendedor', 'created_at' => now(),
        ]);

        $this->actingAs($vendedor)->postJson('/api/catalogos-visuales', ['nombre' => 'X'])
            ->assertForbidden();
    }

    public function test_la_portada_elegida_tiene_que_ser_una_pagina_del_catalogo(): void
    {
        $jefe = $this->jefe();
        $id = $this->actingAs($jefe)->postJson('/api/catalogos-visuales', ['nombre' => 'Camas'])->json('id');
        $this->actingAs($jefe)->postJson("/api/catalogos-visuales/{$id}/paginas", ['imagenes' => [$this->img('1')]]);

        $this->actingAs($jefe)->patchJson("/api/catalogos-visuales/{$id}", ['portada_url' => 'https://otro.com/x.jpg'])
            ->assertStatus(422);

        $this->actingAs($jefe)->patchJson("/api/catalogos-visuales/{$id}", ['portada_url' => $this->img('1')])
            ->assertOk()->assertJsonPath('portada_url', $this->img('1'));
    }
}
