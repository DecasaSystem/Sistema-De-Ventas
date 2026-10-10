<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Una orden cancelada no es venta (dueño, 2026-10-09).
 *
 * Al cancelar, la orden conserva su `valor_total` y sus pagos. El "Total
 * vendido" del Resumen solo quitaba cotizaciones y borradores, así que la
 * sumaba como vendida, mientras el resumen mensual y la cartera ya la sacaban:
 * el mismo mes daba dos cifras distintas según la pestaña. Ahora ninguna
 * pantalla de Reportes la cuenta, ni lo vendido ni lo abonado; solo sale en
 * el conteo de "canceladas".
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReportesNoCuentanCanceladasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('en_produccion'); $t->string('serie')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedBigInteger('covendedor_id')->nullable();
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

        $hoy = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');

        // Una venta viva de 10M con 4M abonados…
        $this->orden($hoy, 10_000_000, 'en_produccion', 4_000_000);
        // …y una de 6M con 3M abonados que el cliente canceló.
        $this->orden($hoy, 6_000_000, 'cancelado', 3_000_000);
    }

    private function orden(Carbon $creada, float $valor, string $estado, float $abono): void
    {
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => $estado,
            'valor_total' => $valor, 'created_at' => $creada, 'updated_at' => $creada,
        ]);
        DB::table('orden_items')->insert(['orden_id' => $id, 'precio_unitario' => $valor]);
        DB::table('pagos')->insert([
            'orden_id' => $id, 'tienda_id' => 1, 'monto' => $abono, 'created_at' => $creada, 'updated_at' => $creada,
        ]);
    }

    private function jefa(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now(),
        ]);
    }

    public function test_el_resumen_no_suma_la_orden_cancelada(): void
    {
        $r = $this->actingAs($this->jefa())->getJson('/api/stats/panel?periodo=mes')->assertOk()->json();

        $this->assertEquals(10_000_000, $r['total_vendido'], 'lo vendido es sin la cancelada');
        $this->assertEquals(4_000_000, $r['ingresos_totales'], 'lo cobrado de lo vendido, sin el abono de la cancelada');
        $this->assertEquals(4_000_000, $r['cobranza_periodo'], 'la cobranza tampoco cuenta lo abonado a la cancelada');
        $this->assertEquals(6_000_000, $r['cartera_pendiente']);
        $this->assertEquals(1, $r['ordenes_totales'], 'la cancelada no es una orden del período');
        $this->assertEquals(1, $r['ordenes_canceladas'], 'pero se sigue contando como cancelada');
        $this->assertEquals(10_000_000, $r['ticket_promedio']);
        // vendido = cobrado + por cobrar, sin la cancelada metida en medio.
        $this->assertEquals($r['total_vendido'], $r['ingresos_totales'] + $r['cartera_pendiente']);
    }

    public function test_el_reporte_de_ventas_no_suma_la_orden_cancelada(): void
    {
        $r = $this->actingAs($this->jefa())->getJson('/api/reportes/ventas?periodo=mes')->assertOk()->json('resumen');

        $this->assertEquals(1, $r['total_ordenes']);
        $this->assertEquals(4_000_000, $r['total_cobrado']);
        $this->assertEquals(10_000_000, $r['valor_bruto']);
    }

    public function test_la_tabla_de_vendedores_no_suma_la_orden_cancelada(): void
    {
        $filas = $this->actingAs($this->jefa())->getJson('/api/stats/vendedores?periodo=mes')->assertOk()->json();
        $v = collect($filas)->firstWhere('id', 1);

        $this->assertEquals(10_000_000, $v['total_vendido']);
        $this->assertEquals(1, $v['ordenes_totales']);
        $this->assertEquals(4_000_000, $v['ingresos']);
        $this->assertEquals(10_000_000, $v['ticket_promedio']);
        // Antes salía siempre 0: se leía como objeto un arreglo.
        $this->assertEquals(1, $v['ordenes_canceladas']);
    }
}
