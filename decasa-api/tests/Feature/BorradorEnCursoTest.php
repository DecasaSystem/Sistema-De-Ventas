<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La orden a medio hacer se sigue en otro aparato.
 *
 * Caso real: se recargó en el celular, se siguió en el computador y el
 * computador ofrecía su copia vieja. Ahora la copia vive también en la cuenta,
 * y un aparato que guarda sobre una versión vieja se entera (409) en vez de
 * pisar lo nuevo o revivir una orden que ya se creó.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class BorradorEnCursoTest extends TestCase
{
    private const CLAVE = 'nueva-orden:1:orden';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('borradores_en_curso', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('clave', 150);
            $t->unsignedInteger('revision')->default(0); $t->longText('datos')->nullable(); $t->text('resumen')->nullable();
            $t->string('aparato_id', 64)->nullable(); $t->string('aparato', 40)->nullable();
            $t->timestamp('guardado_at')->nullable(); $t->timestamp('eliminado_at')->nullable(); $t->timestamps();
            $t->unique(['usuario_id', 'clave']);
        });
    }

    private function vendedora(string $email = 'v@d.com'): Usuario
    {
        return Usuario::create(['nombre' => 'Vendedora', 'email' => $email, 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);
    }

    private function guardar(Usuario $u, ?int $base, array $datos, string $aparato = 'cel', array $extra = [])
    {
        return $this->actingAs($u)->putJson('/api/borradores-en-curso/' . self::CLAVE, [
            'revision_base' => $base,
            'datos'         => $datos,
            'resumen'       => ['titulo' => 'Ana', 'detalle' => '1 producto · paso 2 de 3'],
            'aparato_id'    => $aparato,
            'aparato'       => $aparato === 'cel' ? 'el celular' : 'el computador',
        ] + $extra);
    }

    public function test_lo_guardado_en_el_celular_lo_ve_el_computador(): void
    {
        $u = $this->vendedora();
        $this->actingAs($u)->getJson('/api/borradores-en-curso/' . self::CLAVE)
            ->assertOk()->assertJson(['existe' => false, 'revision' => 0]);

        $this->guardar($u, 0, ['step' => 2, 'items' => [['nombre' => 'Cama Bali']]])
            ->assertOk()->assertJson(['revision' => 1]);

        $this->actingAs($u)->getJson('/api/borradores-en-curso/' . self::CLAVE)
            ->assertOk()
            ->assertJson([
                'existe'   => true,
                'revision' => 1,
                'aparato'  => 'el celular',
                'datos'    => ['step' => 2, 'items' => [['nombre' => 'Cama Bali']]],
                'resumen'  => ['titulo' => 'Ana'],
            ]);
    }

    public function test_un_aparato_con_la_version_vieja_no_pisa_la_nueva(): void
    {
        $u = $this->vendedora();
        $this->guardar($u, 0, ['step' => 1])->assertOk();               // celular: rev 1
        $this->guardar($u, 1, ['step' => 3], 'pc')->assertOk()           // computador sigue: rev 2
            ->assertJson(['revision' => 2]);

        // El celular quedó abierto con la rev 1 y alguien toca algo.
        $this->guardar($u, 1, ['step' => 1, 'otra' => true])
            ->assertStatus(409)
            ->assertJson(['revision' => 2, 'aparato' => 'el computador', 'datos' => ['step' => 3]]);

        // Si la persona escoge quedarse con la del celular, guarda sobre la última.
        $this->guardar($u, 2, ['step' => 1, 'otra' => true])->assertOk()->assertJson(['revision' => 3]);
    }

    public function test_sin_saber_que_habia_no_pisa_una_copia_viva(): void
    {
        $u = $this->vendedora();
        $this->guardar($u, 0, ['step' => 2])->assertOk();
        $this->guardar($u, null, ['step' => 1], 'pc')->assertStatus(409);
        $this->guardar($u, null, ['step' => 1], 'pc', ['forzar' => true])->assertOk()->assertJson(['revision' => 2]);
    }

    public function test_al_crear_la_orden_los_otros_aparatos_ya_no_la_ofrecen(): void
    {
        $u = $this->vendedora();
        $this->guardar($u, 0, ['step' => 3])->assertOk();

        $this->actingAs($u)->deleteJson('/api/borradores-en-curso/' . self::CLAVE)
            ->assertOk()->assertJson(['revision' => 2]);

        $this->actingAs($u)->getJson('/api/borradores-en-curso/' . self::CLAVE)
            ->assertOk()->assertJson(['existe' => false, 'eliminado' => true, 'revision' => 2, 'datos' => null]);

        // El otro aparato, que seguía con la rev 1, no la revive al guardar.
        $this->guardar($u, 1, ['step' => 3], 'pc')->assertStatus(409)->assertJson(['eliminado' => true]);

        // Una orden nueva, que sí preguntó qué había, guarda normal.
        $this->guardar($u, 2, ['step' => 1], 'pc')->assertOk()->assertJson(['revision' => 3]);
    }

    public function test_cada_persona_ve_solo_lo_suyo(): void
    {
        $ana  = $this->vendedora('ana@d.com');
        $otra = $this->vendedora('otra@d.com');
        $this->guardar($ana, 0, ['step' => 2])->assertOk();

        $this->actingAs($otra)->getJson('/api/borradores-en-curso/' . self::CLAVE)
            ->assertOk()->assertJson(['existe' => false]);
        $this->guardar($otra, 0, ['step' => 1])->assertOk()->assertJson(['revision' => 1]);
        $this->assertSame(2, DB::table('borradores_en_curso')->count());
    }

    public function test_despues_de_dos_dias_ya_no_se_ofrece(): void
    {
        $u = $this->vendedora();
        $this->guardar($u, 0, ['step' => 2])->assertOk();
        DB::table('borradores_en_curso')->update(['guardado_at' => now()->subHours(49)]);

        $this->actingAs($u)->getJson('/api/borradores-en-curso/' . self::CLAVE)
            ->assertOk()->assertJson(['existe' => false, 'revision' => 1]);
        // Vencida no frena a quien no sabía qué había.
        $this->guardar($u, null, ['step' => 1], 'pc')->assertOk();
    }

    public function test_clave_rara_no_entra(): void
    {
        $u = $this->vendedora();
        $this->actingAs($u)->getJson('/api/borradores-en-curso/' . str_repeat('a', 200))->assertStatus(422);
    }
}
