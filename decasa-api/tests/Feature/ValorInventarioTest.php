<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\ValorInventario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cuánta plata hay en inventario (dueño, 2026-11-06): "31 sofás a 1 millón =
 * 31 millones", por tienda, por categoría y en total, con las variantes.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ValorInventarioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('acceso_reserva')->default(false);
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->decimal('precio_base', 12, 2)->default(0);
            $t->string('foto_url')->nullable(); $t->boolean('personalizable')->default(false);
            $t->boolean('es_tapizado')->default(false); $t->boolean('tiene_tallas')->default(false);
            $t->integer('piezas_por_juego')->nullable(); $t->decimal('precio_pieza', 12, 2)->nullable();
            $t->text('descripcion')->nullable(); $t->string('medidas')->nullable(); $t->string('material')->nullable();
            $t->boolean('activo')->default(true);
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->decimal('precio_variante', 12, 2)->nullable();
            $t->boolean('activo')->default(true);
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id');
            $t->unsignedBigInteger('opcion_id')->default(0); $t->decimal('precio_adicional', 12, 2)->default(0);
        });
        Schema::create('inventario_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Norte',  'activa' => true,  'es_fabrica' => false],
            ['id' => 2, 'nombre' => 'Decasa Edén',   'activa' => true,  'es_fabrica' => false],
            ['id' => 3, 'nombre' => 'Circunvalar',   'activa' => false, 'es_fabrica' => false],
            ['id' => 9, 'nombre' => 'Fábrica',       'activa' => true,  'es_fabrica' => true],
        ]);
        DB::table('productos')->insert([
            ['id' => 1, 'nombre' => 'Sofá Bali',  'categoria' => 'sofas', 'precio_base' => 1000000, 'activo' => true],
            ['id' => 2, 'nombre' => 'Cama Miami', 'categoria' => 'camas', 'precio_base' => 2000000, 'activo' => true],
            ['id' => 3, 'nombre' => 'Apagado',    'categoria' => 'sofas', 'precio_base' => 9000000, 'activo' => false],
        ]);
        DB::table('inventario')->insert([
            // 31 sofás entre dos tiendas (1 apartado en Norte).
            ['producto_id' => 1, 'tienda_id' => 1, 'cantidad_disponible' => 20, 'cantidad_reservada' => 1],
            ['producto_id' => 1, 'tienda_id' => 2, 'cantidad_disponible' => 11, 'cantidad_reservada' => 0],
            // Tienda cerrada y producto apagado: no cuentan.
            ['producto_id' => 1, 'tienda_id' => 3, 'cantidad_disponible' => 5, 'cantidad_reservada' => 0],
            ['producto_id' => 3, 'tienda_id' => 1, 'cantidad_disponible' => 4, 'cantidad_reservada' => 0],
            // Camas: 5 en Norte, 3 en fábrica.
            ['producto_id' => 2, 'tienda_id' => 1, 'cantidad_disponible' => 5, 'cantidad_reservada' => 1],
            ['producto_id' => 2, 'tienda_id' => 9, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0],
        ]);

        // Sofá: telas sin precio propio (no cambian nada).
        DB::table('producto_variantes')->insert(['id' => 1, 'producto_id' => 1, 'precio_variante' => null]);
        DB::table('inventario_variantes')->insert(['variante_id' => 1, 'tienda_id' => 1, 'cantidad_disponible' => 10]);

        // Cama: medidas 1.40 (precio del producto: 0) y 1.60 ($2.500.000).
        DB::table('producto_variante_configs')->insert([
            ['id' => 1, 'producto_id' => 2, 'tipo_variante_id' => 7, 'opcion_id' => 1, 'precio_adicional' => 0],
            ['id' => 2, 'producto_id' => 2, 'tipo_variante_id' => 7, 'opcion_id' => 2, 'precio_adicional' => 2500000],
        ]);
        DB::table('inventario_variante_configs')->insert([
            // Norte: 2 de 1.40, 2 de 1.60 (una apartada) y 1 sin marcar.
            ['config_id' => 1, 'tienda_id' => 1, 'cantidad_disponible' => 2, 'cantidad_reservada' => 0],
            ['config_id' => 2, 'tienda_id' => 1, 'cantidad_disponible' => 2, 'cantidad_reservada' => 1],
            // Fábrica: el reparto se pasa del stock (5 marcadas, hay 3).
            ['config_id' => 2, 'tienda_id' => 9, 'cantidad_disponible' => 5, 'cantidad_reservada' => 0],
        ]);
    }

    private function usuario(string $rol = 'supervisor', bool $reserva = false): Usuario
    {
        return Usuario::create([
            'nombre' => 'U'.uniqid(), 'email' => uniqid().'@d.com', 'password' => 'x',
            'rol' => $rol, 'acceso_reserva' => $reserva, 'created_at' => now(),
        ]);
    }

    public function test_sofas_en_todas_las_tiendas_son_31_millones_y_dice_cuanto_en_cada_una(): void
    {
        $r = $this->actingAs($this->usuario())
            ->getJson('/api/inventario/resumen-categoria?tienda_id=todas&categoria=sofas')
            ->assertOk();

        $this->assertSame(31, $r->json('cantidad_disponible'));
        $this->assertEquals(31000000, $r->json('valor'));
        $this->assertEquals(1000000, $r->json('valor_apartado'));
        $this->assertEquals(30000000, $r->json('valor_libre'));

        $porTienda = collect($r->json('por_tienda'))->keyBy('tienda_id');
        $this->assertEquals(20000000, $porTienda[1]['valor']);
        $this->assertEquals(11000000, $porTienda[2]['valor']);
        $this->assertArrayNotHasKey(3, $porTienda->all(), 'La tienda cerrada no cuenta');
        // La que más vale primero.
        $this->assertSame(1, $r->json('por_tienda.0.tienda_id'));
    }

    public function test_cada_medida_vale_lo_suyo_y_lo_sin_marcar_al_precio_del_producto(): void
    {
        $r = $this->actingAs($this->usuario())
            ->getJson('/api/inventario/resumen-categoria?tienda_id=1&categoria=camas')
            ->assertOk();

        // 2 × 2.000.000 (1.40) + 2 × 2.500.000 (1.60) + 1 sin marcar × 2.000.000
        $this->assertEquals(11000000, $r->json('valor'));
        $this->assertEquals(2500000, $r->json('valor_apartado'), 'Lo apartado es una 1.60');
        $this->assertSame(1, $r->json('sin_marcar'));
    }

    public function test_un_reparto_que_se_pasa_del_stock_no_infla_la_cifra(): void
    {
        $filas = ValorInventario::filas(9, 'camas');
        $this->assertCount(1, $filas);
        $this->assertSame(3, $filas[0]['unidades']);
        $this->assertEquals(7500000, $filas[0]['valor'], 'Solo 3 unidades, aunque haya 5 marcadas');
        $this->assertSame(0, $filas[0]['sin_marcar']);
    }

    public function test_todas_las_categorias_da_el_total_y_el_reparto(): void
    {
        $r = $this->actingAs($this->usuario())
            ->getJson('/api/inventario/resumen-categoria?tienda_id=todas')
            ->assertOk();

        // 31 sofás + 5 camas en Norte + 3 en fábrica.
        $this->assertSame(39, $r->json('cantidad_disponible'));
        $this->assertEquals(31000000 + 11000000 + 7500000, $r->json('valor'));
        $this->assertSame(2, $r->json('productos'), 'Productos activos del catálogo');

        $cats = collect($r->json('por_categoria'))->keyBy('categoria');
        $this->assertEquals(31000000, $cats['sofas']['valor']);
        $this->assertEquals(18500000, $cats['camas']['valor']);
        $this->assertSame(1, $cats['camas']['productos_con_stock'], 'Una cama en dos tiendas es un producto');
    }

    public function test_el_vendedor_ve_las_unidades_pero_no_la_plata(): void
    {
        $r = $this->actingAs($this->usuario('vendedor'))
            ->getJson('/api/inventario/resumen-categoria?tienda_id=todas')
            ->assertOk();

        $this->assertSame(39, $r->json('cantidad_disponible'));
        $this->assertNull($r->json('valor'));
        $this->assertNull($r->json('por_tienda.0.valor'));
        $this->assertNull($r->json('por_categoria.0.valor'));
        $this->assertNotNull($r->json('por_categoria.0.unidades'));
    }

    public function test_el_excel_trae_el_valor_de_cada_producto_con_sus_variantes(): void
    {
        $u = $this->usuario();
        $item = collect($this->actingAs($u)->getJson('/api/inventario?tienda_id=1&con_valor=1')->assertOk()->json('data'))
            ->firstWhere('producto_id', 2);
        $this->assertEquals(11000000, $item['valor']);

        $todas = collect($this->actingAs($u)->getJson('/api/inventario?tienda_id=todas&con_valor=1')->assertOk()->json('data'))
            ->firstWhere('producto_id', 2);
        $this->assertEquals(18500000, $todas['valor']);
        $this->assertEquals(7500000, collect($todas['por_tienda'])->firstWhere('tienda_id', 9)['valor']);

        // Sin pedirlo no se calcula (la pantalla no lo usa).
        $sin = collect($this->actingAs($u)->getJson('/api/inventario?tienda_id=1')->json('data'))->firstWhere('producto_id', 2);
        $this->assertArrayNotHasKey('valor', $sin);
    }

    public function test_reserva_da_el_total_de_la_fabrica(): void
    {
        $r = $this->actingAs($this->usuario())
            ->getJson('/api/reserva/resumen')
            ->assertOk();

        $this->assertSame(3, $r->json('cantidad_disponible'));
        $this->assertEquals(7500000, $r->json('valor'));
        $this->assertSame('camas', $r->json('por_categoria.0.categoria'));

        $cat = $this->actingAs($this->usuario())->getJson('/api/reserva/resumen?categoria=sofas')->assertOk();
        $this->assertSame(0, $cat->json('cantidad_disponible'));
        $this->assertEquals(0, $cat->json('valor'));

        // Solo supervisores (dueño, 2026-11-06): tener acceso a la Reserva no basta.
        $conAcceso = $this->actingAs($this->usuario('vendedor', true))->getJson('/api/reserva/resumen')
            ->assertOk()->assertJsonMissingPath('valor');
        $this->assertSame(3, $conAcceso->json('cantidad_disponible'));
        $this->assertNull($conAcceso->json('por_categoria.0.valor'));
    }

    public function test_el_excel_del_vendedor_no_trae_la_plata(): void
    {
        $item = collect($this->actingAs($this->usuario('vendedor'))
            ->getJson('/api/inventario?tienda_id=todas&con_valor=1')->assertOk()->json('data'))
            ->firstWhere('producto_id', 2);
        $this->assertArrayNotHasKey('valor', $item);
    }

    public function test_la_tela_con_precio_propio_cuenta_si_no_hay_medidas(): void
    {
        // [disp, res, precio]
        [$valor, $res, $sin] = ValorInventario::valorar(
            ['tela' => [[2, 0, 1200000.0], [1, 1, null]]], 1000000, 4, 1
        );
        // 2 × 1.2 M + 1 × 1 M (tela sin precio) + 1 sin marcar × 1 M
        $this->assertEquals(4400000, $valor);
        $this->assertEquals(1000000, $res);
        $this->assertSame(1, $sin);
    }
}
