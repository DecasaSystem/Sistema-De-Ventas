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
 * Reasignarle la tienda a una orden se lleva lo que tenía apartado.
 *
 * Antes solo se movía el campo: el apartado se quedaba en la tienda de antes.
 * Quedaban dos mentiras a la vez — en la tienda vieja un apartado que ninguna
 * orden sostiene (el "dice apartado y al entrar no hay nada" de inventario) y
 * en la nueva una unidad comprometida que el contador no conocía, libre para
 * vendérsela a otro cliente.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CambiarLaTiendaMudaLoApartadoTest extends TestCase
{
    private const NORTE = 1, SUR = 2, MESA = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->boolean('es_compartida')->default(false);
            $t->string('canal')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->decimal('descuento_total', 12, 2)->default(0);
            $t->decimal('descuento_condicionado', 12, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 5, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->string('notas')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('es_restauracion')->default(false);
            $t->date('devuelto_en')->nullable();
            $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo')->nullable();
            $t->string('titulo')->nullable(); $t->text('mensaje')->nullable();
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable();
            $t->timestamp('leida_at')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->string('estado')->default('pendiente');
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable();
            $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
            $t->string('tipo')->nullable(); $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->json('cambios'); $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte'],
            ['id' => self::SUR,   'nombre' => 'Decasa Sur'],
        ]);
        DB::table('productos')->insert(['id' => self::MESA, 'nombre' => 'Mesa']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventario')->insert([
            ['producto_id' => self::MESA, 'tienda_id' => self::NORTE, 'cantidad_disponible' => 3, 'cantidad_reservada' => 1],
            ['producto_id' => self::MESA, 'tienda_id' => self::SUR,   'cantidad_disponible' => 4, 'cantidad_reservada' => 0],
        ]);
    }

    private function supervisor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Sup', 'email' => 'sup@d.com', 'password' => 'x',
            'rol' => 'supervisor', 'firma_url' => 'firma.png', 'created_at' => now(),
        ]);
    }

    /** Una orden viva de Norte con una mesa apartada ahí. */
    private function ordenEnNorte(array $extra = [], array $itemExtra = []): Orden
    {
        $orden = Orden::create(array_merge([
            'cliente_id' => 1, 'tienda_id' => self::NORTE, 'vendedor_id' => 1,
            'estado' => 'pendiente_anticipo', 'valor_total' => 100000, 'canal' => 'fisica',
        ], $extra));

        OrdenItem::create(array_merge([
            'orden_id' => $orden->id, 'producto_id' => self::MESA, 'cantidad' => 1,
            'precio_unitario' => 100000, 'es_personalizado' => false, 'producto_unico' => false,
        ], $itemExtra));

        return $orden->fresh();
    }

    private function reservado(int $tienda): int
    {
        return (int) DB::table('inventario')
            ->where('producto_id', self::MESA)->where('tienda_id', $tienda)->value('cantidad_reservada');
    }

    private function cambiarTienda(Orden $orden, int $tienda)
    {
        return $this->actingAs($this->supervisor())
            ->patchJson("/api/ordenes/{$orden->id}", ['tienda_id' => $tienda]);
    }

    public function test_lo_apartado_se_va_con_la_orden_a_la_tienda_nueva(): void
    {
        $orden = $this->ordenEnNorte();

        $this->cambiarTienda($orden, self::SUR)->assertOk();

        $this->assertSame(0, $this->reservado(self::NORTE), 'Norte ya no lo tiene apartado');
        $this->assertSame(1, $this->reservado(self::SUR), 'Sur sí');
        $this->assertSame(self::SUR, (int) $orden->fresh()->tienda_id);
    }

    public function test_queda_anotado_en_el_historial_del_inventario(): void
    {
        $orden = $this->ordenEnNorte();

        $this->cambiarTienda($orden, self::SUR)->assertOk();

        $movs = DB::table('inventario_movimientos')->where('motivo', 'like', '%cambio de tienda%')->get();
        $this->assertCount(2, $movs, 'la liberación y la reserva, cada una en su tienda');
        $this->assertSame('liberacion', $movs->firstWhere('tienda_id', self::NORTE)->tipo);
        $this->assertSame('reserva',    $movs->firstWhere('tienda_id', self::SUR)->tipo);
    }

    /**
     * Un ítem que viaja desde otra sede ya tiene su propia tienda de origen:
     * su reserva vive allá y cambiar la tienda de la orden no la mueve.
     */
    public function test_un_item_que_viene_de_otra_sede_no_se_mueve(): void
    {
        $orden = $this->ordenEnNorte([], ['tienda_origen_id' => self::NORTE]);
        // La reserva de ese ítem está en Norte por ser su origen, no por ser
        // la tienda de la orden.
        $this->cambiarTienda($orden, self::SUR)->assertOk();

        $this->assertSame(1, $this->reservado(self::NORTE), 'se queda donde está');
        $this->assertSame(0, $this->reservado(self::SUR));
    }

    /** Un borrador no ha apartado nada todavía: no hay qué mudar. */
    public function test_un_borrador_no_mueve_contadores(): void
    {
        DB::table('inventario')->where('tienda_id', self::NORTE)->update(['cantidad_reservada' => 0]);
        $orden = $this->ordenEnNorte(['estado' => 'borrador']);

        $this->cambiarTienda($orden, self::SUR)->assertOk();

        $this->assertSame(0, $this->reservado(self::NORTE));
        $this->assertSame(0, $this->reservado(self::SUR), 'no se aparta lo que nunca se apartó');
    }

    /** Un ítem devuelto para cambiarlo ya soltó su reserva: no se muda. */
    public function test_un_item_devuelto_no_se_muda(): void
    {
        $orden = $this->ordenEnNorte([], ['devuelto_en' => now()->toDateString()]);

        $this->cambiarTienda($orden, self::SUR)->assertOk();

        $this->assertSame(0, $this->reservado(self::SUR));
    }
}
