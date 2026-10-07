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
            ['id' => 1, 'nombre' => 'Armonia',   'categoria' => 'sillas',     'precio_base' => 780000],
            ['id' => 2, 'nombre' => 'Avioneta',  'categoria' => 'accesorios', 'precio_base' => 168000],
            ['id' => 3, 'nombre' => 'Banca',     'categoria' => 'bancas',     'precio_base' => 1980000],
            ['id' => 4, 'nombre' => 'Base 2k',   'categoria' => 'comedores',  'precio_base' => 1480000],
            ['id' => 5, 'nombre' => 'Base cama', 'categoria' => 'camas',      'precio_base' => 980000],
        ]);
        DB::table('inventario')->insert([
            ['producto_id' => 1, 'tienda_id' => 1, 'cantidad_disponible' => 6, 'cantidad_reservada' => 6, 'stock_minimo' => 0],
            ['producto_id' => 2, 'tienda_id' => 1, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0, 'stock_minimo' => 2],
            ['producto_id' => 3, 'tienda_id' => 1, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1],
            ['producto_id' => 4, 'tienda_id' => 1, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
            // Base cama: 0 en Centro, 3 en Norte.
            ['producto_id' => 5, 'tienda_id' => 2, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
        ]);
    }

    private function nombres(string $tienda, string $extra = ''): array
    {
        $u = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        return collect($this->actingAs($u)->getJson("/api/inventario?tienda_id={$tienda}{$extra}")->assertOk()->json('data'))
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

    public function test_otros_ordenes(): void
    {
        $this->assertSame(['Banca', 'Base cama', 'Avioneta', 'Base 2k', 'Armonia'], $this->nombres('1', '&orden=stock_asc'));
        // Armonia tiene 6 pero las 6 apartadas: libre 0, cae detrás de los de 1.
        $this->assertSame(['Avioneta', 'Base 2k', 'Armonia', 'Banca', 'Base cama'], $this->nombres('1', '&orden=libre_desc'));
        $this->assertSame(['Armonia', 'Avioneta', 'Banca', 'Base 2k', 'Base cama'], $this->nombres('1', '&orden=nombre_asc'));
        $this->assertSame(['Base cama', 'Base 2k', 'Banca', 'Avioneta', 'Armonia'], $this->nombres('1', '&orden=nombre_desc'));
        $this->assertSame(['Banca', 'Base 2k', 'Base cama', 'Armonia', 'Avioneta'], $this->nombres('todas', '&orden=precio_desc'));
        $this->assertSame(['Avioneta', 'Armonia', 'Base cama', 'Base 2k', 'Banca'], $this->nombres('todas', '&orden=precio_asc'));
        // Uno desconocido no rompe: queda el de siempre.
        $this->assertSame(['Armonia', 'Avioneta', 'Base 2k', 'Banca', 'Base cama'], $this->nombres('1', '&orden=xyz'));
    }

    public function test_filtro_de_existencias(): void
    {
        $this->assertSame(['Armonia', 'Avioneta', 'Base 2k'], $this->nombres('1', '&existencia=con_stock'));
        $this->assertSame(['Banca', 'Base cama'], $this->nombres('1', '&existencia=agotados'));
        // Bajo mínimo: solo con mínimo puesto (Avioneta 1 de 2, Banca 0 de 1).
        $this->assertSame(['Avioneta', 'Banca'], $this->nombres('1', '&existencia=bajo_minimo'));

        // En todas las tiendas cuenta la suma: Base cama tiene 3 en Norte.
        $this->assertSame(['Armonia', 'Base cama', 'Avioneta', 'Base 2k'], $this->nombres('todas', '&existencia=con_stock'));
        $this->assertSame(['Banca'], $this->nombres('todas', '&existencia=agotados'));
        $u = Usuario::first();
        $this->actingAs($u)->getJson('/api/inventario?tienda_id=todas&existencia=agotados')->assertJsonPath('total', 1);
    }
}
