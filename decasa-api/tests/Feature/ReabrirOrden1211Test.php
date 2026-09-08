<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El arreglo de datos de la orden 1211 (se marcó entregada por error).
 *
 * Se monta una orden "entregada" con lo que deja una entrega —inventario
 * descontado, producción cerrada, pago `saldo_final`, despacho_item— y se
 * corre la migración para comprobar que todo vuelve a su sitio y que el
 * abono real ($700.000) queda intacto.
 */
class ReabrirOrden1211Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('estado');
            $t->decimal('valor_total', 12, 2)->default(0); $t->timestamp('listo_entrega_at')->nullable();
            $t->boolean('entrega_inmediata')->default(false); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->integer('cantidad')->default(1); $t->boolean('es_personalizado')->default(false);
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->string('tipo')->nullable();
            $t->decimal('monto', 12, 2)->default(0); $t->string('metodo')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->string('estado')->default('pendiente');
            $t->date('fecha_real')->nullable();
        });
        Schema::create('despachos', function (Blueprint $t) { $t->id(); $t->string('estado')->nullable(); $t->timestamps(); });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id'); $t->string('estado')->nullable();
            $t->string('foto_producto')->nullable(); $t->string('foto_pago')->nullable(); $t->string('firma_recibido_url')->nullable();
        });
        Schema::create('devoluciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('orden_item_id');
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->json('cambios'); $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Jefa', 'rol' => 'supervisor']);
    }

    private function correrMigracion(): void
    {
        $migracion = require base_path('database/migrations/2026_09_08_000003_reabrir_orden_1211.php');
        $migracion->up();
    }

    private function sembrarOrdenEntregadaPorError(): void
    {
        DB::table('ordenes')->insert([
            'id' => 1211, 'tienda_id' => 1, 'estado' => 'entregado', 'valor_total' => 2000000,
            'listo_entrega_at' => now()->subDays(2), 'created_at' => now()->subDays(5), 'updated_at' => now(),
        ]);
        // Un mueble de stock (x2) y una cama personalizada.
        DB::table('orden_items')->insert([
            ['id' => 1, 'orden_id' => 1211, 'producto_id' => 5, 'cantidad' => 2, 'es_personalizado' => false, 'tienda_origen_id' => 1, 'variante_id' => null, 'combo_config_id' => null],
            ['id' => 2, 'orden_id' => 1211, 'producto_id' => null, 'cantidad' => 1, 'es_personalizado' => true, 'tienda_origen_id' => null, 'variante_id' => null, 'combo_config_id' => null],
        ]);
        // Inventario ya descontado por la "entrega".
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0]);
        // Producción cerrada por la "entrega".
        DB::table('produccion')->insert(['orden_item_id' => 2, 'estado' => 'entregado', 'fecha_real' => now()->toDateString()]);
        // El abono real + el saldo_final que creó la entrega.
        DB::table('pagos')->insert([
            ['orden_id' => 1211, 'tipo' => 'anticipo', 'monto' => 700000, 'metodo' => 'efectivo', 'created_at' => now()->subDays(4)],
            ['orden_id' => 1211, 'tipo' => 'saldo_final', 'monto' => 1300000, 'metodo' => 'transferencia', 'created_at' => now()],
        ]);
        $dId = DB::table('despachos')->insertGetId(['estado' => 'completado', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('despacho_items')->insert(['despacho_id' => $dId, 'orden_id' => 1211, 'estado' => 'entregado']);
    }

    public function test_reabre_la_orden_y_deja_el_abono_real(): void
    {
        $this->sembrarOrdenEntregadaPorError();

        $this->correrMigracion();

        $orden = DB::table('ordenes')->find(1211);
        // Con la cama personalizada reabierta a 'listo', queda lista para entrega.
        $this->assertSame('listo_entrega', $orden->estado);

        // Inventario devuelto: +2 disponible y +2 reservado para esta orden.
        $inv = DB::table('inventario')->where('producto_id', 5)->where('tienda_id', 1)->first();
        $this->assertSame(5, (int) $inv->cantidad_disponible);
        $this->assertSame(2, (int) $inv->cantidad_reservada);

        // Producción reabierta.
        $this->assertSame('listo', DB::table('produccion')->where('orden_item_id', 2)->value('estado'));

        // Solo queda el abono real.
        $this->assertSame(700000.0, (float) DB::table('pagos')->where('orden_id', 1211)->sum('monto'));
        $this->assertSame(0, DB::table('pagos')->where('orden_id', 1211)->where('tipo', 'saldo_final')->count());

        // El despacho/acta de la entrega que no pasó, fuera.
        $this->assertSame(0, DB::table('despacho_items')->where('orden_id', 1211)->count());
        $this->assertSame(0, DB::table('despachos')->count());

        // Queda el movimiento de inventario y el rastro en la orden.
        $this->assertSame(1, DB::table('inventario_movimientos')->where('tipo', 'entrada')->count());
        $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', 1211)->count());
    }

    public function test_no_borra_pagos_si_la_cuenta_no_cuadra_exacto(): void
    {
        DB::table('ordenes')->insert([
            'id' => 1211, 'tienda_id' => 1, 'estado' => 'entregado', 'valor_total' => 2000000,
            'listo_entrega_at' => now()->subDay(), 'created_at' => now()->subDays(5), 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert(['id' => 1, 'orden_id' => 1211, 'producto_id' => 5, 'cantidad' => 1,
            'es_personalizado' => false, 'tienda_origen_id' => 1, 'variante_id' => null, 'combo_config_id' => null]);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 0, 'cantidad_reservada' => 0]);
        // Total 900.000; quitar el saldo_final dejaría 400.000, no 700.000 → no se toca.
        DB::table('pagos')->insert([
            ['orden_id' => 1211, 'tipo' => 'abono', 'monto' => 400000, 'created_at' => now()->subDays(3)],
            ['orden_id' => 1211, 'tipo' => 'saldo_final', 'monto' => 500000, 'created_at' => now()],
        ]);

        $this->correrMigracion();

        // La orden se reabre igual, pero los pagos quedan intactos para revisar.
        $this->assertSame('listo_entrega', DB::table('ordenes')->where('id', 1211)->value('estado'));
        $this->assertSame(900000.0, (float) DB::table('pagos')->where('orden_id', 1211)->sum('monto'));
    }

    public function test_es_idempotente_si_la_orden_ya_no_esta_entregada(): void
    {
        DB::table('ordenes')->insert([
            'id' => 1211, 'tienda_id' => 1, 'estado' => 'listo_entrega', 'valor_total' => 2000000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pagos')->insert(['orden_id' => 1211, 'tipo' => 'anticipo', 'monto' => 700000, 'created_at' => now()]);

        $this->correrMigracion();

        $this->assertSame('listo_entrega', DB::table('ordenes')->where('id', 1211)->value('estado'));
        $this->assertSame(700000.0, (float) DB::table('pagos')->where('orden_id', 1211)->sum('monto'));
    }
}
