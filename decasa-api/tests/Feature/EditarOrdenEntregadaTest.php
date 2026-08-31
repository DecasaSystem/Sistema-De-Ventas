<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Corregir los papeles de una orden que ya salió.
 *
 * Antes una orden entregada no se podía tocar en absoluto: si la foto de la
 * factura salía borrosa o faltaba el anexo firmado, no había forma de
 * arreglarlo. Ahora se puede entrar a editar, pero sólo a lo que no mueve
 * nada — el precio y los productos ya se cobraron, ya descontaron bodega y ya
 * calcularon comisión, y cambiar un producto entregado tiene su propio camino,
 * que sabe no cobrarlo dos veces.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class EditarOrdenEntregadaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('facturacion')->default(false);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
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
        Schema::create('comisiones', function (Blueprint $t) {
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

    private function jefe(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    /** Una mesa de $800.000 ya entregada, con la foto de la factura borrosa. */
    private function ordenEntregada(string $estado = 'entregado'): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Sra. Pérez', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa de comedor']);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => $estado,
            'valor_total' => 800000, 'numero_orden' => 4300, 'canal' => 'fisica',
            'factura_foto_url' => 'https://x.example/borrosa.jpg',
        ]);

        $mesa = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => 1,
                                   'cantidad' => 1, 'precio_unitario' => 800000]);

        return [$orden, $mesa];
    }

    // ── Lo que sí se puede corregir ───────────────────────────────────────────

    public function test_se_cambia_la_foto_de_una_orden_entregada(): void
    {
        [$orden] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'factura_foto_url' => 'https://x.example/nitida.jpg',
        ])->assertOk();

        $this->assertSame('https://x.example/nitida.jpg', $orden->fresh()->factura_foto_url);
    }

    public function test_se_corrigen_notas_y_direccion(): void
    {
        [$orden] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'notas'           => 'Se entregó en la portería.',
            'direccion_envio' => 'Cra 14 #11-93',
        ])->assertOk();

        $orden->refresh();
        $this->assertSame('Se entregó en la portería.', $orden->notas);
        $this->assertSame('Cra 14 #11-93', $orden->direccion_envio);
    }

    /** Corregir un papel no puede mover el total ni, con él, la comisión. */
    public function test_corregir_la_foto_no_toca_el_total(): void
    {
        [$orden] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'factura_foto_url' => 'https://x.example/nitida.jpg',
        ])->assertOk();

        $this->assertEquals(800000, $orden->fresh()->valor_total);
    }

    /** Una que va en camino se corrige igual: el papel se arregla antes. */
    public function test_tambien_sirve_para_una_orden_en_camino(): void
    {
        [$orden] = $this->ordenEntregada('en_camino');

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'anexo_foto_url' => 'https://x.example/anexo.jpg',
        ])->assertOk();

        $this->assertSame('https://x.example/anexo.jpg', $orden->fresh()->anexo_foto_url);
    }

    // ── Lo que no ─────────────────────────────────────────────────────────────

    public function test_no_se_le_cambia_el_precio_a_lo_ya_entregado(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'items' => [['id' => $mesa->id, 'precio_unitario' => 10]],
        ])->assertStatus(422);

        $this->assertEquals(800000, $mesa->fresh()->precio_unitario);
        $this->assertEquals(800000, $orden->fresh()->valor_total);
    }

    public function test_no_se_le_cambia_la_cantidad(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'items' => [['id' => $mesa->id, 'cantidad' => 9]],
        ])->assertStatus(422);

        $this->assertSame(1, $mesa->fresh()->cantidad);
    }

    public function test_no_se_le_agrega_ni_se_le_quita_un_producto(): void
    {
        [$orden, $mesa] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'items_nuevos' => [['producto_id' => 5, 'cantidad' => 1, 'precio_unitario' => 100]],
        ])->assertStatus(422);

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'items_eliminar' => [$mesa->id],
        ])->assertStatus(422);

        $this->assertSame(1, OrdenItem::where('orden_id', $orden->id)->count());
    }

    public function test_no_se_le_mete_un_descuento_despues(): void
    {
        [$orden] = $this->ordenEntregada();

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'descuento_total' => 500000,
        ])->assertStatus(422);

        $this->assertEquals(0, $orden->fresh()->descuento_total);
    }

    /** Una orden cancelada sigue sin tocarse por ningún lado. */
    public function test_una_cancelada_no_se_edita(): void
    {
        [$orden] = $this->ordenEntregada('cancelado');

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'factura_foto_url' => 'https://x.example/nitida.jpg',
        ])->assertStatus(422);
    }

    /** Y una abierta se sigue editando entera, como siempre. */
    public function test_una_orden_en_produccion_se_edita_completa(): void
    {
        [$orden, $mesa] = $this->ordenEntregada('en_produccion');

        $this->actingAs($this->jefe())->patchJson("/api/ordenes/{$orden->id}", [
            'items' => [['id' => $mesa->id, 'precio_unitario' => 900000]],
        ])->assertOk();

        $this->assertEquals(900000, $mesa->fresh()->precio_unitario);
    }
}
