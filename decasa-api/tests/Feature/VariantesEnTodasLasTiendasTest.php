<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * En Inventario, con "Todas las tiendas", las medidas y las tallas de un
 * producto salían en 0: el servidor solo calculaba su stock para una tienda.
 * Ahora trae el total y en qué tienda está cada una.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class VariantesEnTodasLasTiendasTest extends TestCase
{
    private const NORTE = 1, EDEN = 2, CAMA = 7;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('tipos_variante', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('afecta_precio')->default(true); $t->timestamps();
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id'); $t->string('nombre'); $t->timestamps();
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id');
            $t->unsignedBigInteger('opcion_id'); $t->decimal('precio_adicional', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('inventario_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0); $t->timestamps();
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->string('marca')->nullable();
            $t->string('marca_tela')->nullable(); $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0); $t->timestamps();
        });

        DB::table('tiendas')->insert([['id' => self::NORTE, 'nombre' => 'Decasa Norte'], ['id' => self::EDEN, 'nombre' => 'Decasa Vía El Edén']]);
    }

    private function usuario(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
    }

    public function test_las_medidas_traen_el_total_y_donde_esta_cada_una(): void
    {
        DB::table('tipos_variante')->insert(['id' => 1, 'nombre' => 'Cama Miami medidas']);
        DB::table('tipo_variante_opciones')->insert([['id' => 1, 'tipo_variante_id' => 1, 'nombre' => '1.40'], ['id' => 2, 'tipo_variante_id' => 1, 'nombre' => '1.60']]);
        DB::table('producto_variante_configs')->insert([
            ['id' => 10, 'producto_id' => self::CAMA, 'tipo_variante_id' => 1, 'opcion_id' => 1, 'precio_adicional' => 2_680_000],
            ['id' => 11, 'producto_id' => self::CAMA, 'tipo_variante_id' => 1, 'opcion_id' => 2, 'precio_adicional' => 2_980_000],
        ]);
        DB::table('inventario_variante_configs')->insert([
            ['config_id' => 10, 'tienda_id' => self::NORTE, 'cantidad_disponible' => 1],
            ['config_id' => 10, 'tienda_id' => self::EDEN,  'cantidad_disponible' => 2],
            ['config_id' => 11, 'tienda_id' => self::NORTE, 'cantidad_disponible' => 0],
        ]);

        $items = collect($this->actingAs($this->usuario())->getJson('/api/productos/' . self::CAMA . '/variante-configs')
            ->assertOk()->json('0.items'))->keyBy('opcion_nombre');

        $this->assertSame(3, $items['1.40']['stock_disponible']);
        $this->assertSame([
            ['tienda_id' => self::EDEN,  'tienda_nombre' => 'Decasa Vía El Edén', 'cantidad' => 2],
            ['tienda_id' => self::NORTE, 'tienda_nombre' => 'Decasa Norte',       'cantidad' => 1],
        ], $items['1.40']['por_tienda']);
        // La que no hay en ninguna: 0, y sin tiendas.
        $this->assertSame(0, $items['1.60']['stock_disponible']);
        $this->assertSame([], $items['1.60']['por_tienda']);

        // Con una tienda escogida sigue como antes: solo la suya, sin reparto.
        $norte = collect($this->actingAs($this->usuario())->getJson('/api/productos/' . self::CAMA . '/variante-configs?tienda_id=' . self::NORTE)
            ->assertOk()->json('0.items'))->keyBy('opcion_nombre');
        $this->assertSame(1, $norte['1.40']['stock_disponible']);
        $this->assertNull($norte['1.40']['por_tienda']);
    }

    public function test_las_tallas_traen_el_total_y_donde_esta_cada_una(): void
    {
        DB::table('producto_variantes')->insert(['id' => 5, 'producto_id' => self::CAMA, 'medida' => '1.40']);
        DB::table('inventario_variantes')->insert([
            ['variante_id' => 5, 'tienda_id' => self::NORTE, 'cantidad_disponible' => 2, 'cantidad_reservada' => 1],
            ['variante_id' => 5, 'tienda_id' => self::EDEN,  'cantidad_disponible' => 1, 'cantidad_reservada' => 0],
        ]);

        $v = $this->actingAs($this->usuario())->getJson('/api/productos/' . self::CAMA . '/variantes')->assertOk()->json('0');

        $this->assertSame(3, $v['stock_disponible']);
        $this->assertSame(1, $v['stock_reservado']);
        $this->assertSame(2, $v['stock_libre']);
        $this->assertSame([
            ['tienda_id' => self::NORTE, 'tienda_nombre' => 'Decasa Norte',       'cantidad' => 2],
            ['tienda_id' => self::EDEN,  'tienda_nombre' => 'Decasa Vía El Edén', 'cantidad' => 1],
        ], $v['por_tienda']);
    }
}
