<?php

namespace Tests\Feature;

use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Support\RangoDeUsuarios;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FV2 "no se resta el IVA" desde Editar orden (usuario, 2026-11-20).
 *
 * Al crear la FV2 la marca la prende quien tiene `puede_fv2_sin_iva` (Henry,
 * cuando llega alguien de la familia del dueño). Si se le olvidó, la corrige un
 * super admin desde Editar orden, también con la orden ya entregada. Nadie más:
 * cambia la bonificación del 5 % directo, lo que Finanzas cuenta como venta no
 * gravada y el IVA de la factura.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class Fv2SinIvaAlEditarTest extends TestCase
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
    }

    private function persona(string $nombre, string $rol, bool $superAdmin = false): Usuario
    {
        $u = Usuario::create(['nombre' => $nombre, 'email' => strtolower($nombre) . '@d.com', 'password' => 'x',
                              'rol' => $rol, 'created_at' => now()]);
        if ($superAdmin) $u->forceFill(['es_super_admin' => true])->save();
        RangoDeUsuarios::olvidar();

        return $u->fresh();
    }

    /** Una FV2 de Henry, ya entregada, en la que se le olvidó la marca. */
    private function fv2(string $serie = Orden::SERIE_FV2): Orden
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Familia', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Sofá']);
        $henry = $this->persona('Henry', 'vendedor');

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $henry->id, 'estado' => 'entregado',
            'valor_total' => 1190000, 'canal' => 'fisica',
            ...($serie ? ['serie' => $serie, 'serie_numero' => 30] : ['numero_orden' => 4400]),
        ]);
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => 1,
                           'cantidad' => 1, 'precio_unitario' => 1190000]);

        return $orden;
    }

    public function test_un_super_admin_la_marca_en_una_fv2_entregada_y_queda_en_el_historial(): void
    {
        $orden = $this->fv2();
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}", ['sin_descontar_iva' => true])
            ->assertOk()
            ->assertJsonPath('sin_descontar_iva', true);

        $this->assertTrue($orden->fresh()->sin_descontar_iva);
        $cambios = DB::table('orden_ediciones')->where('orden_id', $orden->id)->value('cambios');
        $this->assertStringContainsString('sin_descontar_iva', $cambios);
    }

    public function test_tambien_la_quita_si_se_marco_de_mas(): void
    {
        $orden = $this->fv2();
        $orden->update(['sin_descontar_iva' => true]);
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}", ['sin_descontar_iva' => false])->assertOk();

        $this->assertFalse($orden->fresh()->sin_descontar_iva);
    }

    public function test_un_supervisor_que_no_es_super_admin_no_la_cambia(): void
    {
        $orden = $this->fv2();
        $this->persona('Dueño', 'supervisor', superAdmin: true);
        $jefa = $this->persona('Jefa', 'supervisor');

        $this->actingAs($jefa)->patchJson("/api/ordenes/{$orden->id}", ['sin_descontar_iva' => true])->assertForbidden();

        $this->assertFalse($orden->fresh()->sin_descontar_iva);
    }

    /** La pantalla manda el campo aunque no cambie: así no frena a nadie. */
    public function test_mandarla_igual_que_estaba_no_frena_al_supervisor(): void
    {
        $orden = $this->fv2();
        $jefa = $this->persona('Jefa', 'supervisor');

        $this->actingAs($jefa)->patchJson("/api/ordenes/{$orden->id}", [
            'sin_descontar_iva' => false, 'notas' => 'Revisada',
        ])->assertOk();
    }

    public function test_solo_en_una_fv2(): void
    {
        $orden = $this->fv2(serie: '');
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}", ['sin_descontar_iva' => true])->assertStatus(422);

        $this->assertFalse($orden->fresh()->sin_descontar_iva);
    }

    /** Con la bonificación ya pagada, se avisa que lo pagado no cambia solo. */
    public function test_con_la_bonificacion_pagada_avisa(): void
    {
        $orden = $this->fv2();
        Comision::create(['orden_id' => $orden->id, 'vendedor_id' => $orden->vendedor_id, 'tienda_id' => 1,
                          'mes_venta' => '2026-11', 'valor_orden' => 1190000, 'fecha_venta' => '2026-11-02',
                          'fecha_disponible' => '2026-11-20', 'estado' => 'pagada', 'monto_comision' => 50000]);
        $admin = $this->persona('Dueño', 'supervisor', superAdmin: true);

        $r = $this->actingAs($admin)->patchJson("/api/ordenes/{$orden->id}", ['sin_descontar_iva' => true])->assertOk();

        $this->assertStringContainsString('ya estaba pagada', (string) $r->json('aviso_comisiones'));
        $this->assertEquals(50000, Comision::first()->monto_comision);
    }
}
