<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reportes → Productos también cuenta lo que no es de catálogo.
 *
 * Los diseños nuevos, los muebles únicos y las restauraciones no tienen
 * producto_id, y con el JOIN a productos no salían en ninguna cifra. Ahora
 * salen, cada uno con su clase, y se pueden filtrar.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReporteProductosEspecialesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_reportes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->boolean('es_personalizado')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('retapizar')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });

        DB::table('productos')->insert(['id' => 1, 'nombre' => 'Sofá Milán', 'categoria' => 'Sofás']);
        $orden = DB::table('ordenes')->insertGetId(['tienda_id' => 1, 'vendedor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $item = fn (array $d) => DB::table('orden_items')->insert(['orden_id' => $orden, 'cantidad' => 1] + $d);
        $item(['producto_id' => 1, 'precio_unitario' => 1000]);                                   // catálogo
        $item(['producto_id' => 1, 'es_personalizado' => true, 'precio_unitario' => 1500]);       // personalizado
        $item(['nombre_custom' => 'Comedor Roble', 'categoria_custom' => 'Comedores', 'es_personalizado' => true, 'precio_unitario' => 3000]);
        $item(['nombre_custom' => 'comedor roble ', 'categoria_custom' => 'Comedores', 'es_personalizado' => true, 'precio_unitario' => 3000]);
        $item(['nombre_custom' => 'Baúl antiguo', 'es_personalizado' => true, 'producto_unico' => true, 'precio_unitario' => 800]);
        $item(['nombre_custom' => 'Silla abuela', 'categoria_custom' => 'Restauración', 'es_personalizado' => true, 'es_restauracion' => true, 'precio_unitario' => 200]);
    }

    private function pedir(string $ruta): array
    {
        $jefe = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);

        return $this->actingAs($jefe)->getJson($ruta)->assertOk()->json();
    }

    public function test_lo_que_no_es_de_catalogo_sale_con_su_clase(): void
    {
        $filas = collect($this->pedir('/api/stats/productos?periodo=mes&limit=50'));

        $clases = $filas->pluck('clase', 'nombre')->all();
        $this->assertSame('diseno_especial', $clases['Comedor Roble'] ?? $clases['comedor roble'] ?? null);
        $this->assertSame('producto_unico', $clases['Baúl antiguo']);
        $this->assertSame('restauracion', $clases['Silla abuela']);

        // El mismo sofá, tal cual y personalizado: dos filas.
        $this->assertCount(2, $filas->where('producto_id', 1));
        // El comedor escrito dos veces distinto es uno solo.
        $comedor = $filas->first(fn ($f) => $f['clase'] === 'diseno_especial');
        $this->assertEquals(2, $comedor['cantidad']);
        $this->assertEquals(6000, $comedor['valor_total']);
    }

    public function test_filtra_por_clase_y_resume_por_clase(): void
    {
        $especiales = $this->pedir('/api/stats/productos?periodo=mes&clase=especiales');
        $this->assertNotContains('catalogo', array_column($especiales, 'clase'));
        $this->assertCount(4, $especiales);

        $tipos = collect($this->pedir('/api/stats/productos/tipos?periodo=mes'))->pluck('valor_total', 'clase');
        $this->assertEquals(1000, $tipos['catalogo']);
        $this->assertEquals(1500, $tipos['personalizado']);
        $this->assertEquals(6000, $tipos['diseno_especial']);
        $this->assertEquals(800,  $tipos['producto_unico']);
        $this->assertEquals(200,  $tipos['restauracion']);

        $cats = collect($this->pedir('/api/stats/categorias?periodo=mes'))->pluck('valor_total', 'categoria');
        $this->assertEquals(6000, $cats['Comedores']);
        $this->assertEquals(800,  $cats['Sin categoría']);
    }
}
