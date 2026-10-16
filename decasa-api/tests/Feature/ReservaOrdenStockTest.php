<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La Reserva de fábrica ordena igual que Inventario: de más a menos stock
 * también en "Todas" (antes iba por nombre y los de 0 salían revueltos), con
 * el mismo menú de orden y filtro de existencias.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReservaOrdenStockTest extends TestCase
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
            $t->string('foto_url')->nullable();
            $t->boolean('es_tapizado')->default(false); $t->boolean('tiene_tallas')->default(false);
            $t->boolean('activo')->default(true);
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Centro', 'es_fabrica' => false],
            ['id' => 9, 'nombre' => 'Fábrica', 'es_fabrica' => true],
        ]);
        DB::table('productos')->insert([
            ['id' => 1, 'nombre' => 'Armonia',   'categoria' => 'sillas',     'precio_base' => 780000],
            ['id' => 2, 'nombre' => 'Avioneta',  'categoria' => 'accesorios', 'precio_base' => 168000],
            ['id' => 3, 'nombre' => 'Banca',     'categoria' => 'bancas',     'precio_base' => 1980000],
            ['id' => 4, 'nombre' => 'Base 2k',   'categoria' => 'comedores',  'precio_base' => 1480000],
            ['id' => 5, 'nombre' => 'Base cama', 'categoria' => 'camas',      'precio_base' => 980000],
        ]);
        DB::table('inventario')->insert([
            ['producto_id' => 1, 'tienda_id' => 9, 'cantidad_disponible' => 6, 'cantidad_reservada' => 6, 'stock_minimo' => 0],
            ['producto_id' => 2, 'tienda_id' => 9, 'cantidad_disponible' => 1, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
            ['producto_id' => 4, 'tienda_id' => 9, 'cantidad_disponible' => 2, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
            // Lo de otra tienda no cuenta en la fábrica.
            ['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 9, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
        ]);
    }

    private function nombres(string $query = ''): array
    {
        $u = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        return collect($this->actingAs($u)->getJson("/api/reserva/inventario{$query}")->assertOk()->json('data'))
            ->pluck('producto.nombre')->all();
    }

    public function test_sin_categoria_va_de_mas_a_menos_stock(): void
    {
        // Banca y Base cama en 0 en la fábrica: al final, por nombre.
        $this->assertSame(['Armonia', 'Base 2k', 'Avioneta', 'Banca', 'Base cama'], $this->nombres());
    }

    public function test_otros_ordenes_y_existencias(): void
    {
        // Armonia tiene 6 pero las 6 apartadas: libre 0, cae detrás.
        $this->assertSame(['Base 2k', 'Avioneta', 'Armonia', 'Banca', 'Base cama'], $this->nombres('?orden=libre_desc'));
        $this->assertSame(['Armonia', 'Avioneta', 'Banca', 'Base 2k', 'Base cama'], $this->nombres('?orden=nombre_asc'));
        $this->assertSame(['Armonia', 'Base 2k', 'Avioneta'], $this->nombres('?existencia=con_stock'));
        $this->assertSame(['Banca', 'Base cama'], $this->nombres('?existencia=agotados'));
        // En la fábrica no hay mínimo: se ignora y sale todo.
        $this->assertCount(5, $this->nombres('?existencia=bajo_minimo'));
    }
}
