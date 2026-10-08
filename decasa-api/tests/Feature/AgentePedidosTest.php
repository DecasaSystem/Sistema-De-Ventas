<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "¿Cómo va mi pedido?" desde el agente de WhatsApp (AgentePedidosController).
 *
 * Lo que importa: que el cliente vea lo SUYO y nada más. Sin montos ni datos
 * internos, sin pedidos de otro número, sin borradores ni cotizaciones, y
 * encontrando su número aunque en la base esté escrito con espacios o +57.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class AgentePedidosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->timestamps();
        });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->string('estado');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('numero_anulado')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->string('contacto_nombre')->nullable(); $t->string('contacto_telefono')->nullable();
            $t->decimal('valor_total', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->integer('cantidad')->default(1);
            $t->integer('cantidad_entregada')->default(0); $t->date('fecha_entrega_prom')->nullable();
            $t->timestamp('devuelto_en')->nullable(); $t->timestamps();
        });

        config(['app.agent_token' => 'secreto-de-prueba']);

        DB::table('productos')->insert([['id' => 1, 'nombre' => 'CAMA MIAMI'], ['id' => 2, 'nombre' => 'MESA DE NOCHE LUNA']]);
        // El mismo cliente escrito como lo escribe la gente: con espacios.
        DB::table('clientes')->insert([
            ['id' => 10, 'nombre' => 'Ana', 'telefono' => '300 111 2233'],
            ['id' => 11, 'nombre' => 'Otro', 'telefono' => '3109998877'],
        ]);
    }

    private function orden(array $datos, array $items = [[1, 1, null]]): int
    {
        $id = DB::table('ordenes')->insertGetId(array_merge([
            'cliente_id' => 10, 'estado' => 'en_produccion', 'numero_orden' => 4200,
            'valor_total' => 2980000, 'created_at' => now(), 'updated_at' => now(),
        ], $datos));
        foreach ($items as [$producto, $cantidad, $fecha]) {
            DB::table('orden_items')->insert([
                'orden_id' => $id, 'producto_id' => $producto, 'cantidad' => $cantidad,
                'fecha_entrega_prom' => $fecha ?? '2026-10-30',
            ]);
        }
        return $id;
    }

    private function consultar(string $telefono = '+573001112233')
    {
        return $this->withHeader('X-Agent-Token', 'secreto-de-prueba')
            ->getJson('/api/agentes/pedidos?telefono=' . urlencode($telefono));
    }

    public function test_el_cliente_ve_lo_suyo_sin_montos(): void
    {
        $this->orden(['numero_orden' => 4200], [[1, 1, '2026-10-30'], [2, 2, '2026-10-25']]);

        $r = $this->consultar()->assertOk()->json('pedidos');

        $this->assertCount(1, $r);
        $this->assertSame('#4200', $r[0]['referencia']);
        $this->assertSame('En fabricación', $r[0]['estado']);
        $this->assertSame('2026-10-30', $r[0]['entrega_estimada']); // la del último producto
        $this->assertSame(['CAMA MIAMI', 'MESA DE NOCHE LUNA ×2'], $r[0]['productos']);
        // Nada de plata ni datos internos.
        $this->assertSame(
            ['referencia', 'estado', 'fecha_compra', 'entrega_estimada', 'entregados', 'unidades', 'productos'],
            array_keys($r[0])
        );
    }

    public function test_no_salen_pedidos_de_otro_numero(): void
    {
        $this->orden(['cliente_id' => 11, 'numero_orden' => 4300]);
        $this->assertSame([], $this->consultar()->assertOk()->json('pedidos'));
    }

    public function test_tambien_encuentra_la_venta_hecha_a_un_contacto_sin_cliente(): void
    {
        $this->orden(['cliente_id' => null, 'contacto_telefono' => '+57 300-111-2233', 'numero_orden' => 4400]);
        $this->assertSame('#4400', $this->consultar()->json('pedidos.0.referencia'));
    }

    public function test_borradores_cotizaciones_y_anuladas_no_salen(): void
    {
        $this->orden(['estado' => 'borrador', 'numero_orden' => null]);
        $this->orden(['estado' => 'cotizacion', 'numero_orden' => null, 'cotizacion_numero' => 9]);
        $this->orden(['estado' => 'cancelado', 'numero_anulado' => '4100']);
        $this->assertSame([], $this->consultar()->json('pedidos'));
    }

    public function test_una_entregada_hace_rato_ya_no_sale(): void
    {
        $this->orden(['estado' => 'entregado', 'numero_orden' => 4000, 'updated_at' => now()->subDays(90)]);
        $this->orden(['estado' => 'entregado', 'numero_orden' => 4001, 'updated_at' => now()->subDays(5)]);
        $r = $this->consultar()->json('pedidos');
        $this->assertCount(1, $r);
        $this->assertSame('Entregada', $r[0]['estado']);
        $this->assertNull($r[0]['entrega_estimada']);
    }

    public function test_sin_token_no_hay_nada(): void
    {
        $this->orden([]);
        $this->getJson('/api/agentes/pedidos?telefono=3001112233')->assertStatus(401);
        $this->withHeader('X-Agent-Token', 'otro')->getJson('/api/agentes/pedidos?telefono=3001112233')->assertStatus(401);
    }

    public function test_un_telefono_incompleto_se_rechaza(): void
    {
        $this->withHeader('X-Agent-Token', 'secreto-de-prueba')
            ->getJson('/api/agentes/pedidos?telefono=2233')->assertStatus(422);
    }
}
