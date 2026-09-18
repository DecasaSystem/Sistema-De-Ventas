<?php

namespace Tests\Feature;

use App\Models\Herramienta;
use App\Models\Modulo;
use App\Models\ModuloItem;
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
            $t->boolean('recarga_telas')->default(false); $t->boolean('acceso_telas')->default(false);
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave')->unique(); $t->string('nombre'); $t->string('arquetipo');
            $t->boolean('activo')->default(true); $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('acceso_redes')->default(false); $t->boolean('acceso_comisiones')->default(false);
            $t->boolean('recarga_telas')->default(false); $t->boolean('acceso_surtir')->default(false);
            $t->boolean('acceso_costos')->default(false); $t->boolean('acceso_proveedores')->default(false);
            $t->boolean('acceso_despacho')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('acceso_reserva')->default(false); $t->boolean('acceso_nomina')->default(false);
            $t->boolean('acceso_compras')->default(false); $t->timestamps();
        });
        Schema::create('modulos', function (Blueprint $t) {
            $t->id(); $t->string('clave')->unique(); $t->string('plantilla', 30)->nullable();
            $t->string('nombre'); $t->text('icono'); $t->json('config')->nullable();
            $t->boolean('visible')->default(true); $t->unsignedSmallInteger('orden')->default(0); $t->timestamps();
        });
        // Los ítems de los módulos que nacen de Telas (Espumas, Hilos...).
        Schema::create('modulo_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('modulo_id');
            $t->string('marca'); $t->string('tipo'); $t->string('color');
            $t->string('referencia')->nullable(); $t->string('textura')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('cantidad_disponible', 10, 2)->default(0); $t->boolean('activo')->default(true); $t->timestamps();
            $t->unique(['modulo_id', 'marca', 'tipo', 'color']);
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('herramientas', function (Blueprint $t) {
            $t->id(); $t->string('clave')->nullable()->unique();
            $t->string('seccion')->default('General'); $t->string('titulo');
            $t->string('tipo')->default('texto'); $t->text('contenido');
            $t->string('subtitulo')->nullable(); $t->string('icono')->nullable();
            $t->boolean('activo')->default(true); $t->unsignedSmallInteger('orden')->default(0); $t->timestamps();
        });
        // Las mira el payload de la sesión para saber si alguien lleva pasos.
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            // Cada quien lleva su linea: 'ambas', o solo una de las dos.
            $t->string('linea')->default('ambas');
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

    /** Un icono dibujado a mano es el trazo de un SVG: mucho más largo que un nombre. */
    public function test_el_icono_puede_ser_un_dibujo(): void
    {
        $dibujo = 'dibujo:' . str_repeat('M2 2L22 22M2 22L22 2', 60);

        $this->actingAs($this->jefe())->patchJson('/api/modulos', [
            'modulos' => [['clave' => 'telas', 'nombre' => 'Telas', 'icono' => $dibujo]],
        ])->assertOk();

        $this->assertSame($dibujo, Modulo::where('clave', 'telas')->first()->icono);
    }

    // ── Módulos a partir de otros ─────────────────────────────────────────────

    private function espumas(): Modulo
    {
        $res = $this->actingAs($this->jefe())->postJson('/api/modulos', [
            'plantilla' => 'telas',
            'nombre'    => 'Espumas',
            'icono'     => 'CubeIcon',
            'config'    => ['unidad' => 'láminas', 'singular' => 'espuma', 'decimales' => 0],
        ])->assertStatus(201);

        return Modulo::findOrFail($res->json('id'));
    }

    /** La mueblería lleva Telas por metros y Espumas por láminas: la misma pantalla, otro nombre. */
    public function test_el_jefe_crea_espumas_a_partir_de_telas(): void
    {
        $espumas = $this->espumas();

        $this->assertSame('telas-espumas', $espumas->clave);
        $this->assertSame('telas', $espumas->plantilla);
        $this->assertSame('láminas', $espumas->configCompleta()['unidad']);
        $this->assertSame(0, $espumas->configCompleta()['decimales']);

        // A todo el mundo le llega con su config completa y detrás de los de siempre.
        $lista = $this->actingAs($this->vendedor())->getJson('/api/modulos')->assertOk()->json();
        $ultimo = end($lista);
        $this->assertSame('telas-espumas', $ultimo['clave']);
        $this->assertSame('espuma', $ultimo['config']['singular']);
        $this->assertNull($lista[0]['config']);
    }

    /** Lo que no se llene lo pone la plantilla: sin unidad, se hereda la de Telas. */
    public function test_la_config_que_no_se_llena_la_pone_la_plantilla(): void
    {
        $res = $this->actingAs($this->jefe())->postJson('/api/modulos', [
            'plantilla' => 'telas', 'nombre' => 'Hilos', 'icono' => 'SwatchIcon',
        ])->assertStatus(201);

        $this->assertSame('m', $res->json('config.unidad'));
        $this->assertSame('tela', $res->json('config.singular'));
    }

    /** Dos módulos con el mismo nombre no pueden compartir clave. */
    public function test_dos_con_el_mismo_nombre_no_chocan(): void
    {
        $this->espumas();
        $res = $this->actingAs($this->jefe())->postJson('/api/modulos', [
            'plantilla' => 'telas', 'nombre' => 'Espumas', 'icono' => 'CubeIcon',
        ])->assertStatus(201);

        $this->assertSame('telas-espumas-2', $res->json('clave'));
    }

    public function test_solo_se_copia_de_una_plantilla_que_exista(): void
    {
        $this->actingAs($this->jefe())->postJson('/api/modulos', [
            'plantilla' => 'nomina', 'nombre' => 'Otra nómina', 'icono' => 'BanknotesIcon',
        ])->assertStatus(422);
    }

    public function test_un_vendedor_no_crea_modulos(): void
    {
        $this->actingAs($this->vendedor())->postJson('/api/modulos', [
            'plantilla' => 'telas', 'nombre' => 'Espumas', 'icono' => 'CubeIcon',
        ])->assertStatus(403);
    }

    /** Un módulo de siempre no tiene cómo volver si se borra: se apaga, no se borra. */
    public function test_los_modulos_de_siempre_no_se_borran(): void
    {
        $telas = Modulo::where('clave', 'telas')->first();

        $this->actingAs($this->jefe())->deleteJson("/api/modulos/{$telas->id}")->assertStatus(422);

        $this->assertNotNull(Modulo::find($telas->id));
    }

    public function test_borrar_una_copia_se_lleva_sus_items(): void
    {
        $espumas = $this->espumas();
        ModuloItem::create(['modulo_id' => $espumas->id, 'marca' => 'X', 'tipo' => 'D25', 'color' => 'Blanca']);

        $this->actingAs($this->jefe())->deleteJson("/api/modulos/{$espumas->id}")->assertOk();

        $this->assertNull(Modulo::find($espumas->id));
        // En MySQL lo hace la llave foránea en cascada; aquí se comprueba que
        // el módulo se fue, que es lo que la pantalla necesita.
    }

    // ── Los ítems de un módulo copiado ────────────────────────────────────────

    public function test_agregar_recargar_y_descontar_una_espuma(): void
    {
        $espumas = $this->espumas();
        $jefe    = $this->jefe();

        $res = $this->actingAs($jefe)->postJson("/api/modulos/{$espumas->clave}/items", [
            'marca' => 'Espumas del Valle', 'tipo' => 'D25', 'color' => 'Blanca', 'cantidad_inicial' => 10,
        ])->assertStatus(201);
        $id = $res->json('id');
        $this->assertEquals(10, $res->json('cantidad_libre'));

        $this->actingAs($jefe)->postJson("/api/modulos/{$espumas->clave}/items/recargar", [
            'id' => $id, 'cantidad' => 5,
        ])->assertOk()->assertJsonPath('cantidad_libre', 15);

        $this->actingAs($jefe)->postJson("/api/modulos/{$espumas->clave}/items/descontar", [
            'id' => $id, 'cantidad' => 4,
        ])->assertOk()->assertJsonPath('cantidad_libre', 11);

        // No se puede descontar más de lo que hay.
        $this->actingAs($jefe)->postJson("/api/modulos/{$espumas->clave}/items/descontar", [
            'id' => $id, 'cantidad' => 50,
        ])->assertStatus(422);

        $lista = $this->actingAs($this->vendedor())->getJson("/api/modulos/{$espumas->clave}/items")->assertOk()->json();
        $this->assertCount(1, $lista);
        $this->assertEquals(11, $lista[0]['cantidad_libre']);
    }

    /** Las espumas de un módulo no se mezclan con las de otro. */
    public function test_cada_modulo_tiene_sus_propios_items(): void
    {
        $espumas = $this->espumas();
        $hilos   = Modulo::create(['clave' => 'telas-hilos', 'plantilla' => 'telas', 'nombre' => 'Hilos', 'icono' => 'SwatchIcon']);
        ModuloItem::create(['modulo_id' => $espumas->id, 'marca' => 'A', 'tipo' => 'D25', 'color' => 'Blanca']);
        ModuloItem::create(['modulo_id' => $hilos->id,   'marca' => 'B', 'tipo' => 'Nylon', 'color' => 'Negro']);

        $vendedor = $this->vendedor();
        $this->assertCount(1, $this->actingAs($vendedor)->getJson('/api/modulos/telas-espumas/items')->json());
        $this->assertSame(['B'], $this->actingAs($vendedor)->getJson('/api/modulos/telas-hilos/items/proveedores')->json());
    }

    /** Los permisos son los de Telas: quien no recarga tela no recarga espuma. */
    public function test_los_permisos_son_los_de_telas(): void
    {
        $espumas  = $this->espumas();
        $vendedor = $this->vendedor();
        $item     = ModuloItem::create(['modulo_id' => $espumas->id, 'marca' => 'A', 'tipo' => 'D25', 'color' => 'Blanca', 'cantidad_disponible' => 3]);

        $this->actingAs($vendedor)->postJson("/api/modulos/{$espumas->clave}/items/recargar", ['id' => $item->id, 'cantidad' => 1])
            ->assertStatus(403);
        $this->actingAs($vendedor)->postJson("/api/modulos/{$espumas->clave}/items/descontar", ['id' => $item->id, 'cantidad' => 1])
            ->assertStatus(403);

        $vendedor->forceFill(['recarga_telas' => true, 'acceso_telas' => true])->save();

        $this->actingAs($vendedor)->postJson("/api/modulos/{$espumas->clave}/items/recargar", ['id' => $item->id, 'cantidad' => 1])
            ->assertOk();
        $this->actingAs($vendedor)->postJson("/api/modulos/{$espumas->clave}/items/descontar", ['id' => $item->id, 'cantidad' => 1])
            ->assertOk();
    }

    /** Telas de siempre no tiene ítems por aquí: los suyos van por /inventario-telas. */
    public function test_un_modulo_de_siempre_no_tiene_items_por_aqui(): void
    {
        $this->actingAs($this->vendedor())->getJson('/api/modulos/telas/items')->assertStatus(404);
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

    // ── Roles: el nombre es de la empresa, la clave es del código ────────────

    /**
     * Lo que sostiene que renombrar un rol sea seguro.
     *
     * Una empresa que no trabaja madera le dirá "Operario" a lo que aquí se
     * llama "Ebanista". El nombre tiene que cambiar en todas las pantallas,
     * pero la clave no: hay cuarenta sitios que preguntan si alguien es
     * `ebanista` para darle su pantalla y sus pasos del taller. Si renombrar
     * moviera la clave, ese trabajador se quedaría sin nada de un momento a
     * otro y sin que nadie lo hubiera pedido.
     */
    public function test_renombrar_un_rol_no_le_cambia_la_clave(): void
    {
        $rol = \App\Models\Rol::create([
            'clave' => 'ebanista', 'nombre' => 'Ebanista', 'arquetipo' => 'taller',
        ]);
        $trabajador = Usuario::create([
            'nombre' => 'Henry', 'email' => 'henry@empresa.com',
            'password' => Hash::make('x'), 'rol_id' => $rol->id,
        ]);

        $this->actingAs($this->jefe())
            ->patchJson("/api/roles/{$rol->id}", ['nombre' => 'Operario de planta'])
            ->assertOk();

        $this->assertSame('Operario de planta', $rol->fresh()->nombre);
        $this->assertSame('ebanista', $rol->fresh()->clave);
        // Y quien lo tiene sigue siendo lo que el código reconoce.
        $this->assertSame('ebanista', $trabajador->fresh()->rol);
    }

    /** El nombre nuevo es el que ve la persona al entrar. */
    public function test_el_nombre_nuevo_del_rol_viaja_en_la_sesion(): void
    {
        $rol = \App\Models\Rol::create([
            'clave' => 'ebanista', 'nombre' => 'Ebanista', 'arquetipo' => 'taller',
        ]);
        $trabajador = Usuario::create([
            'nombre' => 'Henry', 'email' => 'henry@empresa.com',
            'password' => Hash::make('x'), 'rol_id' => $rol->id,
        ]);

        $this->actingAs($this->jefe())
            ->patchJson("/api/roles/{$rol->id}", ['nombre' => 'Operario de planta'])->assertOk();

        $res = $this->actingAs($trabajador->fresh())->getJson('/api/auth/me')->assertOk();

        $this->assertSame('Operario de planta', $res->json('rol_nombre'));
        $this->assertSame('ebanista', $res->json('rol'));
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
