<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El recuadro de "Resumen" (GET /api/stats/panel) tiene que cuadrar consigo
 * mismo: lo vendido, lo cobrado y lo que falta por cobrar son del MISMO
 * período de arriba.
 *
 * Antes `cartera_pendiente` no llevaba filtro de fecha —era la deuda viva de
 * todos los meses—, así que al cambiar el mes el número no se movía y parecía
 * que la pantalla se había quedado pegada. Ahora se acota por `o.created_at`
 * igual que `total_vendido`. El saldo vivo total sigue en /api/stats/cartera.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class PanelCarteraDelPeriodoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_reportes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('en_produccion'); $t->string('serie')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->timestamps();
        });

        // Misma definición que la migración 2026_06_06_200000, sin CREATE OR
        // REPLACE (SQLite no lo admite).
        DB::statement('
            CREATE VIEW v_saldo_ordenes AS
            SELECT
                o.id                                       AS orden_id,
                o.valor_total,
                COALESCE(SUM(p.monto), 0)                   AS total_pagado,
                o.valor_total - COALESCE(SUM(p.monto), 0)   AS saldo_pendiente
            FROM ordenes o
            LEFT JOIN pagos p ON p.orden_id = o.id
            GROUP BY o.id, o.valor_total
        ');

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte', 'activa' => true]);
        DB::table('usuarios')->insert([
            'id' => 1, 'nombre' => 'Vendedora', 'rol' => 'vendedor', 'tienda_default_id' => 1, 'created_at' => now(),
        ]);
    }

    /** Crea una orden con su ítem y sus abonos en la fecha indicada (UTC). */
    private function orden(Carbon $creada, float $valor, array $pagos): int
    {
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => 'en_produccion',
            'valor_total' => $valor, 'created_at' => $creada, 'updated_at' => $creada,
        ]);
        DB::table('orden_items')->insert([
            'orden_id' => $id, 'es_restauracion' => false, 'precio_unitario' => $valor,
        ]);
        foreach ($pagos as $monto) {
            DB::table('pagos')->insert([
                'orden_id' => $id, 'monto' => $monto, 'created_at' => $creada, 'updated_at' => $creada,
            ]);
        }

        return $id;
    }

    private function panel(string $periodo): array
    {
        $jefe = Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now(),
        ]);

        return $this->actingAs($jefe)->getJson("/api/stats/panel?periodo={$periodo}")->assertOk()->json();
    }

    public function test_el_saldo_pendiente_del_resumen_se_mueve_con_el_filtro_de_mes(): void
    {
        $esteMes = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');
        $mesPasado = Carbon::now('America/Bogota')->subMonthNoOverflow()->startOfMonth()->addDays(10)->setTimezone('UTC');

        // Este mes: se vendió 10M, se abonó 3M → faltan 7M.
        $this->orden($esteMes, 10_000_000, [3_000_000]);
        // Mes pasado: se vendió 5M, se abonó 1M → faltan 4M.
        $this->orden($mesPasado, 5_000_000, [1_000_000]);

        $mes = $this->panel('mes');
        $this->assertEquals(10_000_000, $mes['total_vendido']);
        $this->assertEquals(3_000_000,  $mes['ingresos_totales']);
        $this->assertEquals(7_000_000,  $mes['cartera_pendiente'], 'solo el saldo de lo vendido este mes');

        $anterior = $this->panel('mes_anterior');
        $this->assertEquals(5_000_000, $anterior['total_vendido']);
        $this->assertEquals(1_000_000, $anterior['ingresos_totales']);
        $this->assertEquals(4_000_000, $anterior['cartera_pendiente'], 'el filtro sí cambia el saldo');
    }

    public function test_una_orden_del_periodo_ya_pagada_no_suma_a_la_cartera(): void
    {
        $esteMes = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');

        $this->orden($esteMes, 8_000_000, [8_000_000]);          // saldada
        $this->orden($esteMes, 6_000_000, [2_000_000]);          // debe 4M

        $mes = $this->panel('mes');

        $this->assertEquals(14_000_000, $mes['total_vendido']);
        $this->assertEquals(4_000_000,  $mes['cartera_pendiente']);
    }
}
