<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Pago;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Registrar el anticipo de una orden que quedó sin él, desde editar.
 *
 * La orden se crea con "$0 — sin anticipo" o viene de una cotización, y
 * cuando llega la plata no había por dónde meterla como anticipo: editar solo
 * corregía uno que ya existiera, y el "% de anticipo sugerido" es un
 * porcentaje. La gente terminaba escribiendo ahí los pesos.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RegistrarAnticipoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('facturacion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->string('tipo')->default('venta');
            $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 5, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });

        $this->completarEsquemaDeEntregas();

        DB::table('tiendas')->insert(['id' => 8, 'nombre' => 'Independientes']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vendedora(): Usuario
    {
        return Usuario::create(['nombre' => 'Jessica', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'vendedor',
                                'tienda_default_id' => 8, 'created_at' => now()]);
    }

    private function orden(string $estado = 'pendiente_anticipo', float $condicionado = 0): Orden
    {
        return Orden::create([
            'cliente_id' => 1, 'tienda_id' => 8, 'vendedor_id' => 1, 'estado' => $estado,
            'valor_total' => 1_750_000, 'descuento_condicionado' => $condicionado, 'numero_orden' => 4310,
        ]);
    }

    public function test_registra_el_anticipo_que_faltaba(): void
    {
        $orden = $this->orden();

        $this->actingAs($this->vendedora())
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 500000, 'metodo' => 'transferencia', 'referencia' => 'NEQ-123'])
            ->assertCreated()
            ->assertJsonPath('total_pagado', 500000.0)
            ->assertJsonPath('saldo_pendiente', 1250000.0);

        $pago = Pago::first();
        $this->assertSame('anticipo', $pago->tipo);
        $this->assertSame('transferencia', $pago->metodo);
        $this->assertSame(8, (int) $pago->tienda_id);
        // Queda en el historial de la orden.
        $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', $orden->id)->count());
    }

    public function test_no_se_registra_dos_veces_ni_mas_de_lo_que_debe(): void
    {
        $orden = $this->orden();
        $v     = $this->vendedora();

        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 2_000_000, 'metodo' => 'efectivo'])
            ->assertStatus(422);

        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 500000, 'metodo' => 'efectivo'])
            ->assertCreated();

        // Ya tiene: se corrige, no se duplica.
        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 100000, 'metodo' => 'efectivo'])
            ->assertStatus(422);

        $this->assertSame(1, Pago::count());
    }

    public function test_con_la_orden_ya_lista_no_va_por_aqui(): void
    {
        $orden = $this->orden('listo_entrega');

        $this->actingAs($this->vendedora())
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 500000, 'metodo' => 'efectivo'])
            ->assertStatus(422);

        $this->assertSame(0, Pago::count());
    }

    public function test_con_tarjeta_avisa_que_se_pierde_el_descuento_y_solo_sigue_si_aceptan(): void
    {
        $orden = $this->orden('pendiente_anticipo', condicionado: 100_000);
        $v     = $this->vendedora();

        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 500000, 'metodo' => 'tarjeta'])
            ->assertStatus(409)
            ->assertJsonPath('descuento_en_riesgo.valor_sin_descuento', 1850000.0);
        $this->assertSame(0, Pago::count());

        $this->actingAs($v)
            ->postJson("/api/ordenes/{$orden->id}/anticipo", ['monto' => 500000, 'metodo' => 'tarjeta', 'aceptar_perdida_descuento' => true])
            ->assertCreated()
            ->assertJsonPath('descuento_revertido', true);

        $this->assertSame(1, Pago::count());
        $this->assertEquals(1_850_000, (float) $orden->fresh()->valor_total);
    }
}
