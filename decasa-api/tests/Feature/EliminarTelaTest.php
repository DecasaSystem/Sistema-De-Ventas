<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Eliminar una tela (o un ítem de un módulo copiado de Telas).
 *
 * Es para las que se crearon mal. No se borra: queda inactiva, y volverla a
 * crear igual la trae de vuelta. Lo que no se deja es quitar una tela que una
 * orden tiene apartada: el apartado quedaría colgando de algo invisible.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class EliminarTelaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('recarga_telas')->default(false);
            $t->timestamps();
        });
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('marca'); $t->string('tipo'); $t->string('color');
            $t->string('referencia')->nullable(); $t->string('textura')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('metros_disponibles', 10, 2)->default(0); $t->decimal('metros_reservados', 10, 2)->default(0);
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('tela_reservas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->unsignedBigInteger('catalogo_tela_id');
            $t->decimal('metros', 8, 2); $t->string('estado', 20)->default('reservada'); $t->timestamps();
        });
        Schema::create('modulos', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('plantilla'); $t->string('nombre')->nullable();
            $t->timestamps();
        });
        Schema::create('modulo_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('modulo_id'); $t->string('marca'); $t->string('tipo'); $t->string('color');
            $t->decimal('cantidad_disponible', 10, 2)->default(0); $t->boolean('activo')->default(true);
            $t->timestamps();
        });
    }

    private function usuario(string $rol, bool $recarga = false): Usuario
    {
        return Usuario::forceCreate([
            'nombre' => ucfirst($rol), 'email' => "{$rol}@d.com", 'password' => 'x',
            'rol' => $rol, 'recarga_telas' => $recarga,
        ]);
    }

    private function tela(array $extra = []): int
    {
        return DB::table('catalogo_telas')->insertGetId(array_merge(
            ['marca' => 'Bellatela', 'tipo' => 'Alpes', 'color' => 'Gris', 'metros_disponibles' => 12],
            $extra,
        ));
    }

    public function test_el_supervisor_la_elimina_y_queda_inactiva(): void
    {
        $id = $this->tela();

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/catalogo-telas/{$id}")->assertOk();

        // No se borra: sigue ahí, inactiva, con sus datos.
        $this->assertSame(0, (int) DB::table('catalogo_telas')->where('id', $id)->value('activo'));
    }

    public function test_no_se_elimina_con_metros_apartados_por_una_orden(): void
    {
        $id = $this->tela(['metros_reservados' => 3.5]);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/catalogo-telas/{$id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede eliminar: tiene 3.5 m apartados para órdenes. Primero hay que cambiarles la tela o esperar a que se entreguen.']);

        $this->assertSame(1, (int) DB::table('catalogo_telas')->where('id', $id)->value('activo'));
    }

    public function test_tampoco_con_una_reserva_viva_aunque_el_total_diga_cero(): void
    {
        $id = $this->tela();
        DB::table('tela_reservas')->insert(['orden_item_id' => 1, 'catalogo_tela_id' => $id, 'metros' => 2, 'estado' => 'reservada']);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/catalogo-telas/{$id}")->assertStatus(422);
    }

    public function test_una_reserva_ya_consumida_no_la_frena(): void
    {
        $id = $this->tela();
        DB::table('tela_reservas')->insert(['orden_item_id' => 1, 'catalogo_tela_id' => $id, 'metros' => 2, 'estado' => 'consumida']);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/catalogo-telas/{$id}")->assertOk();
    }

    public function test_quien_no_es_supervisor_no_puede(): void
    {
        $id = $this->tela();

        $this->actingAs($this->usuario('vendedor', recarga: true))
            ->deleteJson("/api/catalogo-telas/{$id}")->assertStatus(403);

        $this->assertSame(1, (int) DB::table('catalogo_telas')->where('id', $id)->value('activo'));
    }

    public function test_en_un_modulo_copiado_tambien_se_puede(): void
    {
        $modulo = DB::table('modulos')->insertGetId(['clave' => 'espumas', 'plantilla' => 'telas', 'nombre' => 'Espumas']);
        $item   = DB::table('modulo_items')->insertGetId(['modulo_id' => $modulo, 'marca' => 'X', 'tipo' => 'Y', 'color' => 'Z']);

        // El que recarga no elimina: eso queda para el supervisor, como en Telas.
        $this->actingAs($this->usuario('vendedor', recarga: true))
            ->deleteJson("/api/modulos/espumas/items/{$item}")->assertStatus(403);

        $this->actingAs($this->usuario('supervisor'))
            ->deleteJson("/api/modulos/espumas/items/{$item}")->assertOk();

        $this->assertSame(0, (int) DB::table('modulo_items')->where('id', $item)->value('activo'));
    }
}
