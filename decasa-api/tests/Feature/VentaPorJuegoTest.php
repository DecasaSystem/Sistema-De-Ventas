<?php

namespace Tests\Feature;

use App\Models\OrdenItem;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Productos que se venden en juego (unas mesas de noche de a 2).
 *
 * El stock de esos productos se cuenta por piezas. Lo que ya estaba cargado
 * venía contado en juegos ("pongo 1"), así que activarlo multiplica, y
 * quitarlo divide; cambiar de juego de 2 a juego de 3 no toca nada porque
 * las piezas son las mismas. Mientras algo esté apartado o en camino no se
 * convierte: esa cantidad quedó guardada en la unidad vieja.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class VentaPorJuegoTest extends TestCase
{
    private const MESAS = 5, CENTRO = 1, NORTE = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('precio_base', 12, 2)->default(0);
            $t->unsignedSmallInteger('piezas_por_juego')->nullable(); $t->decimal('precio_pieza', 12, 2)->nullable();
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id');
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id')->default(1);
            $t->unsignedSmallInteger('piezas_por_juego')->nullable();
        });
        Schema::create('inventario_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_variante_combinaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('config_id');
            $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('traslados', function (Blueprint $t) { $t->id(); $t->string('estado')->default('completado'); });
        Schema::create('traslado_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('traslado_id'); $t->unsignedBigInteger('producto_id'); $t->integer('cantidad');
        });
        Schema::create('surtido_tiendas', function (Blueprint $t) { $t->id(); $t->string('estado')->default('pendiente'); });
        Schema::create('surtido_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_tienda_id'); $t->unsignedBigInteger('producto_id'); $t->integer('cantidad');
        });

        DB::table('tiendas')->insert([['id' => self::CENTRO, 'nombre' => 'Centro'], ['id' => self::NORTE, 'nombre' => 'Norte']]);
        DB::table('productos')->insert(['id' => self::MESAS, 'nombre' => 'Mesas de noche Roma', 'precio_base' => 900000]);
        DB::table('inventario')->insert([
            ['producto_id' => self::MESAS, 'tienda_id' => self::CENTRO, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0, 'stock_minimo' => 1],
            ['producto_id' => self::MESAS, 'tienda_id' => self::NORTE,  'cantidad_disponible' => 1, 'cantidad_reservada' => 0, 'stock_minimo' => 0],
        ]);
        DB::table('producto_variantes')->insert(['id' => 7, 'producto_id' => self::MESAS]);
        DB::table('inventario_variantes')->insert(['variante_id' => 7, 'tienda_id' => self::CENTRO, 'cantidad_disponible' => 2]);
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    private function stock(int $tienda): int
    {
        return (int) DB::table('inventario')->where('producto_id', self::MESAS)->where('tienda_id', $tienda)->value('cantidad_disponible');
    }

    private function juego(array $datos, ?Usuario $quien = null)
    {
        return $this->actingAs($quien ?? $this->supervisor())
            ->postJson('/api/productos/' . self::MESAS . '/venta-por-juego', $datos);
    }

    public function test_activar_convirtiendo_pasa_los_juegos_a_piezas_en_todas_las_tiendas(): void
    {
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();

        $this->assertSame(6, $this->stock(self::CENTRO));
        $this->assertSame(2, $this->stock(self::NORTE));
        // El reparto por variante y el mínimo también: cuentan lo mismo.
        $this->assertSame(4, (int) DB::table('inventario_variantes')->value('cantidad_disponible'));
        $this->assertSame(2, (int) DB::table('inventario')->where('tienda_id', self::CENTRO)->value('stock_minimo'));
        // Y el historial lo explica, con la diferencia exacta.
        $mov = DB::table('inventario_movimientos')->where('tienda_id', self::CENTRO)->first();
        $this->assertSame('entrada', $mov->tipo);
        $this->assertSame(3, (int) $mov->cantidad);
        $this->assertSame(2, (int) DB::table('productos')->value('piezas_por_juego'));
    }

    public function test_activar_sin_convertir_deja_el_stock_como_esta(): void
    {
        $this->juego(['piezas_por_juego' => 2])->assertOk();

        $this->assertSame(3, $this->stock(self::CENTRO));
        $this->assertSame(0, DB::table('inventario_movimientos')->count());
    }

    public function test_cambiar_de_juego_de_2_a_3_no_toca_las_piezas(): void
    {
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();
        $this->juego(['piezas_por_juego' => 3])->assertOk();

        $this->assertSame(6, $this->stock(self::CENTRO));
        $this->assertSame(3, (int) DB::table('productos')->value('piezas_por_juego'));
    }

    public function test_cambiar_el_numero_recalculando_rehace_las_piezas(): void
    {
        // Se activó con 2 y eran de a 3: los 3 juegos del Centro son 9 piezas, no 6.
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();
        $this->juego(['piezas_por_juego' => 3, 'convertir_stock' => true])->assertOk();

        $this->assertSame(9, $this->stock(self::CENTRO));
        $this->assertSame(3, $this->stock(self::NORTE));
    }

    /** Alas vintage: la opción "4 alas" viene de a 4 en un producto de a 2. */
    private function conOpciones(): array
    {
        DB::table('producto_variante_configs')->insert([
            ['id' => 21, 'producto_id' => self::MESAS, 'tipo_variante_id' => 1],
            ['id' => 22, 'producto_id' => self::MESAS, 'tipo_variante_id' => 1],
        ]);
        // En el Centro: 1 juego de 2 alas y 2 juegos de 4 alas (3 en total).
        DB::table('inventario_variante_configs')->insert([
            ['config_id' => 21, 'tienda_id' => self::CENTRO, 'cantidad_disponible' => 1],
            ['config_id' => 22, 'tienda_id' => self::CENTRO, 'cantidad_disponible' => 2],
        ]);
        // De los 2 de la tela 7, uno es de 4 alas.
        DB::table('inventario_variante_combinaciones')->insert(
            ['variante_id' => 7, 'config_id' => 22, 'tienda_id' => self::CENTRO, 'cantidad_disponible' => 1]
        );
        return [21, 22];
    }

    private function cfg(int $id): int
    {
        return (int) DB::table('inventario_variante_configs')->where('config_id', $id)->value('cantidad_disponible');
    }

    public function test_cada_opcion_convierte_con_sus_propias_piezas(): void
    {
        [$dos, $cuatro] = $this->conOpciones();

        $this->juego(['piezas_por_juego' => 2, 'piezas_opciones' => [$cuatro => 4], 'convertir_stock' => true])
            ->assertOk()->assertJsonPath('piezas_opciones.' . $cuatro, 4);

        $this->assertSame(2, $this->cfg($dos));      // 1 juego × 2
        $this->assertSame(8, $this->cfg($cuatro));   // 2 juegos × 4
        $this->assertSame(10, $this->stock(self::CENTRO));   // la suma de las dos
        $this->assertSame(2, $this->stock(self::NORTE));     // sin opciones: el del producto
        // Tela 7: 1 juego de 4 alas (4) + 1 sin opción, del producto (2).
        $this->assertSame(6, (int) DB::table('inventario_variantes')->value('cantidad_disponible'));
        $this->assertSame(4, (int) DB::table('inventario_variante_combinaciones')->value('cantidad_disponible'));

        // Y vuelve exacto al quitarlo.
        $this->juego(['piezas_por_juego' => null, 'convertir_stock' => true])->assertOk();
        $this->assertSame(1, $this->cfg($dos));
        $this->assertSame(2, $this->cfg($cuatro));
        $this->assertSame(3, $this->stock(self::CENTRO));
        $this->assertSame(2, (int) DB::table('inventario_variantes')->value('cantidad_disponible'));
        $this->assertNull(DB::table('producto_variante_configs')->where('id', $cuatro)->value('piezas_por_juego'));
    }

    public function test_poner_las_piezas_de_una_opcion_despues_recalcula_solo_esa(): void
    {
        [$dos, $cuatro] = $this->conOpciones();
        // Se activó todo de a 2; después se cae en cuenta de que "4 alas" es de a 4.
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();
        $this->juego(['piezas_por_juego' => 2, 'piezas_opciones' => [$cuatro => 4], 'convertir_stock' => true])->assertOk();

        $this->assertSame(2, $this->cfg($dos));
        $this->assertSame(8, $this->cfg($cuatro));
        $this->assertSame(10, $this->stock(self::CENTRO));
    }

    public function test_la_venta_usa_las_piezas_de_la_opcion(): void
    {
        [, $cuatro] = $this->conOpciones();
        $this->juego(['piezas_por_juego' => 2, 'piezas_opciones' => [$cuatro => 4]])->assertOk();

        $this->assertSame(
            ['piezas_juego' => 4, 'es_pieza_suelta' => false],
            OrdenItem::datosDeJuego(['producto_id' => self::MESAS, 'combo_config_id' => $cuatro, 'cantidad' => 8, 'venta_juego' => 'juego'])
        );
        // 2 piezas no son un juego de 4 alas.
        $this->assertIsString(
            OrdenItem::datosDeJuego(['producto_id' => self::MESAS, 'combo_config_id' => $cuatro, 'cantidad' => 2, 'venta_juego' => 'juego'])
        );
    }

    public function test_con_dos_tipos_de_variante_no_se_ponen_piezas_por_opcion(): void
    {
        [, $cuatro] = $this->conOpciones();
        DB::table('producto_variante_configs')->insert(['id' => 30, 'producto_id' => self::MESAS, 'tipo_variante_id' => 2]);

        $this->juego(['piezas_por_juego' => 2, 'piezas_opciones' => [$cuatro => 4], 'convertir_stock' => true])
            ->assertStatus(422);
        $this->assertSame(3, $this->stock(self::CENTRO));
        // Con un solo número para todo el producto sí se puede.
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();
    }

    public function test_media_pareja_no_vuelve_a_juegos(): void
    {
        [, $cuatro] = $this->conOpciones();
        $this->juego(['piezas_por_juego' => 2, 'piezas_opciones' => [$cuatro => 4], 'convertir_stock' => true])->assertOk();
        // Se vendieron 2 alas sueltas del juego de 4: quedan 6, no son juegos completos de 4.
        DB::table('inventario_variante_configs')->where('config_id', $cuatro)->update(['cantidad_disponible' => 6]);
        DB::table('inventario')->where('tienda_id', self::CENTRO)->update(['cantidad_disponible' => 8]);

        $this->juego(['piezas_por_juego' => null, 'convertir_stock' => true])->assertStatus(422);
        $this->assertSame(6, $this->cfg($cuatro));
    }

    public function test_quitarlo_convirtiendo_vuelve_a_juegos(): void
    {
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();
        $this->juego(['piezas_por_juego' => null, 'convertir_stock' => true])->assertOk();

        $this->assertSame(3, $this->stock(self::CENTRO));
        $this->assertSame(1, $this->stock(self::NORTE));
        $this->assertNull(DB::table('productos')->value('piezas_por_juego'));
    }

    public function test_no_se_vuelve_a_juegos_si_quedo_media_pareja(): void
    {
        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertOk();
        // Se vendió una mesa suelta: quedan 5 piezas en el Centro.
        DB::table('inventario')->where('tienda_id', self::CENTRO)->update(['cantidad_disponible' => 5]);

        $this->juego(['piezas_por_juego' => null, 'convertir_stock' => true])
            ->assertStatus(422)->assertJsonFragment(['message' => 'En Centro hay 5 pieza(s): no son juegos completos de 2. Guárdalo sin convertir (el stock queda como está, contado por piezas) o ajusta ese stock primero.']);
        $this->assertSame(2, (int) DB::table('productos')->value('piezas_por_juego'));
        $this->assertSame(5, $this->stock(self::CENTRO));
    }

    public function test_no_convierte_con_unidades_apartadas(): void
    {
        DB::table('inventario')->where('tienda_id', self::NORTE)->update(['cantidad_reservada' => 1]);

        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertStatus(422);

        $this->assertSame(3, $this->stock(self::CENTRO));
        $this->assertNull(DB::table('productos')->value('piezas_por_juego'));
    }

    public function test_no_convierte_con_un_traslado_en_camino(): void
    {
        DB::table('traslados')->insert(['id' => 1, 'estado' => 'pendiente']);
        DB::table('traslado_items')->insert(['traslado_id' => 1, 'producto_id' => self::MESAS, 'cantidad' => 1]);

        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true])->assertStatus(422);
        $this->assertSame(3, $this->stock(self::CENTRO));
    }

    public function test_solo_un_supervisor_lo_cambia(): void
    {
        $vendedor = Usuario::create(['nombre' => 'Ana', 'email' => 'a@d.com', 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);

        $this->juego(['piezas_por_juego' => 2, 'convertir_stock' => true], $vendedor)->assertForbidden();
        $this->assertSame(3, $this->stock(self::CENTRO));
    }

    public function test_la_orden_valida_juegos_completos_y_guarda_como_se_vendio(): void
    {
        DB::table('productos')->update(['piezas_por_juego' => 2]);

        $this->assertSame(
            ['piezas_juego' => 2, 'es_pieza_suelta' => false],
            OrdenItem::datosDeJuego(['producto_id' => self::MESAS, 'cantidad' => 4, 'venta_juego' => 'juego'])
        );
        $this->assertSame(
            ['piezas_juego' => 2, 'es_pieza_suelta' => true],
            OrdenItem::datosDeJuego(['producto_id' => self::MESAS, 'cantidad' => 1, 'venta_juego' => 'pieza'])
        );
        // Tres piezas no son juegos completos de 2.
        $this->assertIsString(
            OrdenItem::datosDeJuego(['producto_id' => self::MESAS, 'cantidad' => 3, 'venta_juego' => 'juego'])
        );
        // Sin el juego activo (se quitó mientras se armaba) va por unidad.
        DB::table('productos')->update(['piezas_por_juego' => null]);
        $this->assertSame(
            [],
            OrdenItem::datosDeJuego(['producto_id' => self::MESAS, 'cantidad' => 3, 'venta_juego' => 'juego'])
        );
    }

    public function test_la_variante_dice_cuantos_juegos_son(): void
    {
        $juego = new OrdenItem(['cantidad' => 4, 'piezas_juego' => 2, 'es_pieza_suelta' => false, 'variante_detalle' => 'Nogal']);
        $this->assertSame('Nogal · 2 juegos de 2 piezas', $juego->variante_texto);

        $suelta = new OrdenItem(['cantidad' => 1, 'piezas_juego' => 2, 'es_pieza_suelta' => true]);
        $this->assertSame('Pieza suelta de un juego de 2', $suelta->variante_texto);

        $normal = new OrdenItem(['cantidad' => 2, 'variante_detalle' => 'Nogal']);
        $this->assertSame('Nogal', $normal->variante_texto);
    }
}
