<?php

namespace Tests\Feature;

use App\Models\EstimadoIa;
use App\Models\Usuario;
use App\Services\AgentService;
use App\Services\Costos\FewShotProvider;
use App\Services\Costos\SanityChecker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * Cuatro fallas del módulo de Costos (revisión del 2026-11-06):
 *
 * 1. Responder una consulta de costo dejaba el total de la orden en cantidad × precio a
 *    secas: una orden con descuento le subía el total al cliente.
 * 2. El aprendizaje del cotizador casi nunca enlazaba la corrección del ebanista con el
 *    estimado de la IA, y con "Precio manual" (un precio de VENTA) lo guardaba como costo.
 * 3. Restauración de varias piezas: la IA daba el total de las N y la pantalla lo ponía
 *    como precio unitario (2 sillas se cobraban como 4).
 * 4. Un cambio de tela sobre un producto del catálogo salía casi siempre "requiere
 *    revisión": se comparaba el costo del cambio contra la ficha del mueble completo.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CotizadorCostosArreglosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_costos')->default(false); $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->json('notificaciones_apagadas')->nullable(); $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('precio_base', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('estado'); $t->string('canal')->nullable();
            $t->integer('numero')->nullable(); $t->string('serie')->nullable();
            $t->string('contacto_nombre')->nullable(); $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->boolean('es_personalizado')->default(false); $t->boolean('es_restauracion')->default(false);
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('consultas_costo', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('asignado_a_id');
            $t->unsignedBigInteger('solicitado_por_id'); $t->string('estado')->default('pendiente');
            $t->text('notas_adicionales')->nullable(); $t->timestamp('respondido_at')->nullable(); $t->timestamps();
        });
        Schema::create('consulta_costo_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('consulta_id'); $t->unsignedBigInteger('orden_item_id');
            $t->decimal('precio_base', 15, 2)->nullable(); $t->integer('margen_ganancia_pct')->nullable();
            $t->decimal('precio_final', 15, 2)->nullable(); $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('consulta_costo_desglose', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('consulta_item_id'); $t->string('tipo'); $t->string('nombre');
            $t->decimal('cantidad', 12, 3); $t->decimal('precio_unitario', 15, 2); $t->decimal('subtotal', 15, 2); $t->timestamps();
        });
        Schema::create('estimados_ia', function (Blueprint $t) {
            $t->id(); $t->string('input_texto', 500)->nullable(); $t->string('categoria')->nullable();
            $t->string('input_hash')->nullable(); $t->json('medidas')->nullable(); $t->json('bom_json')->nullable();
            $t->integer('precio_ia')->default(0); $t->boolean('requirio_revision')->default(false);
            $t->json('embedding')->nullable(); $t->integer('precio_humano')->nullable(); $t->decimal('error_pct', 8, 2)->nullable();
            $t->unsignedBigInteger('orden_item_id')->nullable(); $t->unsignedBigInteger('corregido_por_id')->nullable();
            $t->timestamp('corregido_at')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('salarios_cargo', function (Blueprint $t) {
            $t->id(); $t->string('cargo'); $t->string('descripcion')->nullable(); $t->decimal('tarifa_hora', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('tarifas_proceso', function (Blueprint $t) {
            $t->id(); $t->string('proceso'); $t->string('descripcion')->nullable(); $t->string('unidad')->nullable();
            $t->decimal('tarifa', 12, 2)->default(0); $t->string('cargo')->nullable(); $t->timestamps();
        });

        $this->completarEsquemaDeEntregas();
    }

    private function persona(string $rol, bool $costos = false): Usuario
    {
        return Usuario::create(['nombre' => ucfirst($rol), 'email' => $rol . rand() . '@d.com', 'password' => 'x',
                                'rol' => $rol, 'acceso_costos' => $costos]);
    }

    /**
     * Cotización con un ítem de catálogo de $1.000.000, un personalizado sin precio y
     * $100.000 de descuento. Devuelve [orden_id, consulta_item_id, ebanista, vendedora].
     */
    private function cotizacionConConsulta(array $personalizado = []): array
    {
        $vendedora = $this->persona('vendedor');
        $ebanista  = $this->persona('ebanista', true);

        $ordenId = DB::table('ordenes')->insertGetId([
            'vendedor_id' => $vendedora->id, 'estado' => 'cotizacion', 'valor_total' => 900000,
            'descuento_total' => 100000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert([
            'orden_id' => $ordenId, 'nombre_custom' => 'Mesa de noche', 'cantidad' => 1, 'precio_unitario' => 1000000,
        ]);
        $itemId = DB::table('orden_items')->insertGetId($personalizado + [
            'orden_id' => $ordenId, 'nombre_custom' => 'Cama Bali especial', 'categoria_custom' => 'Camas',
            'es_personalizado' => true, 'cantidad' => 1, 'precio_unitario' => 0,
        ]);
        $consultaId = DB::table('consultas_costo')->insertGetId([
            'orden_id' => $ordenId, 'asignado_a_id' => $ebanista->id, 'solicitado_por_id' => $vendedora->id,
            'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ciId = DB::table('consulta_costo_items')->insertGetId([
            'consulta_id' => $consultaId, 'orden_item_id' => $itemId, 'estado' => 'pendiente',
        ]);

        return [$ordenId, $consultaId, $ciId, $ebanista, $vendedora];
    }

    private function responderConDesglose(int $consultaId, int $ciId, Usuario $ebanista, int $costo, int $margen): void
    {
        $this->actingAs($ebanista)->putJson("/api/consultas-costo/{$consultaId}/items/{$ciId}", [
            'margen_ganancia_pct' => $margen,
            'desglose' => [['tipo' => 'material', 'nombre' => 'Madera', 'cantidad' => 1, 'precio_unitario' => $costo]],
        ])->assertOk();
        $this->actingAs($ebanista)->postJson("/api/consultas-costo/{$consultaId}/enviar")->assertOk();
    }

    // ── 1. El total respeta el descuento ─────────────────────────────────────

    public function test_responder_la_consulta_respeta_el_descuento_de_la_orden(): void
    {
        [$ordenId, $consultaId, $ciId, $ebanista] = $this->cotizacionConConsulta();

        $this->responderConDesglose($consultaId, $ciId, $ebanista, 500000, 100); // venta $1.000.000

        // $1.000.000 + $1.000.000 − $100.000 de descuento. Antes quedaba en $2.000.000.
        $this->assertEquals(1900000, (float) DB::table('ordenes')->where('id', $ordenId)->value('valor_total'));
    }

    public function test_ajustar_el_precio_de_la_consulta_respeta_el_descuento(): void
    {
        [$ordenId, $consultaId, $ciId, $ebanista, $vendedora] = $this->cotizacionConConsulta();
        $this->responderConDesglose($consultaId, $ciId, $ebanista, 500000, 100);

        $this->actingAs($vendedora)->patchJson("/api/consultas-costo/{$consultaId}/ajustar-precio", [
            'items' => [['consulta_item_id' => $ciId, 'precio_ajustado' => 800000]],
        ])->assertOk();

        $this->assertEquals(1700000, (float) DB::table('ordenes')->where('id', $ordenId)->value('valor_total'));
    }

    // ── 2. El aprendizaje enlaza bien y no aprende precios de venta ─────────

    private function estimadoComoElCotizador(string $nombre, string $especificaciones, string $categoria, int $precioIa): int
    {
        OpenAI::fake([]); // sin embeddings: registrar() sigue (es best-effort)

        return app(FewShotProvider::class)->registrar(
            trim("{$nombre} {$especificaciones}"), $categoria, ['componentes' => []], $precioIa, false, [], $nombre,
        );
    }

    public function test_la_correccion_del_ebanista_se_enlaza_con_el_estimado_aunque_traiga_especificaciones(): void
    {
        [, $consultaId, $ciId, $ebanista] = $this->cotizacionConConsulta();
        // La pantalla manda nombre + especificaciones y su propia categoría ("camas");
        // la orden guarda el nombre y la categoría que escribió el vendedor ("Camas").
        $estimadoId = $this->estimadoComoElCotizador('Cama Bali especial', 'Material / tela: lino. Largo: 190 cm', 'camas', 400000);

        $this->responderConDesglose($consultaId, $ciId, $ebanista, 500000, 100);

        $e = EstimadoIa::find($estimadoId);
        $this->assertSame(500000, (int) $e->precio_humano); // el COSTO, no los $1.000.000 de venta
        $this->assertEquals(-20.0, (float) $e->error_pct);
    }

    public function test_un_producto_del_catalogo_se_enlaza_por_el_nombre_del_producto(): void
    {
        $productoId = DB::table('productos')->insertGetId(['nombre' => 'Sofá Roma', 'precio_base' => 2000000]);
        [, $consultaId, $ciId, $ebanista] = $this->cotizacionConConsulta([
            'producto_id' => $productoId, 'nombre_custom' => null, 'categoria_custom' => null,
        ]);
        $estimadoId = $this->estimadoComoElCotizador('Sofá Roma', 'Material / tela: cuero', 'sofas', 900000);

        $this->responderConDesglose($consultaId, $ciId, $ebanista, 1000000, 80);

        $this->assertSame(1000000, (int) EstimadoIa::find($estimadoId)->precio_humano);
    }

    public function test_el_precio_manual_no_se_toma_como_costo(): void
    {
        [, $consultaId, $ciId, $ebanista] = $this->cotizacionConConsulta();
        $estimadoId = $this->estimadoComoElCotizador('Cama Bali especial', '', 'camas', 400000);

        $this->actingAs($ebanista)->putJson("/api/consultas-costo/{$consultaId}/items/{$ciId}", [
            'precio_manual' => 1200000,
        ])->assertOk();
        $this->actingAs($ebanista)->postJson("/api/consultas-costo/{$consultaId}/enviar")->assertOk();

        $this->assertNull(EstimadoIa::find($estimadoId)->precio_humano);
    }

    // ── 3. Restauración: precio por pieza ────────────────────────────────────

    public function test_la_restauracion_se_cotiza_por_pieza_y_lo_dice(): void
    {
        OpenAI::fake([CreateResponse::fake([
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant',
                'content' => json_encode(['precio_fabricacion' => 100000, 'precio_sugerido_venta' => 180000,
                    'desglose_materiales' => [], 'desglose_mano_obra' => [], 'notas' => ''])]]],
        ])]);

        $r = app(AgentService::class)->calcularPrecioItem([
            'es_restauracion' => true, 'nombre' => 'Silla Luis XV', 'trabajo' => 'Retapizar', 'cantidad' => 2,
        ], $this->persona('vendedor'));

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['por_unidad']);
        $this->assertSame(2, $r['cantidad']);
        $this->assertSame(100000, $r['precio_fabricacion']);

        // Al modelo no le llega la cantidad: si la recibe, devuelve el total de las dos
        OpenAI::assertSent(Chat::class, function (string $metodo, array $params) {
            $todo = json_encode($params['messages'], JSON_UNESCAPED_UNICODE);
            return ! str_contains($todo, '2 pieza') && str_contains($todo, 'UNA');
        });
    }

    // ── 4. Cordura de un cambio sobre un mueble del catálogo ────────────────

    public function test_el_costo_de_un_cambio_no_se_compara_contra_el_mueble_completo(): void
    {
        $ficha = collect([(object) ['costo_total' => 1500000]]);
        $bom   = ['componentes' => [['nombre' => 'Retapizado']]];
        $s     = new SanityChecker();

        // Retapizar por $300.000 un mueble que cuesta $1.500.000: antes, −80 % → revisión.
        $this->assertFalse($s->revisar(300000, $bom, $ficha, 'SOFAS', true)['requiere_revision']);

        // Lo que sí es absurdo: que el cambio cueste más que fabricarlo entero
        $r = $s->revisar(1800000, $bom, $ficha, 'SOFAS', true);
        $this->assertTrue($r['requiere_revision']);
        $this->assertStringContainsString('más que fabricar el mueble completo', $r['motivos'][0]);

        // Sin la marca, el mueble completo se sigue revisando como siempre
        $this->assertTrue($s->revisar(300000, $bom, $ficha, null)['requiere_revision']);
    }
}
