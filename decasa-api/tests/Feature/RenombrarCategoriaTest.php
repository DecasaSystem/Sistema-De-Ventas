<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Renombrar y juntar categorías de inventario (2026-10-29).
 *
 * Lo que puede romperse en silencio: que queden productos con el nombre viejo
 * (los apagados también), que un error de dedo termine creando dos categorías,
 * y que los links de sección que ya tienen los clientes dejen de abrir.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RenombrarCategoriaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria', 80)->nullable();
            $t->decimal('precio_base', 15, 2)->default(0);
            $t->string('foto_url')->nullable(); $t->string('foto_url_2')->nullable();
            $t->string('medidas')->nullable(); $t->string('material')->nullable(); $t->text('descripcion')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        (require database_path('migrations/2026_11_06_000001_crear_categorias_renombradas.php'))->up();
    }

    private function usuario(string $rol): Usuario
    {
        return Usuario::create([
            'nombre' => ucfirst($rol), 'email' => "{$rol}@d.com", 'password' => 'x',
            'rol' => $rol, 'created_at' => now(),
        ]);
    }

    private function producto(string $nombre, ?string $categoria, bool $activo = true): Producto
    {
        return Producto::create(['nombre' => $nombre, 'categoria' => $categoria, 'precio_base' => 100000, 'activo' => $activo]);
    }

    private function categoriaDe(Producto $p): ?string
    {
        return DB::table('productos')->where('id', $p->id)->value('categoria');
    }

    public function test_renombrar_cambia_todos_los_productos_tambien_los_apagados(): void
    {
        $a = $this->producto('Throw gris', 'Truf');
        $b = $this->producto('Throw beige', 'Truf', activo: false);
        $c = $this->producto('Cama Bali', 'camas');

        $this->actingAs($this->usuario('supervisor'))
            ->postJson('/api/productos/categorias/renombrar', ['de' => 'Truf', 'a' => '  Throw  '])
            ->assertOk()
            ->assertJson(['de' => 'Truf', 'a' => 'Throw', 'productos' => 2, 'juntada_con' => null]);

        $this->assertSame('Throw', $this->categoriaDe($a));
        $this->assertSame('Throw', $this->categoriaDe($b));
        $this->assertSame('camas', $this->categoriaDe($c));
    }

    public function test_solo_un_supervisor_puede_renombrar(): void
    {
        $p = $this->producto('Throw gris', 'Truf');

        $this->actingAs($this->usuario('vendedor'))
            ->postJson('/api/productos/categorias/renombrar', ['de' => 'Truf', 'a' => 'Throw'])
            ->assertForbidden();
        $this->actingAs($this->usuario('vendedor'))
            ->getJson('/api/productos/categorias/gestion')
            ->assertForbidden();

        $this->assertSame('Truf', $this->categoriaDe($p));
    }

    public function test_la_lista_trae_el_nombre_real_y_cuenta_los_apagados(): void
    {
        $this->producto('Comedor Roma', 'comedores');
        $this->producto('Comedor Viejo', 'comedores', activo: false);
        $this->producto('Sin nada', null);

        $this->actingAs($this->usuario('supervisor'))
            ->getJson('/api/productos/categorias/gestion')
            ->assertOk()
            ->assertExactJson([['nombre' => 'comedores', 'productos' => 2, 'activos' => 1]]);
    }

    public function test_si_ya_existe_otra_con_ese_nombre_pregunta_antes_de_juntar(): void
    {
        $a = $this->producto('Sofá Milán', 'Sofa');
        $b = $this->producto('Sofá Roma', 'sofas');
        $jefe = $this->usuario('supervisor');

        // "Sofás" es "sofas" sin tilde ni mayúscula: ya existe.
        $this->actingAs($jefe)
            ->postJson('/api/productos/categorias/renombrar', ['de' => 'Sofa', 'a' => 'Sofás'])
            ->assertStatus(409)
            ->assertJson(['existe' => 'sofas']);
        $this->assertSame('Sofa', $this->categoriaDe($a));

        // Al juntar, las dos quedan escritas como se pidió.
        $this->actingAs($jefe)
            ->postJson('/api/productos/categorias/renombrar', ['de' => 'Sofa', 'a' => 'Sofás', 'juntar' => true])
            ->assertOk()
            ->assertJson(['a' => 'Sofás', 'productos' => 2, 'juntada_con' => 'sofas']);

        $this->assertSame('Sofás', $this->categoriaDe($a));
        $this->assertSame('Sofás', $this->categoriaDe($b));
    }

    public function test_corregir_como_se_escribe_no_cuenta_como_juntar(): void
    {
        $p = $this->producto('Sofá Roma', 'sofas');

        $this->actingAs($this->usuario('supervisor'))
            ->postJson('/api/productos/categorias/renombrar', ['de' => 'sofas', 'a' => 'Sofás'])
            ->assertOk()
            ->assertJson(['juntada_con' => null]);

        $this->assertSame('Sofás', $this->categoriaDe($p));
    }

    public function test_no_deja_un_nombre_vacio_ni_una_categoria_que_no_existe(): void
    {
        $this->producto('Throw gris', 'Truf');
        $jefe = $this->usuario('supervisor');

        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Truf', 'a' => ' - '])
            ->assertStatus(422)->assertJsonValidationErrors('a');
        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Truf', 'a' => 'Truf'])
            ->assertStatus(422)->assertJsonValidationErrors('a');
        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Nada', 'a' => 'Algo'])
            ->assertStatus(422)->assertJsonValidationErrors('de');
    }

    public function test_el_link_viejo_de_la_seccion_sigue_abriendo(): void
    {
        $this->producto('Throw gris', 'Truf');
        $jefe = $this->usuario('supervisor');

        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Truf', 'a' => 'Throw'])->assertOk();
        // Y otra vez: un link de hace dos renombres también abre.
        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Throw', 'a' => 'Mantas'])->assertOk();

        $this->getJson('/api/catalogo/truf')
            ->assertOk()
            ->assertJson(['seccion' => 'Mantas'])
            ->assertJsonPath('productos.0.nombre', 'Throw gris');
        $this->getJson('/api/catalogo/throw')->assertOk()->assertJson(['seccion' => 'Mantas']);
        $this->getJson('/api/catalogo/mantas')->assertOk();
    }

    public function test_si_un_nombre_vuelve_a_su_nombre_viejo_manda_lo_que_hay_hoy(): void
    {
        $p = $this->producto('Throw gris', 'Truf');
        $jefe = $this->usuario('supervisor');

        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Truf', 'a' => 'Throw'])->assertOk();
        $this->actingAs($jefe)->postJson('/api/productos/categorias/renombrar', ['de' => 'Throw', 'a' => 'Truf'])->assertOk();

        $this->assertSame('Truf', $this->categoriaDe($p));
        $this->getJson('/api/catalogo/truf')->assertOk()->assertJson(['seccion' => 'Truf']);
        $this->getJson('/api/catalogo/throw')->assertOk()->assertJson(['seccion' => 'Truf']);
        // Ningún renglón apunta a sí mismo.
        $this->assertSame(0, DB::table('categorias_renombradas')->where('clave_vieja', 'truf')->count());
    }
}
