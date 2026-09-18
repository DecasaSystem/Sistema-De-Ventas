<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las estadísticas de un vendedor (GET /api/stats/vendedor/{id}) tienen que
 * cuadrar consigo mismas: "Total vendido" y "Dinero cobrado" son del mismo
 * período.
 *
 * Antes "Dinero cobrado" sumaba TODOS los abonos recibidos en el rango por
 * fecha del pago, así que un abono de este mes a una orden de un mes anterior
 * hacía que "cobrado" superara a "vendido" y el recuadro parecía roto. Ese
 * número sigue disponible aparte como "Cobranza del período".
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class VendedorStatsCobradoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('es_independientes')->default(false); $t->date('cerrada_en')->nullable();
        });
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes', 7); $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable(); $t->string('nota')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes'); $t->decimal('meta', 15, 2)->default(0);
            $t->unsignedInteger('divisor_asesores')->default(1); $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->boolean('es_compartida')->default(false); $t->string('estado')->default('en_produccion');
            $t->string('canal')->nullable(); $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 15, 2)->default(0);
            $t->boolean('es_restauracion')->default(false);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable(); $t->timestamps();
        });

        DB::statement('
            CREATE VIEW v_saldo_ordenes AS
            SELECT o.id AS orden_id, o.valor_total,
                   COALESCE(SUM(p.monto), 0) AS total_pagado,
                   o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
            FROM ordenes o LEFT JOIN pagos p ON p.orden_id = o.id
            GROUP BY o.id, o.valor_total
        ');

        DB::table('usuarios')->insert([
            'id' => 1, 'nombre' => 'Vendedor', 'rol' => 'vendedor', 'tienda_default_id' => null, 'created_at' => now(),
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente']);
    }

    private function orden(Carbon $creada, float $valor): int
    {
        return DB::table('ordenes')->insertGetId([
            'cliente_id' => 1, 'vendedor_id' => 1, 'estado' => 'en_produccion',
            'valor_total' => $valor, 'created_at' => $creada, 'updated_at' => $creada,
        ]);
    }
    private function abono(int $ordenId, Carbon $fecha, float $monto): void
    {
        DB::table('pagos')->insert([
            'orden_id' => $ordenId, 'monto' => $monto, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    public function test_cobrado_no_supera_lo_vendido_y_la_cobranza_va_aparte(): void
    {
        $esteMes   = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');
        $mesPasado = Carbon::now('America/Bogota')->subMonthNoOverflow()->startOfMonth()->addDays(10)->setTimezone('UTC');

        // Vendió 6M este mes y le abonaron 2M el mismo día.
        $nueva = $this->orden($esteMes, 6_000_000);
        $this->abono($nueva, $esteMes, 2_000_000);

        // Orden de mes pasado (5M) que el cliente termina de pagar ESTE mes.
        $vieja = $this->orden($mesPasado, 5_000_000);
        $this->abono($vieja, $esteMes, 5_000_000);

        $jefe = Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now(),
        ]);

        $r = $this->actingAs($jefe)->getJson('/api/stats/vendedor/1?periodo=mes')->assertOk()->json();

        $this->assertEquals(6_000_000, $r['total_vendido']);
        $this->assertEquals(2_000_000, $r['dinero_vendido'], 'cobrado de lo vendido en el período');
        $this->assertLessThanOrEqual($r['total_vendido'], $r['dinero_vendido']);

        // La caja real del mes: 2M de la nueva + 5M de la vieja.
        $this->assertEquals(7_000_000, $r['cobranza_periodo']);

        // "Órdenes del período" trae solo las del rango: la de mes pasado no
        // sale, y el conteo cuadra con "órdenes creadas".
        $this->assertCount(1, $r['ordenes_recientes']);
        $this->assertEquals($r['ordenes_creadas'], count($r['ordenes_recientes']));
        $this->assertEquals($nueva, $r['ordenes_recientes'][0]['id']);
    }
}
