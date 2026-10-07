<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La lista de inventario va de más a menos stock también en "Todos".
 *
 * Antes solo al filtrar una categoría se ordenaba por cantidad; sin filtro iba
 * por nombre y los productos en 0 salían revueltos entre los que sí hay.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class InventarioOrdenStockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->decimal('precio_base', 12, 2)->default(0);
            $t->string('foto_url')->nullable(); $t->boolean('personalizable')->default(false);
            $t->boolean('es_tapizado')->default(false); $t->boolean('tiene_tallas')->default(false);
            $t->unsignedInteger('piezas_por_juego')->nullable(); $t->decimal('precio_pieza', 12, 2)->nullable();
            $t->text('descripcion')->nullable(); $t->string('medidas')->nullable(); $t->string('material')->nullable();
            $t->boolean('activo')->default(true);
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });

        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Centro'], ['id' => 2, 'nombre' => 'Norte']]);
        // En orden alfabético quedan revueltos: Armonia 6, Avioneta 1, Banca 0, Base 2k 1, Base cama 0.
        DB::table('productos')->insert([
            ['id' => 1, 'nombre' => 'Armonia',   'categoria' => 'sillas'],
            ['id' => 2, 'nombre' => 'Avioneta',  'categoria' => 'accesorios'],
            ['id' => 3, 'nombre' => 'Banca',     'categoria' => 'bancas'],
            ['id' => 4, 'nombre' => 'Base 2k',   'categoria' => 'comedores'],
            ['id' => 5, 'nombre' => 'Base cama', 'categoria' => 'camas'],
        ]);
        DB::table('inventario')->insert([
            ['producto_id' => 1, 'tienda_id' => 1, 'cantidad_disponible' => 6],
            ['producto_id' => 2, 'tienda_id' => 1, 'cantidad_disponible' => 1],
            ['producto_id' => 3, 'tienda_id' => 1, 'cantidad_disponible' => 0],
            ['producto_id' => 4, 'tienda_id' => 1, 'cantidad_disponible' => 1],
            // Base cama: 0 en Centro, 3 en Norte.
            ['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 3],
        ]);
    }

    private function nombres(string $tienda): array
    {
        $u = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        return collect($this->actingAs($u)->getJson("/api/inventario?tienda_id={$tienda}")->assertOk()->json('data'))
            ->pluck('producto.nombre')->all();
    }

    public function test_en_una_tienda_va_de_mas_a_menos_stock(): void
    {
        // Empates (Avioneta y Base 2k con 1; Banca y Base cama con 0) por nombre.
        $this->assertSame(['Armonia', 'Avioneta', 'Base 2k', 'Banca', 'Base cama'], $this->nombres('1'));
    }

    public function test_en_todas_las_tiendas_suma_y_va_de_mas_a_menos(): void
    {
        $this->assertSame(['Armonia', 'Base cama', 'Avioneta', 'Base 2k', 'Banca'], $this->nombres('todas'));
    }
}
