<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\Costos\FichaRetriever;
use App\Services\Costos\RecetasDeProductos;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Costos conectado con Inventario (2026-11-06): cada producto activo aparece en Costos
 * con su receta o "en espera", la receta nace del producto (nombre, categoría, foto) y una
 * versión con precio propio (la cama de 1.40) puede tener la suya. Una sola receta por
 * producto y versión. Las fichas del Excel sin producto salen "sueltas" con sugerencia.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CostosConectadoConInventarioTest extends TestCase
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
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('precio_base', 12, 2)->default(0); $t->boolean('activo')->default(true);
        });
        Schema::create('tipos_variante', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('afecta_precio')->default(true); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id'); $t->string('nombre'); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_variante_id');
            $t->unsignedBigInteger('opcion_id'); $t->decimal('precio_adicional', 12, 2)->default(0);
            $t->integer('piezas_por_juego')->nullable(); $t->timestamps();
        });
        Schema::create('fichas_tecnicas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->text('descripcion')->nullable(); $t->string('categoria');
            $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('variante_config_id')->nullable();
            $t->decimal('costo_materiales', 12, 2)->default(0); $t->decimal('costo_mano_obra', 12, 2)->default(0);
            $t->decimal('costo_total', 12, 2)->default(0); $t->string('ruta_excel')->nullable();
            $t->string('foto_url', 500)->nullable(); $t->longText('embedding')->nullable();
            $t->timestamp('embedding_at')->nullable(); $t->timestamps();
        });
        Schema::create('ficha_tecnica_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('ficha_tecnica_id'); $t->string('seccion')->nullable();
            $t->string('descripcion'); $t->decimal('cantidad', 12, 4)->default(0); $t->string('unidad')->nullable();
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('es_mano_obra')->default(false); $t->unsignedBigInteger('tarifa_proceso_id')->nullable();
            $t->unsignedBigInteger('material_id')->nullable(); $t->integer('orden')->default(0); $t->timestamps();
        });
        Schema::create('materiales', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('descripcion')->nullable(); $t->string('unidad')->nullable();
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('salarios_cargo', function (Blueprint $t) {
            $t->id(); $t->string('cargo'); $t->decimal('tarifa_hora', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('productos_no_fabricados', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id')->unique(); $t->string('origen', 20);
            $t->string('nota')->nullable(); $t->unsignedBigInteger('marcado_por_id')->nullable(); $t->timestamps();
        });
        Schema::create('tarifas_proceso', function (Blueprint $t) {
            $t->id(); $t->string('proceso'); $t->string('cargo')->nullable(); $t->timestamps();
        });

        $this->ebanista = Usuario::forceCreate(['nombre' => 'Ebanista', 'rol' => 'ebanista', 'created_at' => now()]);
        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->andReturnTrue()->byDefault();
    }

    /** Cama Bali con medidas 1.40 ($2.000.000) y 1.60 ($2.400.000). Devuelve [producto, c140, c160]. */
    private function camaConMedidas(): array
    {
        $p    = DB::table('productos')->insertGetId(['nombre' => 'Cama Bali', 'categoria' => 'Camas', 'precio_base' => 1800000, 'foto_url' => 'https://res.cloudinary.com/x/bali.jpg']);
        $tipo = DB::table('tipos_variante')->insertGetId(['nombre' => 'Medida', 'afecta_precio' => true]);
        $o140 = DB::table('tipo_variante_opciones')->insertGetId(['tipo_variante_id' => $tipo, 'nombre' => '1.40']);
        $o160 = DB::table('tipo_variante_opciones')->insertGetId(['tipo_variante_id' => $tipo, 'nombre' => '1.60']);
        $c140 = DB::table('producto_variante_configs')->insertGetId(['producto_id' => $p, 'tipo_variante_id' => $tipo, 'opcion_id' => $o140, 'precio_adicional' => 2000000]);
        $c160 = DB::table('producto_variante_configs')->insertGetId(['producto_id' => $p, 'tipo_variante_id' => $tipo, 'opcion_id' => $o160, 'precio_adicional' => 2400000]);

        return [$p, $c140, $c160];
    }

    private function ficha(array $campos, float $costo = 500000): int
    {
        $id = DB::table('fichas_tecnicas')->insertGetId($campos + ['categoria' => 'CAMAS', 'costo_total' => $costo, 'costo_materiales' => $costo]);
        DB::table('ficha_tecnica_items')->insert(['ficha_tecnica_id' => $id, 'descripcion' => 'MADERA', 'cantidad' => 1,
            'precio_unitario' => $costo, 'subtotal' => $costo, 'material_id' => 99]);
        return $id;
    }

    private function listado(): array
    {
        return $this->actingAs($this->ebanista)->getJson('/api/fichas-tecnicas/productos')->assertOk()->json();
    }

    public function test_todo_producto_activo_aparece_en_costos_con_su_receta_o_en_espera(): void
    {
        $sofa   = DB::table('productos')->insertGetId(['nombre' => 'Sofá Roma', 'categoria' => 'Sofás', 'precio_base' => 3000000]);
        $mesa   = DB::table('productos')->insertGetId(['nombre' => 'Mesa Toledo', 'categoria' => 'Mesas', 'precio_base' => 900000]);
        DB::table('productos')->insert(['nombre' => 'Apagado', 'categoria' => 'Mesas', 'activo' => false]);
        $this->ficha(['nombre' => 'SOFA ROMA', 'producto_id' => $sofa], 1200000);

        $r = $this->listado();

        $this->assertSame(2, $r['resumen']['productos']);   // el apagado no sale
        $this->assertSame(1, $r['resumen']['sin_receta']);
        $this->assertSame(1, $r['resumen']['lista']);
        $porId = collect($r['productos'])->keyBy('id');
        $this->assertSame('lista', $porId[$sofa]['estado']);
        $this->assertSame('sin_receta', $porId[$mesa]['estado']);
        $this->assertEquals(1200000, $porId[$sofa]['fichas'][0]['costo_total']);
    }

    /**
     * Con medidas, cada una lleva su receta (usuario, 2026-11-06: "no todas cuestan lo
     * mismo"). La general no cubre a ninguna: con solo la general, el producto sigue en espera.
     */
    public function test_con_medidas_cada_una_necesita_su_receta_y_la_general_no_cuenta(): void
    {
        [$p, $c140, $c160] = $this->camaConMedidas();
        $cama = fn () => collect($this->listado()['productos'])->firstWhere('id', $p);

        $this->ficha(['nombre' => 'CAMA BALI', 'producto_id' => $p], 700000);
        $this->assertSame('sin_receta', $cama()['estado']);
        $this->assertSame(2, $cama()['faltan']);

        $this->ficha(['nombre' => 'CAMA BALI 1.60', 'producto_id' => $p, 'variante_config_id' => $c160], 900000);
        $c = $cama();
        $this->assertSame('incompleta', $c['estado']);
        $this->assertSame(1, $c['faltan']);
        $vers = collect($c['versiones'])->keyBy('config_id');
        $this->assertSame([], $vers[$c140]['fichas']);
        $this->assertEquals(2000000, $vers[$c140]['precio']);
        $this->assertEquals(900000, $vers[$c160]['fichas'][0]['costo_total']);
        $this->assertEquals(2400000, $vers[$c160]['fichas'][0]['precio_venta']);
        $this->assertCount(1, $c['fichas']);   // la general sigue ahí, de plantilla

        $this->ficha(['nombre' => 'CAMA BALI 1.40', 'producto_id' => $p, 'variante_config_id' => $c140], 750000);
        $this->assertSame('lista', $cama()['estado']);
        $this->assertSame(1, $this->listado()['resumen']['lista']);
    }

    public function test_la_receta_general_de_un_producto_con_medidas_no_tiene_margen(): void
    {
        [$p] = $this->camaConMedidas();
        $f = $this->ficha(['nombre' => 'CAMA BALI', 'producto_id' => $p], 700000);

        $lista = $this->actingAs($this->ebanista)->getJson('/api/fichas-tecnicas')->assertOk();
        $this->assertNull($lista->json('fichas.0.producto_precio'));

        $this->actingAs($this->ebanista)->getJson("/api/fichas-tecnicas/{$f}")->assertOk()
            ->assertJsonPath('producto_con_medidas', true);

        // Un producto sin medidas sí se compara con su precio
        $sofa = DB::table('productos')->insertGetId(['nombre' => 'Sofá', 'categoria' => 'Sofás', 'precio_base' => 3000000]);
        $g = $this->ficha(['nombre' => 'SOFA', 'producto_id' => $sofa], 1000000);
        $this->actingAs($this->ebanista)->getJson("/api/fichas-tecnicas/{$g}")->assertOk()
            ->assertJsonPath('producto_con_medidas', false);
    }

    public function test_crear_la_receta_desde_el_producto_la_enlaza_y_no_deja_dos(): void
    {
        [$p, $c140] = $this->camaConMedidas();

        $r = $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'cama bali 1.40', 'categoria' => 'camas', 'producto_id' => $p, 'variante_config_id' => $c140,
            'items'  => [['descripcion' => 'TABLA', 'cantidad' => 2, 'precio_unitario' => 50000]],
        ])->assertCreated();

        $this->assertSame($p, $r->json('producto_id'));
        $this->assertSame($c140, $r->json('variante_config_id'));
        $this->assertSame('CAMA BALI 1.40', $r->json('nombre'));
        $this->assertSame('1.40', $r->json('variante_config.opcion.nombre'));
        $this->assertNull($r->json('foto_url'));   // se ve la del producto, no se copia

        // Otra receta para la misma versión: no
        $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'OTRA', 'categoria' => 'CAMAS', 'producto_id' => $p, 'variante_config_id' => $c140,
            'items'  => [['descripcion' => 'TABLA', 'cantidad' => 1, 'precio_unitario' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('producto_id');

        // La del producto (sin versión) sí, es otro lugar
        $this->actingAs($this->ebanista)->postJson('/api/fichas-tecnicas', [
            'nombre' => 'CAMA BALI', 'categoria' => 'CAMAS', 'producto_id' => $p,
            'items'  => [['descripcion' => 'TABLA', 'cantidad' => 1, 'precio_unitario' => 1]],
        ])->assertCreated();
    }

    public function test_una_version_de_otro_producto_no_se_acepta(): void
    {
        [, $c140] = $this->camaConMedidas();
        $otro = DB::table('productos')->insertGetId(['nombre' => 'Nochero', 'categoria' => 'Mesas']);
        $f    = $this->ficha(['nombre' => 'NOCHERO']);

        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$f}/producto", [
            'producto_id' => $otro, 'variante_config_id' => $c140,
        ])->assertStatus(422)->assertJsonValidationErrors('variante_config_id');
    }

    public function test_copiar_la_receta_del_producto_para_una_version(): void
    {
        [$p, , $c160] = $this->camaConMedidas();
        $base = $this->ficha(['nombre' => 'CAMA BALI', 'producto_id' => $p], 700000);

        $r = $this->actingAs($this->ebanista)->postJson("/api/fichas-tecnicas/{$base}/duplicar", [
            'nombre' => 'CAMA BALI 1.60', 'producto_id' => $p, 'variante_config_id' => $c160,
        ])->assertCreated();

        $this->assertSame($c160, $r->json('variante_config_id'));
        $this->assertCount(1, $r->json('items'));
        // Copiar sin decir de qué es: queda suelta, como antes
        $this->actingAs($this->ebanista)->postJson("/api/fichas-tecnicas/{$base}/duplicar")->assertCreated()
            ->assertJsonPath('producto_id', null);
    }

    public function test_las_fichas_sueltas_traen_la_sugerencia_del_producto_y_la_medida(): void
    {
        [$p, $c140] = $this->camaConMedidas();
        DB::table('productos')->insert(['nombre' => 'Sofá Roma', 'categoria' => 'Sofás', 'precio_base' => 1]);
        $suelta = $this->ficha(['nombre' => 'CAMA BALI DE 1,40 EN PINO']);
        $nada   = $this->ficha(['nombre' => 'ZAPATERO XL', 'categoria' => 'ZAPATEROS']);

        $sueltas = collect($this->listado()['sueltas'])->keyBy('id');

        $s = $sueltas[$suelta]['sugerencias'][0];
        $this->assertSame($p, $s['producto_id']);
        $this->assertSame($c140, $s['config_id']);
        $this->assertSame('1.40', $s['version']);
        $this->assertSame([], $sueltas[$nada]['sugerencias']);

        // Al enlazarla por la sugerencia deja de ser suelta y la medida queda con receta
        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$suelta}/producto", [
            'producto_id' => $p, 'variante_config_id' => $c140,
        ])->assertOk();
        $r = $this->listado();
        $this->assertFalse(collect($r['sueltas'])->contains('id', $suelta));
    }

    public function test_el_margen_de_la_receta_de_una_version_usa_el_precio_de_la_version(): void
    {
        [$p, , $c160] = $this->camaConMedidas();
        $this->ficha(['nombre' => 'CAMA BALI 1.60', 'producto_id' => $p, 'variante_config_id' => $c160], 900000);

        $f = $this->actingAs($this->ebanista)->getJson('/api/fichas-tecnicas')->assertOk()->json('fichas.0');

        $this->assertEquals(2400000, $f['producto_precio']);
        $this->assertSame('1.60', $f['version_nombre']);
    }

    public function test_el_cotizador_usa_la_receta_enlazada_al_producto_y_no_la_que_se_llame_parecido(): void
    {
        [$p, , $c160] = $this->camaConMedidas();
        $this->ficha(['nombre' => 'CAMA BALI'], 100);   // suelta, se llama igual
        $v160 = $this->ficha(['nombre' => 'CAMA 1.60 PINO', 'producto_id' => $p, 'variante_config_id' => $c160], 900000);
        $base = $this->ficha(['nombre' => 'CAMA PINO MACIZO', 'producto_id' => $p], 700000);

        $agente = app(\App\Services\AgentService::class);
        $receta = (new \ReflectionMethod($agente, 'recetaDelProducto'))->invoke($agente, $p);
        $this->assertSame($base, $receta);   // la del producto antes que la de una versión

        $datos = (new \ReflectionMethod($agente, 'handleObtenerFichaTecnica'))
            ->invoke($agente, ['nombre_producto' => 'Cama Bali', 'ficha_id' => $receta]);
        $this->assertSame($base, $datos['ficha']->id);

        DB::table('fichas_tecnicas')->where('id', $base)->delete();
        $this->assertSame($v160, (new \ReflectionMethod($agente, 'recetaDelProducto'))->invoke($agente, $p));
    }

    // ── Vincular: nombre, descripción y foto del producto ───────────────────

    private function vincular(int $ficha, ?int $producto, ?int $config = null)
    {
        return $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$ficha}/producto", [
            'producto_id' => $producto, 'variante_config_id' => $config,
        ])->assertOk();
    }

    public function test_al_vincular_toma_el_nombre_y_la_foto_del_producto_y_el_nombre_viejo_queda_de_descripcion(): void
    {
        [$p] = $this->camaConMedidas();
        $f = $this->ficha(['nombre' => 'CAMA MACARENA DE 1.40 PINO', 'foto_url' => 'https://res.cloudinary.com/x/vieja.jpg']);

        $this->mock(FichaRetriever::class)->shouldReceive('indexarFicha')->once()->with($f)->andReturnTrue();

        $r = $this->vincular($f, $p);

        $r->assertJsonPath('nombre', 'CAMA BALI')
          ->assertJsonPath('descripcion', 'CAMA MACARENA DE 1.40 PINO')
          ->assertJsonPath('foto_url', null)                                    // se ve la del producto
          ->assertJsonPath('producto.foto_url', 'https://res.cloudinary.com/x/bali.jpg');
    }

    public function test_vincular_a_una_version_le_pone_la_medida_al_nombre_y_guarda_la_descripcion_original(): void
    {
        [$p, $c140] = $this->camaConMedidas();
        $otro = DB::table('productos')->insertGetId(['nombre' => 'Cama Suiza', 'categoria' => 'Camas']);
        $f = $this->ficha(['nombre' => 'CAMA DEL EXCEL 140']);

        $this->vincular($f, $otro);
        $this->vincular($f, $p, $c140)
            ->assertJsonPath('nombre', 'CAMA BALI 1.40')
            // La descripción sigue siendo el nombre original, no el del producto anterior
            ->assertJsonPath('descripcion', 'CAMA DEL EXCEL 140');
    }

    public function test_si_el_producto_no_tiene_foto_la_ficha_se_queda_con_la_suya_y_al_desvincular_no_queda_sin_foto(): void
    {
        $sinFoto = DB::table('productos')->insertGetId(['nombre' => 'Puff', 'categoria' => 'Butacos']);
        $f = $this->ficha(['nombre' => 'PUFF REDONDO', 'foto_url' => 'https://res.cloudinary.com/x/puff.jpg']);
        $this->vincular($f, $sinFoto)->assertJsonPath('foto_url', 'https://res.cloudinary.com/x/puff.jpg');

        [$p] = $this->camaConMedidas();
        $g = $this->ficha(['nombre' => 'CAMA X']);
        $this->vincular($g, $p)->assertJsonPath('foto_url', null);

        // Al quitar el producto, se queda con la foto que veía y con el nombre
        $this->vincular($g, null)
            ->assertJsonPath('foto_url', 'https://res.cloudinary.com/x/bali.jpg')
            ->assertJsonPath('nombre', 'CAMA BALI')
            ->assertJsonPath('producto_id', null);
    }

    public function test_renombrar_el_producto_en_inventario_renombra_sus_recetas(): void
    {
        [$p, $c140] = $this->camaConMedidas();
        $base = $this->ficha(['nombre' => 'CAMA BALI', 'producto_id' => $p]);
        $v140 = $this->ficha(['nombre' => 'CAMA BALI 1.40', 'producto_id' => $p, 'variante_config_id' => $c140]);

        \App\Models\Producto::find($p)->update(['nombre' => 'Cama Bali Premium']);

        $this->assertSame('CAMA BALI PREMIUM', DB::table('fichas_tecnicas')->where('id', $base)->value('nombre'));
        $this->assertSame('CAMA BALI PREMIUM 1.40', DB::table('fichas_tecnicas')->where('id', $v140)->value('nombre'));
    }

    public function test_vinculada_el_nombre_no_se_cambia_en_la_ficha_pero_la_descripcion_si(): void
    {
        [$p] = $this->camaConMedidas();
        $f = $this->ficha(['nombre' => 'CAMA BALI', 'producto_id' => $p]);
        $item = DB::table('ficha_tecnica_items')->where('ficha_tecnica_id', $f)->value('id');

        $this->actingAs($this->ebanista)->patchJson("/api/fichas-tecnicas/{$f}/items", [
            'nombre' => 'OTRO NOMBRE', 'descripcion' => 'Pino macizo, cabecero tapizado',
            'items'  => [['id' => $item, 'cantidad' => 1, 'precio_unitario' => 500000]],
        ])->assertOk()
          ->assertJsonPath('nombre', 'CAMA BALI')
          ->assertJsonPath('descripcion', 'Pino macizo, cabecero tapizado');
    }

    // ── Importados y comprados: no se fabrican ───────────────────────────────

    private function origen(int $producto, ?string $origen, ?string $nota = null)
    {
        return $this->actingAs($this->ebanista)->putJson("/api/fichas-tecnicas/productos/{$producto}/origen", [
            'origen' => $origen, 'nota' => $nota,
        ]);
    }

    public function test_un_importado_sale_de_espera_y_se_cuenta_aparte(): void
    {
        $reloj = DB::table('productos')->insertGetId(['nombre' => 'Reloj Angel', 'categoria' => 'Relojes', 'precio_base' => 250000]);
        DB::table('productos')->insert(['nombre' => 'Mesa Toledo', 'categoria' => 'Mesas', 'precio_base' => 900000]);

        $this->origen($reloj, 'importado', 'Viene de China')->assertOk();

        $r = $this->listado();
        $this->assertSame(1, $r['resumen']['sin_receta']);      // solo la mesa
        $this->assertSame(1, $r['resumen']['no_fabricados']);
        $p = collect($r['productos'])->firstWhere('id', $reloj);
        $this->assertSame('no_fabricado', $p['estado']);
        $this->assertSame(['origen' => 'importado', 'nota' => 'Viene de China'], $p['no_fabricado']);
    }

    public function test_se_puede_cambiar_a_comprado_y_volver_a_fabricado(): void
    {
        $puff = DB::table('productos')->insertGetId(['nombre' => 'Puff', 'categoria' => 'Butacos']);

        $this->origen($puff, 'importado')->assertOk();
        $this->origen($puff, 'comprado', 'Proveedor de Medellín')->assertOk();
        $this->assertSame(1, DB::table('productos_no_fabricados')->count());
        $this->assertSame('comprado', DB::table('productos_no_fabricados')->value('origen'));
        $this->assertSame($this->ebanista->id, (int) DB::table('productos_no_fabricados')->value('marcado_por_id'));

        $this->origen($puff, null)->assertOk();
        $this->assertSame('sin_receta', collect($this->listado()['productos'])->firstWhere('id', $puff)['estado']);
    }

    public function test_solo_se_aceptan_importado_o_comprado_y_el_producto_tiene_que_existir(): void
    {
        $puff = DB::table('productos')->insertGetId(['nombre' => 'Puff', 'categoria' => 'Butacos']);

        $this->origen($puff, 'regalado')->assertStatus(422);
        $this->origen(9999, 'importado')->assertNotFound();

        $vendedor = Usuario::forceCreate(['nombre' => 'Vende', 'rol' => 'vendedor', 'created_at' => now()]);
        $this->actingAs($vendedor)->putJson("/api/fichas-tecnicas/productos/{$puff}/origen", ['origen' => 'importado'])
            ->assertForbidden();
    }

    public function test_una_ficha_suelta_no_se_le_sugiere_a_un_importado(): void
    {
        [$p] = $this->camaConMedidas();
        $this->ficha(['nombre' => 'CAMA BALI PINO']);
        $this->origen($p, 'importado')->assertOk();

        $this->assertSame([], $this->listado()['sueltas'][0]['sugerencias']);
    }

    public function test_la_categoria_de_la_receta_usa_la_del_cotizador_si_es_la_misma(): void
    {
        $this->ficha(['nombre' => 'SOFA X', 'categoria' => 'SOFAS']);
        $r = app(RecetasDeProductos::class);

        $this->assertSame('SOFAS', $r->categoriaParaFicha('Sofás'));
        $this->assertSame('SOFAS', $r->categoriaParaFicha('sofa'));
        $this->assertSame('RELOJES', $r->categoriaParaFicha('Relojes'));
        $this->assertSame('1.40', $r->medida('140x190'));
        $this->assertSame('1.40', $r->medida('Cama 1,40'));
        $this->assertNull($r->medida('Sofá Roma'));
    }
}
