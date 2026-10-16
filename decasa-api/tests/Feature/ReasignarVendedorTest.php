<?php

namespace Tests\Feature;

use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cambiarle el vendedor a una orden (Editar orden → Reasignar, solo
 * supervisor) rehace sus comisiones con el dueño correcto.
 *
 * El caso real (2026-10-16): la FV2-16 se subió en la cuenta de Paola y era
 * de Martha, las dos de Norte. Pero el mismo botón se usa entre tiendas, con
 * independientes y con ventas compartidas, y en cada caso la comisión tiene
 * que quedar donde las reglas de §2 de MEMORY.md dicen.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReasignarVendedorTest extends TestCase
{
    private const NORTE = 1;
    private const UNICENTRO = 4;
    private const INDEP = 8;

    private const PAOLA = 16;
    private const MARTHA = 29;
    private const JUAN = 30;      // Unicentro
    private const HENRY = 40;     // independiente
    private const JEFA = 50;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('facturacion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(true);
            $t->boolean('es_independientes')->default(false); $t->boolean('es_fabrica')->default(false);
            $t->boolean('activa')->default(true); $t->date('cerrada_en')->nullable();
            $t->string('comision_periodicidad')->default('mensual');
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_vendedor_id')->nullable(); $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('en_produccion'); $t->string('tipo')->default('venta');
            $t->decimal('valor_total', 12, 2)->default(0);
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
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes', 7); $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable(); $t->string('nota')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'es_independientes' => false],
            ['id' => self::UNICENTRO, 'nombre' => 'Decasa Unicentro Pereira', 'es_independientes' => false],
            ['id' => self::INDEP, 'nombre' => 'Independientes', 'es_independientes' => true],
        ]);
        foreach ([
            [self::PAOLA, 'Paola Andrea', self::NORTE, false, 'vendedor'],
            [self::MARTHA, 'Martha', self::NORTE, false, 'vendedor'],
            [self::JUAN, 'Juan', self::UNICENTRO, false, 'vendedor'],
            [self::HENRY, 'Henry', self::INDEP, true, 'vendedor'],
            [self::JEFA, 'Jefa', null, false, 'supervisor'],
        ] as [$id, $nombre, $tienda, $indep, $rol]) {
            DB::table('usuarios')->insert(['id' => $id, 'nombre' => $nombre, 'email' => "u{$id}@d.com", 'password' => 'x',
                'rol' => $rol, 'tienda_default_id' => $tienda, 'independiente' => $indep, 'created_at' => now()]);
        }
        $mes = now('America/Bogota')->format('Y-m');
        DB::table('tienda_asesores_comision')->insert([
            ['tienda_id' => self::NORTE, 'mes' => $mes, 'vendedor_id' => self::PAOLA],
            ['tienda_id' => self::NORTE, 'mes' => $mes, 'vendedor_id' => self::MARTHA],
            ['tienda_id' => self::UNICENTRO, 'mes' => $mes, 'vendedor_id' => self::JUAN],
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Sofá']);
    }

    /** Una venta de $1.340.000 hecha en Norte, con su comisión pendiente. */
    private function orden(int $vendedor, array $extra = []): Orden
    {
        $orden = Orden::create(array_merge([
            'cliente_id' => 1, 'tienda_id' => self::NORTE, 'vendedor_id' => $vendedor,
            'valor_total' => 1340000, 'serie' => 'FV2', 'serie_numero' => 16, 'canal' => 'fisica',
        ], $extra));
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'tienda_origen_id' => self::NORTE,
                           'cantidad' => 1, 'precio_unitario' => 1340000]);
        \App\Http\Controllers\ComisionController::crearParaOrden($orden->fresh());

        return $orden;
    }

    private function reasignar(Orden $orden, array $datos)
    {
        return $this->actingAs(Usuario::find(self::JEFA))->patchJson("/api/ordenes/{$orden->id}", $datos);
    }

    /** [vendedor_id => [tienda_id, origen]] de las comisiones de la orden. */
    private function comisiones(Orden $orden): array
    {
        return Comision::where('orden_id', $orden->id)->orderBy('vendedor_id')->get()
            ->mapWithKeys(fn ($c) => [(int) $c->vendedor_id => [(int) $c->tienda_id, $c->origen]])->all();
    }

    public function test_misma_tienda_la_comision_pasa_a_la_nueva_vendedora(): void
    {
        $orden = $this->orden(self::PAOLA);

        $this->reasignar($orden, ['vendedor_id' => self::MARTHA])->assertOk();

        $this->assertSame([self::MARTHA => [self::NORTE, 'venta']], $this->comisiones($orden));
        $this->assertSame(self::NORTE, (int) $orden->fresh()->tienda_vendedor_id);
        $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', $orden->id)->count(), 'queda en el historial');
    }

    public function test_a_alguien_de_otra_tienda_la_orden_pasa_a_su_tienda(): void
    {
        // Dueño, 2026-10-16: si el vendedor nuevo tiene tienda, la orden se va
        // con él. Antes la de mostrador se quedaba contando para Norte.
        $orden = $this->orden(self::PAOLA);

        $this->reasignar($orden, ['vendedor_id' => self::JUAN])->assertOk();

        $this->assertSame(self::UNICENTRO, (int) $orden->fresh()->tienda_id);
        $this->assertSame([self::JUAN => [self::UNICENTRO, 'venta']], $this->comisiones($orden));
    }

    public function test_si_el_supervisor_escoge_otra_tienda_manda_la_suya(): void
    {
        $orden = $this->orden(self::PAOLA);

        $this->reasignar($orden, ['vendedor_id' => self::JUAN, 'tienda_id' => self::INDEP])->assertOk();

        $this->assertSame(self::INDEP, (int) $orden->fresh()->tienda_id);
    }

    public function test_el_mueble_sigue_saliendo_de_la_tienda_vieja(): void
    {
        // Cambia de quién es la venta, no dónde está el sofá: lo apartado en
        // Norte se queda apartado en Norte y de ahí se descuenta al entregar.
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => self::NORTE, 'vendedor_id' => self::PAOLA,
                                'valor_total' => 1340000, 'canal' => 'fisica']);
        $item = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1, 'precio_unitario' => 1340000]);
        DB::table('inventario')->insert([
            ['producto_id' => 5, 'tienda_id' => self::NORTE, 'cantidad_disponible' => 3, 'cantidad_reservada' => 1],
            ['producto_id' => 5, 'tienda_id' => self::UNICENTRO, 'cantidad_disponible' => 2, 'cantidad_reservada' => 0],
        ]);
        \App\Http\Controllers\ComisionController::crearParaOrden($orden->fresh());

        $this->reasignar($orden, ['vendedor_id' => self::JUAN])->assertOk();

        $this->assertSame(self::UNICENTRO, (int) $orden->fresh()->tienda_id);
        $this->assertSame(self::NORTE, (int) $item->fresh()->tienda_origen_id);
        $this->assertSame(1, (int) DB::table('inventario')->where('tienda_id', self::NORTE)->value('cantidad_reservada'));
        $this->assertSame(0, (int) DB::table('inventario')->where('tienda_id', self::UNICENTRO)->value('cantidad_reservada'));
        $this->assertSame(0, DB::table('inventario_movimientos')->count());
    }

    public function test_restauracion_de_un_independiente_entregada_pasa_a_la_tienda_y_se_reparte(): void
    {
        // El caso de la R-1093: la subió Flabio (independiente) y era de
        // Gladys, de El Edén, donde las restauraciones se parten entre el
        // equipo. Ya entregada: el selector de tienda ni se ve.
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => self::INDEP, 'vendedor_id' => self::HENRY,
                                'valor_total' => 1300000, 'serie' => 'R', 'serie_numero' => 1093,
                                'canal' => 'fisica', 'estado' => 'entregado']);
        OrdenItem::create(['orden_id' => $orden->id, 'es_restauracion' => true, 'nombre_custom' => 'Sala',
                           'cantidad' => 1, 'precio_unitario' => 1300000]);
        \App\Http\Controllers\ComisionController::crearParaOrden($orden->fresh());

        $this->reasignar($orden, ['vendedor_id' => self::PAOLA])->assertOk();

        $this->assertSame(self::NORTE, (int) $orden->fresh()->tienda_id);
        $this->assertSame([
            self::PAOLA  => [self::NORTE, 'restauracion_equipo'],
            self::MARTHA => [self::NORTE, 'restauracion_equipo'],
        ], $this->comisiones($orden));
        $this->assertEquals([650000, 650000],
            Comision::where('orden_id', $orden->id)->orderBy('vendedor_id')->pluck('valor_orden')->map(fn ($v) => (float) $v)->all());
    }

    public function test_venta_digital_a_alguien_de_otra_tienda_se_va_a_la_tienda_de_el(): void
    {
        $orden = $this->orden(self::PAOLA, ['canal' => 'whatsapp']);

        $this->reasignar($orden, ['vendedor_id' => self::JUAN])->assertOk();

        $this->assertSame(self::UNICENTRO, (int) $orden->fresh()->tienda_vendedor_id);
        $this->assertSame([self::JUAN => [self::UNICENTRO, 'venta']], $this->comisiones($orden));
    }

    public function test_a_un_independiente_la_comision_es_suya(): void
    {
        $orden = $this->orden(self::PAOLA);

        $this->reasignar($orden, ['vendedor_id' => self::HENRY])->assertOk();

        $this->assertSame([self::HENRY], array_keys($this->comisiones($orden)));
        $this->assertSame(self::INDEP, (int) $orden->fresh()->tienda_id, 'se va con él a Independientes');
    }

    public function test_de_un_independiente_que_compartia_con_un_almacen_a_uno_de_tienda_quita_el_reparto(): void
    {
        // Henry compartió la venta con Unicentro: la gente de Unicentro tiene su
        // pedazo del 5% (abono_almacen). Si la venta en realidad era de Martha,
        // ese reparto ya no aplica: solo un independiente comparte con un almacén.
        $orden = $this->orden(self::HENRY, ['tienda_abonada_id' => self::UNICENTRO]);
        $this->assertSame([self::JUAN => [self::UNICENTRO, 'abono_almacen'], self::HENRY => [self::NORTE, 'venta']],
            $this->comisiones($orden));

        $this->reasignar($orden, ['vendedor_id' => self::MARTHA])->assertOk();

        $this->assertNull($orden->fresh()->tienda_abonada_id);
        $this->assertSame([self::MARTHA => [self::NORTE, 'venta']], $this->comisiones($orden));
    }

    public function test_venta_compartida_cada_uno_queda_con_su_fila(): void
    {
        $orden = $this->orden(self::PAOLA, ['es_compartida' => true, 'covendedor_id' => self::JUAN]);

        $this->reasignar($orden, ['vendedor_id' => self::MARTHA])->assertOk();

        $this->assertSame([self::MARTHA => [self::NORTE, 'venta'], self::JUAN => [self::UNICENTRO, 'venta']],
            $this->comisiones($orden));
    }

    public function test_no_se_puede_poner_de_vendedor_al_mismo_covendedor(): void
    {
        // Quedaría una sola fila y la venta compartida con nadie.
        $orden = $this->orden(self::PAOLA, ['es_compartida' => true, 'covendedor_id' => self::MARTHA]);

        $this->reasignar($orden, ['vendedor_id' => self::MARTHA])->assertStatus(422);

        $this->assertSame(self::PAOLA, (int) $orden->fresh()->vendedor_id);
    }

    public function test_con_la_comision_ya_pagada_no_deja_cambiar(): void
    {
        $orden = $this->orden(self::PAOLA);
        Comision::where('orden_id', $orden->id)->update(['estado' => 'pagada']);

        $this->reasignar($orden, ['vendedor_id' => self::MARTHA])->assertStatus(422);

        $this->assertSame([self::PAOLA => [self::NORTE, 'venta']], $this->comisiones($orden));
    }
}
