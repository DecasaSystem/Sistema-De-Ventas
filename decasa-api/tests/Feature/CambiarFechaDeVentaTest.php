<?php

namespace Tests\Feature;

use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Support\RangoDeUsuarios;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cambiar la fecha de una venta (usuario, 2026-11-20): se vendió el 31 de
 * octubre y la subieron el 1 de noviembre. Solo un super admin; la venta y su
 * bonificación pasan al mes en que de verdad se vendió.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CambiarFechaDeVentaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('facturacion')->default(false);
            $t->boolean('es_super_admin')->default(false); $t->boolean('puede_fv2_sin_iva')->default(false);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->decimal('descuento_total', 12, 2)->default(0);
            $t->decimal('descuento_condicionado', 12, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 8, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->string('motivo_serie')->nullable(); $t->boolean('sin_descontar_iva')->default(false);
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->string('notas')->nullable(); $t->string('canal')->nullable();
            $t->string('factura_foto_url')->nullable(); $t->string('anexo_foto_url')->nullable();
            $t->string('firma_url')->nullable(); $t->decimal('anticipo_pct', 5, 2)->nullable();
            $t->string('departamento_envio')->nullable(); $t->string('ciudad_envio')->nullable();
            $t->string('direccion_envio')->nullable(); $t->date('fecha_sugerida_vendedor')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            // La tabla real siempre la tiene, y de ella sale si una orden es
            // restauracion: sin la columna, cualquier cosa que toque comisiones
            // revienta aqui y no en el codigo que se esta probando.
            $t->boolean('es_restauracion')->default(false);
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(false);
            $t->boolean('es_regalo')->default(false); $t->json('specs_personalizacion')->nullable();
            $t->string('boceto_url')->nullable(); $t->json('boceto_fotos')->nullable();
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable();
            $t->text('motivo_devolucion')->nullable(); $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) { $t->string('clave_unica')->nullable()->unique(); $t->string('forma_pago_pagada')->nullable();
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id'); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2); $t->date('fecha_venta'); $t->date('fecha_disponible');
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('tipo')->nullable();
            $t->decimal('monto', 12, 2); $t->string('metodo')->nullable(); $t->string('referencia')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });

        // Hoy es 5 de noviembre (en Bogotá).
        Carbon::setTestNow('2026-11-05 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona(string $nombre, string $rol, bool $superAdmin = false): Usuario
    {
        $u = Usuario::create(['nombre' => $nombre, 'email' => strtolower($nombre) . '@d.com', 'password' => 'x',
                              'rol' => $rol, 'created_at' => now()]);
        if ($superAdmin) $u->forceFill(['es_super_admin' => true])->save();
        RangoDeUsuarios::olvidar();

        return $u->fresh();
    }

    /**
     * Venta del 31 de octubre que Gladys subió el 1 de noviembre a las 9 a. m.
     * (14:00 UTC), con su bonificación de noviembre.
     */
    private function ventaSubidaEl1(string $estadoComision = 'pendiente', string $estado = 'en_produccion'): Orden
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'El Edén']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Lina', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Sofá']);
        $gladys = $this->persona('Gladys', 'vendedor');

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $gladys->id, 'estado' => $estado,
            'valor_total' => 2000000, 'canal' => 'fisica', 'numero_orden' => 4423,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => '2026-11-01 14:00:00']);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => 1,
                           'cantidad' => 1, 'precio_unitario' => 2000000]);
        Comision::create(['orden_id' => $orden->id, 'vendedor_id' => $gladys->id, 'tienda_id' => 1,
                          'mes_venta' => '2026-11', 'valor_orden' => 2000000, 'fecha_venta' => '2026-11-01',
                          'fecha_disponible' => '2026-12-20', 'estado' => $estadoComision]);

        return $orden->fresh();
    }

    public function test_un_super_admin_la_pasa_al_31_y_la_bonificacion_se_va_con_ella(): void
    {
        $orden = $this->ventaSubidaEl1();
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}/fecha-venta", [
            'fecha' => '2026-10-31', 'motivo' => 'Se vendió el 31',
        ])->assertOk();

        $orden->refresh();
        $this->assertSame('2026-10-31', $orden->created_at->copy()->setTimezone('America/Bogota')->toDateString());
        $this->assertSame(4423, (int) $orden->numero_orden);

        $c = Comision::first();
        $this->assertSame('2026-10', $c->mes_venta);
        $this->assertSame('2026-10-31', substr((string) $c->fecha_venta, 0, 10));
        $this->assertSame('2026-11-20', substr((string) $c->fecha_disponible, 0, 10));

        $cambios = json_decode((string) DB::table('orden_ediciones')->where('orden_id', $orden->id)->value('cambios'), true);
        $this->assertSame(['01/11/2026', '31/10/2026'], [$cambios[0]['antes'], $cambios[0]['despues']]);
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', $orden->vendedor_id)->count());
    }

    public function test_un_supervisor_que_no_es_super_admin_no_puede(): void
    {
        $orden = $this->ventaSubidaEl1();
        $this->persona('Dueño', 'supervisor', superAdmin: true);
        $jefa = $this->persona('Jefa', 'supervisor');

        $this->actingAs($jefa)->patchJson("/api/ordenes/{$orden->id}/fecha-venta", ['fecha' => '2026-10-31'])
            ->assertForbidden();

        $this->assertSame('2026-11', Comision::first()->mes_venta);
    }

    public function test_con_la_bonificacion_pagada_no_se_mueve(): void
    {
        $orden = $this->ventaSubidaEl1('pagada');
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}/fecha-venta", ['fecha' => '2026-10-31'])
            ->assertStatus(422);

        $this->assertSame('2026-11-01 14:00:00', (string) DB::table('ordenes')->value('created_at'));
    }

    public function test_no_a_una_fecha_que_no_ha_llegado(): void
    {
        $orden = $this->ventaSubidaEl1();
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}/fecha-venta", ['fecha' => '2026-11-06'])
            ->assertStatus(422);
        // Hoy sí se puede.
        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}/fecha-venta", ['fecha' => '2026-11-05'])
            ->assertOk();
    }

    public function test_un_borrador_no_tiene_fecha_de_venta(): void
    {
        $orden = $this->ventaSubidaEl1(estado: 'borrador');
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}/fecha-venta", ['fecha' => '2026-10-31'])
            ->assertStatus(422);
    }
}
