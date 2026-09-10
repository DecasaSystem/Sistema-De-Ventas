<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La pestaña Tiendas de Reportes (GET /api/stats/tiendas) tiene que cuadrar
 * consigo misma, igual que el Resumen y el perfil de vendedor.
 *
 * Antes "Total vendido" de una tienda era `cobrado + cartera`, donde "cobrado"
 * era toda la caja del rango (incluidos abonos de órdenes de otros meses). Eso
 * mezclaba dos períodos y no era "lo que vendió la tienda". Ahora "Total
 * vendido" es el valor de las órdenes creadas en el rango, "Cobrado" lo
 * abonado a esas órdenes, y la caja real va aparte en `cobranza_periodo`.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class TiendasStatsCuadraTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable();
            $t->boolean('activa')->default(true); $t->boolean('es_fabrica')->default(false);
            $t->boolean('es_independientes')->default(false);
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('covendedor_id')->nullable(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->boolean('es_compartida')->default(false); $t->string('estado')->default('en_produccion');
            $t->string('canal')->nullable(); $t->string('serie')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::statement('
            CREATE VIEW v_saldo_ordenes AS
            SELECT o.id AS orden_id, o.valor_total,
                   COALESCE(SUM(p.monto), 0) AS total_pagado,
                   o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
            FROM ordenes o LEFT JOIN pagos p ON p.orden_id = o.id
            GROUP BY o.id, o.valor_total
        ');

        $this->prestarleASqliteLoQueEsDeMysql();

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte', 'activa' => true]);
        DB::table('usuarios')->insert([
            'id' => 1, 'nombre' => 'Vendedora', 'rol' => 'vendedor', 'tienda_default_id' => 1, 'created_at' => now(),
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente']);
    }

    private function orden(Carbon $creada, float $valor): int
    {
        return DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => 1, 'cliente_id' => 1, 'estado' => 'en_produccion',
            'valor_total' => $valor, 'created_at' => $creada, 'updated_at' => $creada,
        ]);
    }
    private function abono(int $ordenId, Carbon $fecha, float $monto): void
    {
        DB::table('pagos')->insert([
            'orden_id' => $ordenId, 'tienda_id' => 1, 'monto' => $monto, 'created_at' => $fecha,
        ]);
    }

    public function test_total_vendido_de_la_tienda_es_lo_del_periodo_no_cobrado_mas_cartera(): void
    {
        $esteMes   = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');
        $mesPasado = Carbon::now('America/Bogota')->subMonthNoOverflow()->startOfMonth()->addDays(10)->setTimezone('UTC');

        // Este mes: vendió 10M, abonaron 3M el mismo día → falta 7M.
        $nueva = $this->orden($esteMes, 10_000_000);
        $this->abono($nueva, $esteMes, 3_000_000);

        // Mes pasado: 8M, y el cliente le abona 6M ESTE mes.
        $vieja = $this->orden($mesPasado, 8_000_000);
        $this->abono($vieja, $esteMes, 6_000_000);

        $jefe = Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now(),
        ]);

        $filas = $this->actingAs($jefe)->getJson('/api/stats/tiendas?periodo=mes')->assertOk()->json();
        $norte = collect($filas)->firstWhere('tienda_id', 1);

        $this->assertEquals(10_000_000, $norte['total_vendido'], 'solo lo vendido este mes');
        $this->assertEquals(3_000_000,  $norte['ingresos'], 'cobrado de lo del período');
        $this->assertLessThanOrEqual($norte['total_vendido'], $norte['ingresos']);
        $this->assertEquals(7_000_000,  $norte['cartera_pendiente']);
        // La caja real del mes: 3M de la nueva + 6M de la vieja.
        $this->assertEquals(9_000_000,  $norte['cobranza_periodo']);
        // Ticket = vendido / órdenes del período (1), no cobrado / entregadas.
        $this->assertEquals(10_000_000, $norte['ticket_promedio']);
    }
}
