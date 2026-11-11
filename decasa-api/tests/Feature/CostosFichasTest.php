<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\Costos\BomBuilder;
use App\Services\Costos\FichaRetriever;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Costos de producción: fichas técnicas, materiales y tarifas.
 *
 * Las fichas se enlazan con el catálogo de materiales por NOMBRE (no por id), así que
 * renombrar o cambiar un material tiene que arrastrar esos ítems; y lo que se crea desde
 * la app tiene que quedar igual de conectado que lo que vino del Excel (foto, vínculo con
 * la tarifa, índice del cotizador).
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CostosFichasTest extends TestCase
{
    private Usuario $ebanista;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_costos')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('fichas_tecnicas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->decimal('costo_materiales', 12, 2)->default(0); $t->decimal('costo_mano_obra', 12, 2)->default(0);
            $t->decimal('costo_total', 12, 2)->default(0); $t->string('ruta_excel')->nullable();
            $t->string('foto_url', 500)->nullable(); $t->longText('embedding')->nullable();
            $t->timestamp('embedding_at')->nullable(); $t->timestamps();
        });
        Schema::create('salarios_cargo', function (Blueprint $t) {
            $t->id(); $t->string('cargo')->unique(); $t->string('descripcion')->nullable();
            $t->decimal('salario_mensual', 12, 2)->default(0); $t->integer('dias_laborales_mes')->default(26);
            $t->decimal('tarifa_hora', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('tarifas_proceso', function (Blueprint $t) {
            $t->id(); $t->string('proceso'); $t->string('descripcion')->nullable(); $t->string('unidad')->nullable();
            $t->string('cargo')->nullable(); $t->string('aplica_a')->nullable();
            $t->decimal('dias_por_unidad', 8, 4)->default(0); $t->decimal('tarifa', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('ficha_tecnica_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('ficha_tecnica_id'); $t->string('seccion')->nullable();
            $t->string('descripcion'); $t->decimal('cantidad', 12, 4)->default(0); $t->string('unidad')->nullable();
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('es_mano_obra')->default(false); $t->unsignedBigInteger('tarifa_proceso_id')->nullable();
            $t->unsignedBigInteger('material_id')->nullable();
            $t->integer('orden')->default(0); $t->timestamps();
        });
        Schema::create('materiales', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('descripcion')->nullable(); $t->string('unidad')->nullable();
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('precio_base', 12, 2)->default(0); $t->boolean('activo')->default(true);
        });
        Schema::create('material_precio_historial', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('material_id')->nullable(); $t->string('material_nombre');
            $t->decimal('precio_anterior', 12, 2); $t->decimal('precio_nuevo', 12, 2);
            $t->unsignedInteger('productos_afectados')->default(0); $t->decimal('impacto_total', 14, 2)->default(0);
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });

        $this->ebanista = Usuario::forceCreate(['nombre' => 'Ebanista', 'rol' => 'ebanista', 'created_at' => now()]);

        // Indexar llama a OpenAI: aquí solo importa que se pida
        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->andReturnTrue()->byDefault();
    }

    private function ficha(string $nombre, array $items): int
    {
        $id = DB::table('fichas_tecnicas')->insertGetId(['nombre' => $nombre, 'categoria' => 'SOFAS']);
        foreach ($items as $i => $it) {
            DB::table('ficha_tecnica_items')->insert($it + [
                'ficha_tecnica_id' => $id, 'orden' => $i, 'es_mano_obra' => false,
                'subtotal' => round($it['cantidad'] * $it['precio_unitario'], 2),
            ]);
        }
        \App\Models\FichaTecnica::recalcularTotales($id);
        return $id;
    }

    public function test_crear_ficha_guarda_la_foto_vincula_la_tarifa_y_la_indexa(): void
    {
        DB::table('salarios_cargo')->insert(['cargo' => 'tapicero', 'tarifa_hora' => 10000]);
        $tarifa = DB::table('tarifas_proceso')->insertGetId(['proceso' => 'tapizado', 'cargo' => 'tapicero', 'dias_por_unidad' => 0.5]);

        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->once()->andReturnTrue();

        $r = $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'SOFA X', 'categoria' => 'SOFAS', 'foto_url' => 'https://res.cloudinary.com/x/sofa.jpg',
            'items' => [
                ['seccion' => 'Materiales', 'descripcion' => 'TELA', 'cantidad' => 3, 'unidad' => 'METROS',
                 'precio_unitario' => 20000, 'subtotal' => 1, 'es_mano_obra' => false],
                ['seccion' => 'Mano de obra', 'descripcion' => 'tapizado', 'cantidad' => 4, 'unidad' => 'horas',
                 'precio_unitario' => 10000, 'subtotal' => 40000, 'es_mano_obra' => true, 'tarifa_proceso_id' => $tarifa],
            ],
        ]);

        $r->assertCreated();
        $ficha = DB::table('fichas_tecnicas')->first();
        $this->assertSame('https://res.cloudinary.com/x/sofa.jpg', $ficha->foto_url);
        // El subtotal lo recalcula el servidor (el cliente mandó 1)
        $this->assertEquals(60000, $ficha->costo_materiales);
        $this->assertEquals(100000, $ficha->costo_total);
        $mo = DB::table('ficha_tecnica_items')->where('es_mano_obra', true)->first();
        $this->assertEquals($tarifa, $mo->tarifa_proceso_id);
    }

    public function test_editar_ficha_guarda_la_foto_y_el_material_cambiado(): void
    {
        $id   = $this->ficha('SOFA Y', [['descripcion' => 'TELA VIEJA', 'cantidad' => 2, 'unidad' => 'METROS', 'precio_unitario' => 10000]]);
        $item = DB::table('ficha_tecnica_items')->value('id');

        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/items", [
            'foto_url' => 'https://res.cloudinary.com/x/y.jpg',
            'items'    => [['id' => $item, 'descripcion' => 'TELA NUEVA', 'unidad' => 'METRO',
                            'cantidad' => 2, 'precio_unitario' => 15000, 'subtotal' => 30000]],
        ])->assertOk();

        $this->assertSame('https://res.cloudinary.com/x/y.jpg', DB::table('fichas_tecnicas')->value('foto_url'));
        $it = DB::table('ficha_tecnica_items')->find($item);
        $this->assertSame('TELA NUEVA', $it->descripcion);
        $this->assertSame('METRO', $it->unidad);
        $this->assertEquals(30000, DB::table('fichas_tecnicas')->value('costo_total'));
    }

    public function test_renombrar_ficha_la_reindexa(): void
    {
        $id   = $this->ficha('SOFA Z', [['descripcion' => 'TELA', 'cantidad' => 1, 'precio_unitario' => 1000]]);
        $item = DB::table('ficha_tecnica_items')->value('id');

        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->once()->with($id)->andReturnTrue();

        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/items", [
            'nombre' => 'sofa z grande',
            'items'  => [['id' => $item, 'cantidad' => 1, 'precio_unitario' => 1000]],
        ])->assertOk();

        $this->assertSame('SOFA Z GRANDE', DB::table('fichas_tecnicas')->value('nombre'));
    }

    public function test_renombrar_un_material_arrastra_los_items_de_las_fichas(): void
    {
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'COLBON', 'unidad' => 'BOTELLA', 'precio_unitario' => 18000]);
        $a = $this->ficha('SOFA A', [['descripcion' => 'COLBON', 'cantidad' => 2, 'unidad' => 'BOTELLA', 'precio_unitario' => 18000]]);
        $b = $this->ficha('SOFA B', [['descripcion' => 'COLBON ', 'cantidad' => 1, 'unidad' => 'BOTELLAS', 'precio_unitario' => 18000]]);

        $r = $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$mat}", [
            'nombre' => 'CARPINCOL', 'unidad' => 'BOTELLA', 'precio_unitario' => 20000,
        ]);

        $r->assertOk()->assertJsonPath('productos_afectados', 2);
        $this->assertSame(0, DB::table('ficha_tecnica_items')->where('descripcion', 'like', 'COLBON%')->count());
        $this->assertSame(2, DB::table('ficha_tecnica_items')->where('descripcion', 'CARPINCOL')->count());
        $this->assertEquals(40000, DB::table('fichas_tecnicas')->where('id', $a)->value('costo_total'));
        $this->assertEquals(20000, DB::table('fichas_tecnicas')->where('id', $b)->value('costo_total'));
        // La unidad no cambió en el material, así que cada ítem conserva la suya
        $this->assertSame('BOTELLAS', DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $b)->value('unidad'));

        // Un segundo cambio de precio sigue encontrando los ítems con el nombre nuevo
        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$mat}", [
            'nombre' => 'CARPINCOL', 'unidad' => 'BOTELLA', 'precio_unitario' => 25000,
        ])->assertJsonPath('productos_afectados', 2);
        $this->assertEquals(50000, DB::table('fichas_tecnicas')->where('id', $a)->value('costo_total'));
    }

    public function test_los_items_enlazados_por_id_siguen_al_material_aunque_el_texto_no_coincida(): void
    {
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'ESPUMA 26', 'precio_unitario' => 50000]);
        $id  = $this->ficha('SOFA ID', [['descripcion' => 'espuma vieja', 'cantidad' => 2, 'precio_unitario' => 50000, 'material_id' => $mat]]);

        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$mat}", [
            'nombre' => 'ESPUMA 26', 'precio_unitario' => 60000,
        ])->assertJsonPath('productos_afectados', 1);

        $item = DB::table('ficha_tecnica_items')->first();
        $this->assertSame('ESPUMA 26', $item->descripcion);
        $this->assertEquals(120000, DB::table('fichas_tecnicas')->where('id', $id)->value('costo_total'));

        // Y "usos" lo encuentra por id
        $this->actingAs($this->ebanista)->getJson("/api/materiales/{$mat}/usos")->assertJsonPath('total', 1);
        $this->actingAs($this->ebanista)->getJson('/api/materiales')->assertJsonPath('0.usos', 1);
    }

    public function test_un_material_escrito_a_mano_se_enlaza_con_el_catalogo_y_el_resto_queda_para_revisar(): void
    {
        $tela = DB::table('materiales')->insertGetId(['nombre' => 'TELA LINO', 'precio_unitario' => 30000]);

        $r = $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'SOFA N', 'categoria' => 'SOFAS',
            'items' => [
                ['descripcion' => ' tela lino ', 'cantidad' => 2, 'precio_unitario' => 30000, 'es_mano_obra' => false],
                ['descripcion' => 'BOTONES RAROS', 'cantidad' => 4, 'precio_unitario' => 0, 'es_mano_obra' => false],
            ],
        ])->assertCreated();

        $items = DB::table('ficha_tecnica_items')->orderBy('id')->get();
        $this->assertEquals($tela, $items[0]->material_id);
        $this->assertNull($items[1]->material_id);

        $f = $this->actingAs($this->ebanista)->getJson('/api/fichas-tecnicas')->json('fichas.0');
        $this->assertSame(1, $f['fuera_catalogo']);
        $this->assertSame(1, $f['sin_precio']);

        // Crear el material en el catálogo enlaza el ítem que estaba suelto
        $this->actingAs($this->ebanista)->postJson('/api/materiales', ['nombre' => 'BOTONES RAROS', 'precio_unitario' => 500])->assertCreated();
        $this->assertNotNull(DB::table('ficha_tecnica_items')->where('id', $items[1]->id)->value('material_id'));

        // Cambiar el material desde el buscador cambia el enlace
        $otra = DB::table('materiales')->insertGetId(['nombre' => 'TELA PANA', 'precio_unitario' => 40000]);
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$r->json('id')}/items", ['items' => [
            ['id' => $items[0]->id, 'descripcion' => 'TELA PANA', 'material_id' => $otra, 'cantidad' => 2, 'precio_unitario' => 40000],
        ]])->assertOk();
        $this->assertEquals($otra, DB::table('ficha_tecnica_items')->where('id', $items[0]->id)->value('material_id'));
    }

    public function test_la_migracion_enlaza_por_nombre_los_items_que_ya_existian(): void
    {
        Schema::table('ficha_tecnica_items', fn (Blueprint $t) => $t->dropColumn('material_id'));
        $viejo = DB::table('materiales')->insertGetId(['nombre' => 'PINO', 'precio_unitario' => 1]);
        DB::table('materiales')->insert(['nombre' => 'pino', 'precio_unitario' => 2]);   // duplicado más nuevo
        $this->ficha('MESA', [
            ['descripcion' => ' Pino ', 'cantidad' => 1, 'precio_unitario' => 1],
            ['descripcion' => 'CEDRO',  'cantidad' => 1, 'precio_unitario' => 1],
            ['descripcion' => 'PINO',   'cantidad' => 1, 'precio_unitario' => 1, 'es_mano_obra' => true],
        ]);

        (require database_path('migrations/2026_10_13_000002_items_de_ficha_con_material_id.php'))->up();

        $ids = DB::table('ficha_tecnica_items')->orderBy('id')->pluck('material_id')->all();
        $this->assertSame([$viejo, null, null], array_map(fn ($v) => $v === null ? null : (int) $v, $ids));
    }

    public function test_cambiar_la_unidad_del_material_la_pasa_a_los_items(): void
    {
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'TELA', 'unidad' => 'MTS', 'precio_unitario' => 10000]);
        $this->ficha('SOFA C', [['descripcion' => 'TELA', 'cantidad' => 1, 'unidad' => 'MTS', 'precio_unitario' => 10000]]);

        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$mat}", [
            'nombre' => 'TELA', 'unidad' => 'METROS', 'precio_unitario' => 10000,
        ])->assertJsonPath('productos_afectados', 1);

        $this->assertSame('METROS', DB::table('ficha_tecnica_items')->value('unidad'));
    }

    public function test_editar_ficha_agrega_y_quita_items_y_cambia_la_categoria(): void
    {
        DB::table('salarios_cargo')->insert(['cargo' => 'tapicero', 'tarifa_hora' => 10000]);
        $tarifa = DB::table('tarifas_proceso')->insertGetId(['proceso' => 'tapizado', 'cargo' => 'tapicero']);
        $id = $this->ficha('SOFA E', [
            ['seccion' => 'BASE', 'descripcion' => 'TELA',  'cantidad' => 2, 'precio_unitario' => 10000],
            ['seccion' => 'BASE', 'descripcion' => 'PINO',  'cantidad' => 1, 'precio_unitario' => 5000],
        ]);
        [$tela, $pino] = DB::table('ficha_tecnica_items')->orderBy('id')->pluck('id')->all();

        // Cambia la categoría: lo que indexa el cotizador cambió
        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->once()->with($id)->andReturnTrue();

        $r = $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/items", [
            'categoria' => 'sofas cama',
            'eliminar'  => [$pino],
            'items'     => [
                ['id' => $tela, 'cantidad' => 2, 'precio_unitario' => 10000],
                ['seccion' => 'BASE', 'descripcion' => 'ESPUMA', 'unidad' => 'LAMINA', 'cantidad' => 1, 'precio_unitario' => 30000],
                ['seccion' => 'BASE', 'descripcion' => 'tapizado', 'unidad' => 'horas', 'cantidad' => 3,
                 'precio_unitario' => 10000, 'es_mano_obra' => true, 'tarifa_proceso_id' => $tarifa],
            ],
        ]);

        $r->assertOk()->assertJsonCount(3, 'items');
        $ficha = DB::table('fichas_tecnicas')->find($id);
        $this->assertSame('SOFAS CAMA', $ficha->categoria);
        $this->assertEquals(50000, $ficha->costo_materiales);
        $this->assertEquals(30000, $ficha->costo_mano_obra);
        $this->assertNull(DB::table('ficha_tecnica_items')->find($pino));
        $mo = DB::table('ficha_tecnica_items')->where('es_mano_obra', true)->first();
        $this->assertEquals($tarifa, $mo->tarifa_proceso_id);
        $this->assertSame('BASE', $mo->seccion);
    }

    public function test_no_se_puede_dejar_una_ficha_sin_items_ni_tocar_items_de_otra(): void
    {
        $a = $this->ficha('SOFA F', [['descripcion' => 'TELA', 'cantidad' => 1, 'precio_unitario' => 1000]]);
        $b = $this->ficha('SOFA G', [['descripcion' => 'PINO', 'cantidad' => 1, 'precio_unitario' => 2000]]);
        $itemA = DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $a)->value('id');
        $itemB = DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $b)->value('id');

        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$a}/items", [
            'items' => [], 'eliminar' => [$itemA],
        ])->assertStatus(422);
        $this->assertNotNull(DB::table('ficha_tecnica_items')->find($itemA));

        // Un id de otra ficha en "eliminar" o en "items" no la toca
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$a}/items", [
            'items' => [['id' => $itemB, 'cantidad' => 9, 'precio_unitario' => 9]], 'eliminar' => [$itemB],
        ])->assertOk();
        $this->assertEquals(1, DB::table('ficha_tecnica_items')->find($itemB)->cantidad);
        $this->assertEquals(2000, DB::table('fichas_tecnicas')->where('id', $b)->value('costo_total'));
    }

    public function test_duplicar_ficha_copia_items_foto_y_tarifas(): void
    {
        $tarifa = DB::table('tarifas_proceso')->insertGetId(['proceso' => 'tapizado', 'cargo' => 'tapicero']);
        $id = $this->ficha('SOFA D', [
            ['descripcion' => 'TELA', 'cantidad' => 2, 'precio_unitario' => 10000],
            ['descripcion' => 'tapizado', 'cantidad' => 3, 'precio_unitario' => 8000, 'es_mano_obra' => true, 'tarifa_proceso_id' => $tarifa],
        ]);
        DB::table('fichas_tecnicas')->where('id', $id)->update(['foto_url' => 'https://x/sofa.jpg']);

        // atLeast: en pruebas la app se reutiliza y los "después de responder" de una petición
        // se vuelven a correr al terminar la siguiente
        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->atLeast()->once()->andReturnTrue();

        $r = $this->actingAs($this->ebanista)->postJson("/api/fichas-tecnicas/{$id}/duplicar", ['nombre' => 'sofa d xl']);

        $r->assertCreated()->assertJsonPath('nombre', 'SOFA D XL')->assertJsonCount(2, 'items');
        $copia = DB::table('fichas_tecnicas')->find($r->json('id'));
        $this->assertSame('https://x/sofa.jpg', $copia->foto_url);
        $this->assertEquals(44000, $copia->costo_total);
        $this->assertEquals($tarifa, DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $copia->id)->where('es_mano_obra', true)->value('tarifa_proceso_id'));
        // El original queda igual
        $this->assertSame(2, DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $id)->count());

        // Sin nombre, la copia se llama "COPIA DE …"
        $this->actingAs($this->ebanista)->postJson("/api/fichas-tecnicas/{$id}/duplicar")
            ->assertJsonPath('nombre', 'COPIA DE SOFA D');
    }

    public function test_eliminar_ficha_es_del_supervisor_con_acceso_a_costos(): void
    {
        $id = $this->ficha('SOFA H', [['descripcion' => 'TELA', 'cantidad' => 1, 'precio_unitario' => 1000]]);
        $sup = Usuario::forceCreate(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_costos' => true, 'created_at' => now()]);

        $this->actingAs($this->ebanista)->deleteJson("/api/fichas-tecnicas/{$id}")->assertForbidden();
        $this->assertSame(1, DB::table('fichas_tecnicas')->count());

        $this->actingAs($sup)->deleteJson("/api/fichas-tecnicas/{$id}")->assertOk();
        $this->assertSame(0, DB::table('fichas_tecnicas')->count());
    }

    public function test_vincular_una_ficha_a_su_producto_trae_el_precio_de_venta(): void
    {
        $id   = $this->ficha('SOFA M', [['descripcion' => 'TELA', 'cantidad' => 10, 'precio_unitario' => 50000]]);
        $prod = DB::table('productos')->insertGetId(['nombre' => 'Sofá Milán', 'precio_base' => 1500000]);

        // Al vincular toma el nombre del producto (2026-11-06)
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/producto", ['producto_id' => $prod])
            ->assertOk()->assertJsonPath('producto.nombre', 'Sofá Milán')->assertJsonPath('nombre', 'SOFÁ MILÁN');

        $r = $this->actingAs($this->ebanista)->getJson('/api/fichas-tecnicas?search=mil');
        $r->assertOk()->assertJsonPath('fichas.0.producto_id', $prod)
          ->assertJsonPath('fichas.0.producto_nombre', 'Sofá Milán');
        $this->assertEquals(1500000, $r->json('fichas.0.producto_precio'));
        $this->assertEquals(500000, $r->json('fichas.0.costo_total'));

        // Desvincular
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/producto", ['producto_id' => null])->assertOk();
        $this->assertNull(DB::table('fichas_tecnicas')->where('id', $id)->value('producto_id'));

        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/producto", ['producto_id' => 999])->assertStatus(422);
    }

    public function test_cambiar_el_precio_de_un_material_queda_en_el_historial(): void
    {
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'TELA', 'unidad' => 'METRO', 'precio_unitario' => 10000]);
        $this->ficha('SOFA H1', [['descripcion' => 'TELA', 'cantidad' => 3, 'precio_unitario' => 10000]]);
        $this->ficha('SOFA H2', [['descripcion' => 'TELA', 'cantidad' => 2, 'precio_unitario' => 10000]]);

        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$mat}", [
            'nombre' => 'TELA', 'unidad' => 'METRO', 'precio_unitario' => 12000,
        ])->assertOk();
        // Sin cambio de precio no se anota nada
        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$mat}", [
            'nombre' => 'TELA', 'unidad' => 'METRO', 'descripcion' => 'algodón', 'precio_unitario' => 12000,
        ])->assertOk();

        $r = $this->actingAs($this->ebanista)->getJson("/api/materiales/{$mat}/historial");

        $r->assertOk()->assertJsonCount(1)
          ->assertJsonPath('0.usuario', 'Ebanista')
          ->assertJsonPath('0.productos_afectados', 2);
        $this->assertEquals(10000, $r->json('0.precio_anterior'));
        $this->assertEquals(12000, $r->json('0.precio_nuevo'));
        // 5 metros en total × $2.000 de diferencia
        $this->assertEquals(10000, $r->json('0.impacto_total'));
    }

    public function test_los_costos_solo_los_ve_quien_tiene_acceso_a_costos(): void
    {
        $id  = $this->ficha('SOFA P', [['descripcion' => 'TELA', 'cantidad' => 1, 'precio_unitario' => 1000]]);
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'TELA', 'precio_unitario' => 1000]);

        $vendedor   = Usuario::forceCreate(['nombre' => 'Vendedor', 'rol' => 'vendedor', 'created_at' => now()]);
        $supervisor = Usuario::forceCreate(['nombre' => 'Sup', 'rol' => 'supervisor', 'created_at' => now()]);
        $conAcceso  = Usuario::forceCreate(['nombre' => 'Costos', 'rol' => 'vendedor', 'acceso_costos' => true, 'created_at' => now()]);

        foreach ([$vendedor, $supervisor] as $u) {
            $this->actingAs($u)->getJson('/api/fichas-tecnicas')->assertForbidden();
            $this->actingAs($u)->getJson("/api/fichas-tecnicas/{$id}")->assertForbidden();
            $this->actingAs($u)->getJson('/api/fichas-tecnicas/materiales-sugeridos?search=te')->assertForbidden();
            $this->actingAs($u)->getJson('/api/materiales')->assertForbidden();
            $this->actingAs($u)->getJson("/api/materiales/{$mat}/usos")->assertForbidden();
            $this->actingAs($u)->patchJson("/api/materiales/{$mat}", ['precio_unitario' => 1])->assertForbidden();
        }

        foreach ([$conAcceso, $this->ebanista] as $u) {
            $this->actingAs($u)->getJson('/api/fichas-tecnicas')->assertOk();
            $this->actingAs($u)->getJson('/api/materiales')->assertOk();
        }

        // Borrar materiales sigue siendo solo del supervisor, y además con acceso a costos
        $this->actingAs($conAcceso)->deleteJson("/api/materiales/{$mat}")->assertForbidden();
        $this->assertSame(1000.0, (float) DB::table('materiales')->where('id', $mat)->value('precio_unitario'));
    }

    public function test_ya_no_se_puede_reimportar_desde_la_app(): void
    {
        $this->ficha('SOFA R', [['descripcion' => 'TELA', 'cantidad' => 1, 'precio_unitario' => 1000]]);
        $sup = Usuario::forceCreate(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_costos' => true, 'created_at' => now()]);

        $this->actingAs($sup)->postJson('/api/fichas-tecnicas/reimportar')->assertStatus(405);
        $this->assertSame(1, DB::table('fichas_tecnicas')->count());
    }

    public function test_el_cotizador_acepta_los_cargos_creados_en_tarifas(): void
    {
        DB::table('salarios_cargo')->insert([['cargo' => 'carpintero'], ['cargo' => 'pintor']]);

        $sanear = new \ReflectionMethod(BomBuilder::class, 'sanear');
        $bom = $sanear->invoke(new BomBuilder, ['componentes' => [[
            'nombre'     => 'Mesa',
            'materiales' => [],
            'mano_obra'  => [
                ['cargo' => 'Pintor', 'horas' => 3],
                ['cargo' => 'carpintero', 'horas' => 5],
                ['cargo' => 'astronauta', 'horas' => 9],
            ],
        ]]]);

        $this->assertSame(['pintor', 'carpintero'], array_column($bom['componentes'][0]['mano_obra'], 'cargo'));
    }

    // ── Revisión del 2026-11-06 ──────────────────────────────────────────────

    public function test_guardar_una_ficha_abierta_desde_antes_no_devuelve_los_precios_viejos(): void
    {
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'TRIPLEX', 'unidad' => 'LAMINA', 'precio_unitario' => 100000]);
        DB::table('salarios_cargo')->insert(['cargo' => 'carpintero', 'tarifa_hora' => 15000]);
        $tarifa = DB::table('tarifas_proceso')->insertGetId(['proceso' => 'armado', 'cargo' => 'carpintero']);
        $id = $this->ficha('MESA', [
            ['descripcion' => 'TRIPLEX', 'cantidad' => 2, 'unidad' => 'LAMINA', 'precio_unitario' => 80000, 'material_id' => $mat],
            ['descripcion' => 'armado', 'cantidad' => 4, 'unidad' => 'horas', 'precio_unitario' => 12000,
             'es_mano_obra' => true, 'tarifa_proceso_id' => $tarifa],
        ]);
        [$itMat, $itMo] = DB::table('ficha_tecnica_items')->orderBy('id')->pluck('id')->all();

        // La pantalla manda lo que tenía al abrir: $80.000 la lámina y $12.000 la hora
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$id}/items", [
            'foto_url' => 'https://res.cloudinary.com/x/mesa.jpg',
            'items'    => [
                ['id' => $itMat, 'cantidad' => 2, 'precio_unitario' => 80000],
                ['id' => $itMo,  'cantidad' => 4, 'precio_unitario' => 12000],
            ],
        ])->assertOk();

        $this->assertEquals(100000, DB::table('ficha_tecnica_items')->where('id', $itMat)->value('precio_unitario'));
        $this->assertEquals(15000, DB::table('ficha_tecnica_items')->where('id', $itMo)->value('precio_unitario'));
        $this->assertEquals(2 * 100000 + 4 * 15000, DB::table('fichas_tecnicas')->value('costo_total'));
    }

    public function test_un_material_del_catalogo_toma_el_precio_del_catalogo_y_lo_libre_el_escrito(): void
    {
        $mat = DB::table('materiales')->insertGetId(['nombre' => 'ESPUMA', 'unidad' => 'LAMINA', 'precio_unitario' => 50000]);

        $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'PUFF', 'categoria' => 'BUTACOS',
            'items'  => [
                ['descripcion' => 'ESPUMA', 'cantidad' => 1, 'precio_unitario' => 1, 'material_id' => $mat],
                // Escrito a mano con el nombre de un material: también es ese material
                ['descripcion' => 'espuma', 'cantidad' => 1, 'precio_unitario' => 2],
                ['descripcion' => 'BOTONES ESPECIALES', 'cantidad' => 4, 'precio_unitario' => 3000],
            ],
        ])->assertCreated();

        $precios = DB::table('ficha_tecnica_items')->orderBy('id')->pluck('precio_unitario')->map(fn ($p) => (float) $p)->all();
        $this->assertSame([50000.0, 50000.0, 3000.0], $precios);
        $this->assertEquals(112000, DB::table('fichas_tecnicas')->value('costo_total'));
    }

    public function test_cambiar_la_unidad_del_material_actualiza_la_unidad_que_ve_el_cotizador(): void
    {
        Schema::table('materiales', fn (Blueprint $t) => $t->string('unidad_norm', 20)->nullable());
        $supervisor = Usuario::forceCreate(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_costos' => true, 'created_at' => now()]);

        $id = $this->actingAs($supervisor)->postJson('/api/materiales', [
            'nombre' => 'TRIPLEX 15', 'unidad' => 'LÁMINA DE 18 MM', 'precio_unitario' => 90000,
        ])->assertCreated()->json('id');
        $this->assertSame('lamina', DB::table('materiales')->where('id', $id)->value('unidad_norm'));

        $this->actingAs($supervisor)->patchJson("/api/materiales/{$id}", [
            'nombre' => 'TRIPLEX 15', 'unidad' => 'METROS', 'precio_unitario' => 90000,
        ])->assertOk();
        $this->assertSame('metro', DB::table('materiales')->where('id', $id)->value('unidad_norm'));

        $this->assertSame('unidad', \App\Support\UnidadMaterial::normalizar('und'));
        $this->assertSame('otro', \App\Support\UnidadMaterial::normalizar('CHAPILLA [CTMS]'));
        $this->assertNull(\App\Support\UnidadMaterial::normalizar(''));
    }

    public function test_no_se_puede_crear_ni_renombrar_un_material_con_un_nombre_que_ya_existe(): void
    {
        $supervisor = Usuario::forceCreate(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_costos' => true, 'created_at' => now()]);
        $tela  = DB::table('materiales')->insertGetId(['nombre' => 'TELA LINO', 'precio_unitario' => 30000]);
        $otra  = DB::table('materiales')->insertGetId(['nombre' => 'TELA PANA', 'precio_unitario' => 25000]);

        $this->actingAs($supervisor)->postJson('/api/materiales', ['nombre' => ' tela lino ', 'precio_unitario' => 1])
            ->assertStatus(422)->assertJsonPath('existente_id', $tela);

        $this->actingAs($supervisor)->patchJson("/api/materiales/{$otra}", ['nombre' => 'TELA LINO', 'precio_unitario' => 25000])
            ->assertStatus(422);

        // Sin renombrar se puede seguir editando, aunque ya hubiera dos repetidos de antes
        DB::table('materiales')->insert(['nombre' => 'TELA PANA', 'precio_unitario' => 25000]);
        $this->actingAs($supervisor)->patchJson("/api/materiales/{$otra}", ['nombre' => 'TELA PANA', 'precio_unitario' => 26000])
            ->assertOk();
        $this->assertSame(3, DB::table('materiales')->count());
    }

    /**
     * Mano de obra sin costo fijo (usuario, 2026-11-11): se escoge el tipo y el costo es el que
     * se escribe en la ficha. Tarifas no lo toca y renombrar el tipo solo cambia el nombre.
     */
    public function test_mano_de_obra_por_trabajo_guarda_el_costo_escrito_y_tarifas_no_lo_cambia(): void
    {
        Schema::create('tipos_mano_obra', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activo')->default(true); $t->unsignedInteger('orden')->default(0); $t->timestamps();
        });
        Schema::table('ficha_tecnica_items', fn (Blueprint $t) => $t->unsignedBigInteger('tipo_mano_obra_id')->nullable());
        \App\Http\Controllers\FichaTecnicaController::olvidarEsquema();

        $esq = $this->actingAs($this->ebanista)->postJson('/api/costos/tipos-mano-obra', ['nombre' => '  esqueletería '])
            ->assertCreated()->assertJsonPath('nombre', 'Esqueletería')->json('id');
        $this->actingAs($this->ebanista)->postJson('/api/costos/tipos-mano-obra', ['nombre' => 'ESQUELETERÍA'])->assertStatus(422);

        // Un oficio con incentivo, para ver que guardar Tarifas no toca el trabajo escrito a mano
        DB::table('salarios_cargo')->insert(['cargo' => 'tapicero', 'tarifa_hora' => 10000]);

        $ficha = $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'SOFA E', 'categoria' => 'SOFAS',
            'items'  => [
                ['descripcion' => 'Esqueletería', 'cantidad' => 1, 'unidad' => 'trabajo', 'precio_unitario' => 180000,
                 'es_mano_obra' => true, 'tipo_mano_obra_id' => $esq],
                ['descripcion' => 'TELA', 'cantidad' => 2, 'precio_unitario' => 20000],
            ],
        ])->assertCreated()->json('id');

        $item = DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $ficha)->where('es_mano_obra', true)->first();
        $this->assertEquals($esq, $item->tipo_mano_obra_id);
        $this->assertEquals(180000, $item->precio_unitario);
        $this->assertEquals(180000, DB::table('fichas_tecnicas')->where('id', $ficha)->value('costo_mano_obra'));
        $this->assertEquals(220000, DB::table('fichas_tecnicas')->where('id', $ficha)->value('costo_total'));

        // Editar el costo en la ficha se respeta (no hay precio "vigente" que lo pise)
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$ficha}/items", [
            'items' => [['id' => $item->id, 'cantidad' => 1, 'precio_unitario' => 200000]],
        ])->assertOk();
        $this->assertEquals(200000, DB::table('ficha_tecnica_items')->where('id', $item->id)->value('precio_unitario'));

        // Renombrar el tipo cambia el nombre del renglón, no su costo; la lista dice dónde se usa
        $this->actingAs($this->ebanista)->patchJson("/api/costos/tipos-mano-obra/{$esq}", ['nombre' => 'Esqueleto'])
            ->assertOk()->assertJsonPath('usos', 1);
        $item = DB::table('ficha_tecnica_items')->where('id', $item->id)->first();
        $this->assertSame('Esqueleto', $item->descripcion);
        $this->assertEquals(200000, $item->precio_unitario);

        // Usado no se borra: se archiva, y la ficha sigue igual
        $this->actingAs($this->ebanista)->deleteJson("/api/costos/tipos-mano-obra/{$esq}")->assertStatus(422);
        $this->actingAs($this->ebanista)->patchJson("/api/costos/tipos-mano-obra/{$esq}", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertEquals(220000 + 20000, DB::table('fichas_tecnicas')->where('id', $ficha)->value('costo_total'));

        // Copiar la ficha se lleva el tipo y el costo escrito
        $copia = $this->actingAs($this->ebanista)->postJson("/api/fichas-tecnicas/{$ficha}/duplicar")->assertCreated()->json('id');
        $mo = DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $copia)->where('es_mano_obra', true)->first();
        $this->assertEquals($esq, $mo->tipo_mano_obra_id);
        $this->assertEquals(200000, $mo->precio_unitario);

        // Uno sin usar sí se borra
        $otro = $this->actingAs($this->ebanista)->postJson('/api/costos/tipos-mano-obra', ['nombre' => 'Pintura'])->json('id');
        $this->actingAs($this->ebanista)->deleteJson("/api/costos/tipos-mano-obra/{$otro}")->assertOk();
        $this->assertSame(1, DB::table('tipos_mano_obra')->count());
    }

    public function test_el_color_del_material_se_guarda_y_sale_en_la_ficha_y_la_leyenda_se_edita(): void
    {
        Schema::table('materiales', fn (Blueprint $t) => $t->string('color', 20)->nullable());
        Schema::create('configuracion', function (Blueprint $t) {
            $t->id(); $t->string('clave')->unique(); $t->text('valor')->nullable(); $t->timestamps();
        });

        $id = $this->actingAs($this->ebanista)->postJson('/api/materiales', [
            'nombre' => 'TELA LINO', 'unidad' => 'METRO', 'precio_unitario' => 30000, 'color' => 'rojo',
        ])->assertCreated()->json('id');
        $this->assertSame('rojo', DB::table('materiales')->where('id', $id)->value('color'));

        // Un color que no existe no se acepta; null se lo quita
        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$id}", ['precio_unitario' => 30000, 'color' => 'fucsia'])->assertStatus(422);
        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$id}", ['precio_unitario' => 30000, 'color' => 'verde'])->assertOk();

        // En la ficha, el ítem trae el color de su material
        $ficha = $this->ficha('SOFA L', [['descripcion' => 'TELA LINO', 'cantidad' => 2, 'precio_unitario' => 30000, 'material_id' => $id]]);
        $this->actingAs($this->ebanista)->getJson("/api/fichas-tecnicas/{$ficha}")
            ->assertOk()->assertJsonPath('items.0.material.color', 'verde');

        // Leyenda: de fábrica, y editable sin tocar los materiales
        $this->actingAs($this->ebanista)->getJson('/api/materiales/colores')
            ->assertOk()->assertJsonPath('colores.rojo', 'Falta actualizar el precio');
        $this->actingAs($this->ebanista)->putJson('/api/materiales/colores', ['colores' => ['verde' => 'Precio de octubre', 'otro' => 'x']])
            ->assertOk()->assertJsonPath('colores.verde', 'Precio de octubre')->assertJsonMissingPath('colores.otro');
        $this->actingAs($this->ebanista)->getJson('/api/materiales/colores')->assertJsonPath('colores.verde', 'Precio de octubre');
        $this->assertSame('verde', DB::table('materiales')->where('id', $id)->value('color'));

        $this->actingAs($this->ebanista)->patchJson("/api/materiales/{$id}", ['precio_unitario' => 30000, 'color' => null])->assertOk();
        $this->assertNull(DB::table('materiales')->where('id', $id)->value('color'));
    }

    public function test_reemplazar_un_material_solo_en_las_fichas_escogidas(): void
    {
        $vieja = DB::table('materiales')->insertGetId(['nombre' => 'TELA VIEJA', 'unidad' => 'METRO', 'precio_unitario' => 10000]);
        $nueva = DB::table('materiales')->insertGetId(['nombre' => 'TELA NUEVA', 'unidad' => 'METRO', 'precio_unitario' => 25000]);

        $a = $this->ficha('SOFA A', [
            ['descripcion' => 'TELA VIEJA', 'cantidad' => 2, 'unidad' => 'METRO', 'precio_unitario' => 10000, 'material_id' => $vieja],
            ['descripcion' => 'ESPUMA', 'cantidad' => 1, 'precio_unitario' => 5000],
        ]);
        // Ítem viejo sin enlazar por id: se reconoce por el nombre
        $b = $this->ficha('SOFA B', [['descripcion' => 'tela vieja', 'cantidad' => 3, 'precio_unitario' => 10000]]);
        $c = $this->ficha('SOFA C', [['descripcion' => 'TELA VIEJA', 'cantidad' => 1, 'precio_unitario' => 10000, 'material_id' => $vieja]]);

        $this->actingAs($this->ebanista)->postJson("/api/materiales/{$vieja}/reemplazar", [
            'reemplazar_con_id' => $nueva, 'fichas' => [$a, $b],
        ])->assertOk()->assertJsonPath('fichas', 2)->assertJsonPath('items', 2);

        // A y B pasan a la tela nueva con su misma cantidad; C se queda con la vieja
        $this->assertEquals(55000, DB::table('fichas_tecnicas')->where('id', $a)->value('costo_total'));
        $this->assertEquals(75000, DB::table('fichas_tecnicas')->where('id', $b)->value('costo_total'));
        $this->assertEquals(10000, DB::table('fichas_tecnicas')->where('id', $c)->value('costo_total'));
        $itemB = DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $b)->first();
        $this->assertEquals($nueva, $itemB->material_id);
        $this->assertSame('TELA NUEVA', $itemB->descripcion);
        $this->assertEquals(3, $itemB->cantidad);
        // La espuma de A no se toca y el material viejo sigue en el catálogo
        $this->assertSame(1, DB::table('ficha_tecnica_items')->where('descripcion', 'ESPUMA')->count());
        $this->assertTrue(DB::table('materiales')->where('id', $vieja)->exists());

        // Por el mismo, o con fichas que no lo usan: no se hace nada
        $this->actingAs($this->ebanista)->postJson("/api/materiales/{$vieja}/reemplazar", [
            'reemplazar_con_id' => $vieja, 'fichas' => [$c],
        ])->assertStatus(422);
        $this->actingAs($this->ebanista)->postJson("/api/materiales/{$vieja}/reemplazar", [
            'reemplazar_con_id' => $nueva, 'fichas' => [$a],
        ])->assertStatus(422);
        $this->assertEquals(10000, DB::table('fichas_tecnicas')->where('id', $c)->value('costo_total'));
    }
}
