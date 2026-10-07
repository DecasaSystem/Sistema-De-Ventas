<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Eliminar un tipo de variante lo desactiva, pero su nombre seguía ocupado:
 * borrar "alas vintage" y crearlo otra vez decía "ya existe", sin que
 * apareciera en ninguna lista. Ahora el eliminado se vuelve a usar.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class TipoVarianteEliminadoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        // Igual que en la base: el nombre es único en toda la tabla.
        Schema::create('tipos_variante', function (Blueprint $t) {
            $t->id(); $t->string('nombre', 100)->unique(); $t->boolean('afecta_precio')->default(false);
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id'); $t->string('nombre', 100);
            $t->boolean('activo')->default(true); $t->timestamps();
            $t->unique(['tipo_variante_id', 'nombre']);
        });
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    public function test_se_puede_volver_a_crear_un_tipo_eliminado(): void
    {
        $jefa = $this->supervisor();
        $id = $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => 'alas vintage', 'afecta_precio' => false])
            ->assertCreated()->json('id');
        $this->actingAs($jefa)->postJson("/api/tipos-variante/{$id}/opciones", ['opciones' => ['Roble', 'Nogal']])->assertCreated();
        $this->actingAs($jefa)->deleteJson("/api/tipos-variante/{$id}")->assertOk();

        $resp = $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => ' alas vintage ', 'afecta_precio' => true])
            ->assertCreated();

        // Es el mismo, otra vez en uso, con lo nuevo y sin las opciones de antes.
        $this->assertSame($id, $resp->json('id'));
        $this->assertTrue($resp->json('afecta_precio'));
        $this->assertSame([], $resp->json('opciones'));
        $this->assertSame(1, DB::table('tipos_variante')->count());
        $this->actingAs($jefa)->getJson('/api/tipos-variante')->assertJsonFragment(['nombre' => 'alas vintage']);

        // Una opción con el mismo nombre de antes se reactiva sola.
        $this->actingAs($jefa)->postJson("/api/tipos-variante/{$id}/opciones", ['opciones' => ['Roble']])
            ->assertCreated()->assertJsonCount(1, 'opciones');
    }

    public function test_se_renombra_un_tipo(): void
    {
        $jefa = $this->supervisor();
        $id   = $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => 'alerones', 'afecta_precio' => true])->json('id');
        $otro = $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => 'color', 'afecta_precio' => false])->json('id');

        $this->actingAs($jefa)->patchJson("/api/tipos-variante/{$id}", ['nombre' => ' Alas '])
            ->assertOk()->assertJsonFragment(['id' => $id, 'nombre' => 'Alas']);

        // Con el de otro que está en uso, no.
        $this->actingAs($jefa)->patchJson("/api/tipos-variante/{$id}", ['nombre' => 'color'])
            ->assertStatus(422)->assertJsonFragment(['nombre' => ['Ya hay un tipo de variante con ese nombre.']]);

        // Con el de uno eliminado, sí: el eliminado deja libre el nombre.
        $this->actingAs($jefa)->deleteJson("/api/tipos-variante/{$otro}")->assertOk();
        $this->actingAs($jefa)->patchJson("/api/tipos-variante/{$id}", ['nombre' => 'color'])->assertOk();
        $this->assertSame("color (eliminado #{$otro})", DB::table('tipos_variante')->where('id', $otro)->value('nombre'));
    }

    public function test_se_renombra_una_opcion(): void
    {
        $jefa = $this->supervisor();
        $id   = $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => 'tamaño', 'afecta_precio' => true])->json('id');
        $ops  = collect($this->actingAs($jefa)->postJson("/api/tipos-variante/{$id}/opciones", ['opciones' => ['2 pustos', '3 puestos', '4 puestos']])
            ->json('opciones'))->pluck('id', 'nombre');

        $this->actingAs($jefa)->patchJson("/api/tipos-variante/opciones/{$ops['2 pustos']}", ['nombre' => '2 puestos'])
            ->assertOk()->assertJsonFragment(['id' => $ops['2 pustos'], 'nombre' => '2 puestos']);

        $this->actingAs($jefa)->patchJson("/api/tipos-variante/opciones/{$ops['2 pustos']}", ['nombre' => '3 puestos'])
            ->assertStatus(422)->assertJsonFragment(['nombre' => ['Este tipo ya tiene una opción con ese nombre.']]);

        // La de un eliminado se libera.
        $this->actingAs($jefa)->deleteJson("/api/tipos-variante/opciones/{$ops['4 puestos']}")->assertOk();
        $this->actingAs($jefa)->patchJson("/api/tipos-variante/opciones/{$ops['3 puestos']}", ['nombre' => '4 puestos'])->assertOk();
    }

    public function test_uno_en_uso_sigue_sin_poder_repetirse(): void
    {
        $jefa = $this->supervisor();
        $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => 'alas', 'afecta_precio' => false])->assertCreated();

        $this->actingAs($jefa)->postJson('/api/tipos-variante', ['nombre' => 'alas', 'afecta_precio' => false])
            ->assertStatus(422)->assertJsonFragment(['nombre' => ['Ya hay un tipo de variante con ese nombre.']]);
    }
}
