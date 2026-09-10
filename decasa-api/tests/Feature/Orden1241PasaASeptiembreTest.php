<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración puntual que mueve la orden #1241 del 31 de agosto al 1 de
 * septiembre y rehace su comisión.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class Orden1241PasaASeptiembreTest extends TestCase
{
    private string $migracion = 'database/migrations/2026_09_21_000001_orden_1241_pasa_a_septiembre.php';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('covendedor_id')->nullable(); $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->boolean('es_compartida')->default(false); $t->string('estado')->default('en_produccion');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 15, 2)->default(0);
            $t->string('metodo')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->text('cambios')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Unicentro Pereira']);
        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Juan', 'rol' => 'vendedor', 'tienda_default_id' => 1]);
        DB::table('usuarios')->insert(['id' => 2, 'nombre' => 'Jefa', 'rol' => 'supervisor']);
    }

    private function crearOrden1241(): int
    {
        // 31 ago 15:00 Colombia == 31 ago 20:00 UTC.
        $creada = Carbon::create(2026, 8, 31, 20, 0, 0, 'UTC');

        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => 1, 'numero_orden' => 1241, 'estado' => 'en_produccion',
            'valor_total' => 4_200_000, 'created_at' => $creada, 'updated_at' => $creada,
        ]);
        DB::table('orden_items')->insert(['orden_id' => $id, 'es_restauracion' => false, 'precio_unitario' => 4_200_000]);
        DB::table('comisiones')->insert([
            'orden_id' => $id, 'vendedor_id' => 1, 'tienda_id' => 1, 'origen' => 'venta',
            'mes_venta' => '2026-08', 'valor_orden' => 4_200_000,
            'fecha_venta' => '2026-08-31', 'fecha_disponible' => '2026-10-20', 'estado' => 'pendiente',
        ]);

        return $id;
    }

    private function correrMigracion(): void
    {
        $migration = require base_path($this->migracion);
        $migration->up();
    }

    public function test_mueve_la_fecha_y_rehace_la_comision(): void
    {
        $id = $this->crearOrden1241();

        $this->correrMigracion();

        $orden = DB::table('ordenes')->find($id);
        $fechaCol = Carbon::parse($orden->created_at, 'UTC')->setTimezone('America/Bogota');
        $this->assertSame('2026-09-01', $fechaCol->format('Y-m-d'));
        $this->assertSame('15:00', $fechaCol->format('H:i'), 'se conserva la hora del día');

        $com = DB::table('comisiones')->where('orden_id', $id)->first();
        $this->assertSame('2026-09', $com->mes_venta);
        $this->assertSame('2026-09-01', substr((string) $com->fecha_venta, 0, 10));
        $this->assertSame('2026-10-20', substr((string) $com->fecha_disponible, 0, 10));
        $this->assertSame('pendiente', $com->estado);

        $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', $id)->count());
    }

    public function test_no_toca_nada_si_la_comision_ya_esta_pagada(): void
    {
        $id = $this->crearOrden1241();
        DB::table('comisiones')->where('orden_id', $id)->update(['estado' => 'pagada']);

        $this->correrMigracion();

        $orden = DB::table('ordenes')->find($id);
        $fechaCol = Carbon::parse($orden->created_at, 'UTC')->setTimezone('America/Bogota');
        $this->assertSame('2026-08-31', $fechaCol->format('Y-m-d'), 'la orden no se movió');
        $this->assertSame('2026-08', DB::table('comisiones')->where('orden_id', $id)->value('mes_venta'));
    }

    public function test_no_hace_nada_si_ya_esta_en_septiembre(): void
    {
        $id = $this->crearOrden1241();
        DB::table('ordenes')->where('id', $id)->update([
            'created_at' => Carbon::create(2026, 9, 1, 20, 0, 0, 'UTC'),
        ]);

        $this->correrMigracion();

        $this->assertSame(0, DB::table('orden_ediciones')->where('orden_id', $id)->count());
    }
}
