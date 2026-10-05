<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * De un mueble único pueden haber salido varias piezas. Al vender otra se
 * escoge de la lista de los ya vendidos, con su última foto y precio.
 */
class UnicosVendidosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->string('estado')->default('entregado'); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->boolean('producto_unico')->default(false); $t->boolean('es_personalizado')->default(true);
            $t->string('boceto_url')->nullable(); $t->text('boceto_fotos')->nullable();
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });

        $orden = fn (string $estado = 'entregado') => DB::table('ordenes')->insertGetId(['estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('orden_items')->insert([
            ['orden_id' => $orden(), 'nombre_custom' => 'Comedor Roble', 'categoria_custom' => 'Comedores', 'producto_unico' => true, 'precio_unitario' => 3000, 'boceto_fotos' => null],
            ['orden_id' => $orden(), 'nombre_custom' => 'comedor roble ', 'categoria_custom' => 'Comedores', 'producto_unico' => true, 'precio_unitario' => 3200, 'boceto_fotos' => json_encode(['https://x/f.jpg'])],
            ['orden_id' => $orden('cancelado'), 'nombre_custom' => 'Baúl cancelado', 'categoria_custom' => null, 'producto_unico' => true, 'precio_unitario' => 1, 'boceto_fotos' => null],
            ['orden_id' => $orden(), 'nombre_custom' => 'Diseño nuevo', 'categoria_custom' => null, 'producto_unico' => false, 'precio_unitario' => 1, 'boceto_fotos' => null],
        ]);
    }

    public function test_lista_los_unicos_ya_vendidos_juntando_por_nombre(): void
    {
        $u = Usuario::create(['nombre' => 'V', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);

        $lista = $this->actingAs($u)->getJson('/api/productos/unicos?q=comed')->assertOk()->json();

        $this->assertCount(1, $lista);
        $this->assertSame('comedor roble', mb_strtolower($lista[0]['nombre']));
        $this->assertSame(2, $lista[0]['veces']);
        $this->assertEquals(3200, $lista[0]['precio_unitario']);
        $this->assertSame(['https://x/f.jpg'], $lista[0]['fotos']);

        $todos = $this->actingAs($u)->getJson('/api/productos/unicos')->assertOk()->json();
        $this->assertSame(['comedor roble'], array_map(fn ($x) => mb_strtolower($x['nombre']), $todos));
    }
}
