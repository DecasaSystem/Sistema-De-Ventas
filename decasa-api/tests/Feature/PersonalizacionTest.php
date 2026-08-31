<?php

namespace Tests\Feature;

use App\Models\Herramienta;
use App\Models\Modulo;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Que cada empresa le ponga a los módulos el nombre que usa.
 *
 * El programa hace lo mismo en todos lados, pero donde una mueblería tiene
 * "Telas" una de ropa tiene "Insumos". Lo que NO puede cambiar es la clave: es
 * lo que el código busca, y si se renombrara el módulo quedaría huérfano.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class PersonalizacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('modulos', function (Blueprint $t) {
            $t->id(); $t->string('clave')->unique(); $t->string('nombre'); $t->string('icono');
            $t->boolean('visible')->default(true); $t->unsignedSmallInteger('orden')->default(0); $t->timestamps();
        });
        Schema::create('herramientas', function (Blueprint $t) {
            $t->id(); $t->string('clave')->nullable()->unique();
            $t->string('seccion')->default('General'); $t->string('titulo');
            $t->string('tipo')->default('texto'); $t->text('contenido');
            $t->string('subtitulo')->nullable(); $t->string('icono')->nullable();
            $t->boolean('activo')->default(true); $t->unsignedSmallInteger('orden')->default(0); $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });

        Modulo::create(['clave' => 'telas', 'nombre' => 'Telas', 'icono' => 'SwatchIcon', 'orden' => 10]);
        Modulo::create(['clave' => 'produccion', 'nombre' => 'Producción', 'icono' => 'WrenchScrewdriverIcon', 'orden' => 20]);
    }

    private function jefe(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Jefa', 'email' => 'jefa@empresa.com',
            'password' => Hash::make('x'), 'rol' => 'supervisor',
        ]);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Ana', 'email' => 'ana@empresa.com',
            'password' => Hash::make('x'), 'rol' => 'vendedor',
        ]);
    }

    public function test_cualquiera_que_entra_ve_como_se_llaman_sus_modulos(): void
    {
        $res = $this->actingAs($this->vendedor())->getJson('/api/modulos')->assertOk();

        $this->assertSame('telas', $res->json('0.clave'));
        $this->assertSame('Telas', $res->json('0.nombre'));
    }

    /** Una empresa de ropa le dice "Insumos" a lo mismo. */
    public function test_el_jefe_le_cambia_el_nombre_y_el_icono(): void
    {
        $this->actingAs($this->jefe())->patchJson('/api/modulos', [
            'modulos' => [
                ['clave' => 'telas', 'nombre' => 'Insumos', 'icono' => 'ScissorsIcon', 'visible' => true],
            ],
        ])->assertOk();

        $telas = Modulo::where('clave', 'telas')->first();
        $this->assertSame('Insumos', $telas->nombre);
        $this->assertSame('ScissorsIcon', $telas->icono);
    }

    /** Una tienda de ropa no tiene taller: apaga el módulo sin tocar permisos. */
    public function test_se_puede_apagar_un_modulo_que_no_se_usa(): void
    {
        $this->actingAs($this->jefe())->patchJson('/api/modulos', [
            'modulos' => [
                ['clave' => 'produccion', 'nombre' => 'Producción', 'icono' => 'WrenchScrewdriverIcon', 'visible' => false],
            ],
        ])->assertOk();

        $this->assertFalse(Modulo::where('clave', 'produccion')->first()->visible);
    }

    /**
     * La clave es lo que el código busca. Mandar una que no existe no puede
     * crear un módulo fantasma ni renombrar el de al lado.
     */
    public function test_una_clave_inventada_no_pasa(): void
    {
        $this->actingAs($this->jefe())->patchJson('/api/modulos', [
            'modulos' => [
                ['clave' => 'modulo-que-no-existe', 'nombre' => 'Lo que sea', 'icono' => 'HomeIcon'],
            ],
        ])->assertStatus(422);

        $this->assertSame(2, Modulo::count());
    }

    public function test_un_vendedor_no_le_cambia_el_nombre_a_nada(): void
    {
        $this->actingAs($this->vendedor())->patchJson('/api/modulos', [
            'modulos' => [['clave' => 'telas', 'nombre' => 'Lo que sea', 'icono' => 'HomeIcon']],
        ])->assertStatus(403);

        $this->assertSame('Telas', Modulo::where('clave', 'telas')->first()->nombre);
    }

    public function test_un_modulo_no_se_queda_sin_nombre(): void
    {
        $this->actingAs($this->jefe())->patchJson('/api/modulos', [
            'modulos' => [['clave' => 'telas', 'nombre' => '', 'icono' => 'SwatchIcon']],
        ])->assertStatus(422);
    }

    // ── Herramientas ──────────────────────────────────────────────────────────

    public function test_el_jefe_arma_sus_propias_herramientas(): void
    {
        $this->actingAs($this->jefe())->postJson('/api/herramientas', [
            'seccion'   => 'Garantías',
            'titulo'    => 'Garantía de 1 año',
            'tipo'      => 'texto',
            'contenido' => 'Todos nuestros equipos tienen garantía de 1 año.',
        ])->assertStatus(201);

        $this->assertSame(1, Herramienta::count());
        $this->assertSame('Garantías', Herramienta::first()->seccion);
    }

    /** Al asesor sólo le salen las encendidas; al que administra, todas. */
    public function test_las_apagadas_no_le_salen_al_asesor(): void
    {
        Herramienta::create(['seccion' => 'S', 'titulo' => 'Viva',  'contenido' => 'a', 'activo' => true]);
        Herramienta::create(['seccion' => 'S', 'titulo' => 'Guardada', 'contenido' => 'b', 'activo' => false]);

        $vendedor = $this->vendedor();
        $this->assertCount(1, $this->actingAs($vendedor)->getJson('/api/herramientas')->json());
        $this->assertCount(2, $this->actingAs($vendedor)->getJson('/api/herramientas?todas=1')->json());
    }

    public function test_apagar_una_herramienta_no_borra_su_texto(): void
    {
        $h = Herramienta::create(['seccion' => 'S', 'titulo' => 'Horario', 'contenido' => 'De 8 a 5']);

        $this->actingAs($this->jefe())->patchJson("/api/herramientas/{$h->id}", ['activo' => false])->assertOk();

        $h->refresh();
        $this->assertFalse($h->activo);
        $this->assertSame('De 8 a 5', $h->contenido);
    }

    public function test_un_vendedor_no_crea_ni_borra_herramientas(): void
    {
        $h = Herramienta::create(['seccion' => 'S', 'titulo' => 'Horario', 'contenido' => 'De 8 a 5']);
        $vendedor = $this->vendedor();

        $this->actingAs($vendedor)->postJson('/api/herramientas', [
            'seccion' => 'S', 'titulo' => 'Mía', 'tipo' => 'texto', 'contenido' => 'x',
        ])->assertStatus(403);

        $this->actingAs($vendedor)->deleteJson("/api/herramientas/{$h->id}")->assertStatus(403);

        $this->assertSame(1, Herramienta::count());
    }

    public function test_un_tipo_que_no_existe_no_pasa(): void
    {
        $this->actingAs($this->jefe())->postJson('/api/herramientas', [
            'seccion' => 'S', 'titulo' => 'X', 'tipo' => 'video', 'contenido' => 'x',
        ])->assertStatus(422);
    }

    // ── Catálogos: son herramientas, pero el bot los busca por su clave ──────

    /**
     * Editar el enlace de un catálogo no puede quitarle la clave: el bot lo
     * busca por ahí y se quedaría sin qué mandarle al cliente.
     */
    public function test_editar_un_catalogo_no_le_borra_la_clave(): void
    {
        $cat = Herramienta::create([
            'seccion' => 'Catálogos', 'titulo' => 'Sofás', 'tipo' => 'enlace',
            'contenido' => 'https://viejo.example/sofas.pdf',
        ]);
        $cat->forceFill(['clave' => 'catalogo_sofas'])->save();

        $this->actingAs($this->jefe())->patchJson("/api/herramientas/{$cat->id}", [
            'contenido' => 'https://nuevo.example/sofas.pdf',
        ])->assertOk();

        $cat->refresh();
        $this->assertSame('catalogo_sofas', $cat->clave);
        $this->assertSame('https://nuevo.example/sofas.pdf', $cat->contenido);
    }

    /** Y lo que se cambie aquí es lo que sale por donde el bot los pide. */
    public function test_el_bot_recibe_el_enlace_ya_cambiado(): void
    {
        $cat = Herramienta::create([
            'seccion' => 'Catálogos', 'titulo' => 'Camas', 'tipo' => 'enlace',
            'contenido' => 'https://viejo.example/camas.pdf',
        ]);
        $cat->forceFill(['clave' => 'catalogo_camas'])->save();

        $this->actingAs($this->jefe())->patchJson("/api/herramientas/{$cat->id}", [
            'contenido' => 'https://nuevo.example/camas.pdf',
        ])->assertOk();

        $res = $this->actingAs($this->vendedor())->getJson('/api/redes/catalogos')->assertOk();
        $this->assertSame('https://nuevo.example/camas.pdf', $res->json('camas'));
    }

    /** Un catálogo apagado deja de ofrecerse, también por esa puerta. */
    public function test_un_catalogo_apagado_no_se_le_manda_a_nadie(): void
    {
        $cat = Herramienta::create([
            'seccion' => 'Catálogos', 'titulo' => 'Colchones', 'tipo' => 'enlace',
            'contenido' => 'https://x.example/colchones.pdf', 'activo' => false,
        ]);
        $cat->forceFill(['clave' => 'catalogo_colchones'])->save();

        $res = $this->actingAs($this->vendedor())->getJson('/api/redes/catalogos')->assertOk();
        $this->assertNull($res->json('colchones'));
    }
}
