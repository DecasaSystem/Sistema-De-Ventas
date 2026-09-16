<?php

namespace Tests\Feature;

use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Desmarcar una orden "lista para entregar" para poder editarla.
 *
 * El caso real: se vendió un escritorio que estaba en la fábrica, la orden
 * quedó lista para entregar, y el cliente cambió de opinión: ahora quiere
 * uno a la medida que hay que fabricar. El escritorio apartado se fue al
 * almacén de exhibición. Estando lista, la orden no dejaba ni cambiar de
 * estado ni tocar productos, así que no había forma de arreglarlo.
 *
 * Lo que se comprueba: que el supervisor la devuelva a espera (y a
 * "en producción" si tiene una pieza abierta), que de ahí se pueda editar
 * —quitar el escritorio suelta su reserva, agregar el diseño le crea
 * producción—, y que NO se pueda si alguien ya la está despachando.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class DevolverAEsperaTest extends TestCase
{
    private const ESCRITORIO = 7;
    private const FABRICA    = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('facturacion')->default(false); $t->boolean('acceso_entregas')->default(false);
            $t->boolean('notif_asignar_fecha')->default(false);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable();
            $t->decimal('precio_base', 12, 2)->default(0); $t->boolean('activo')->default(true);
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('canal')->nullable();
            $t->string('tipo')->default('venta'); $t->string('estado')->default('pendiente_anticipo');
            $t->timestamp('listo_entrega_at')->nullable(); $t->boolean('entrega_inmediata')->default(false);
            $t->decimal('valor_total', 15, 2)->default(0); $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 5, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->decimal('anticipo_pct', 5, 2)->default(0); $t->text('notas')->nullable();
            $t->date('fecha_sugerida_vendedor')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('factura_foto_url')->nullable(); $t->string('firma_url')->nullable();
            $t->string('anexo_foto_url')->nullable();
            $t->string('departamento_envio')->nullable(); $t->string('ciudad_envio')->nullable();
            $t->string('direccion_envio')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('serie')->nullable(); $t->string('motivo_serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('grupo_secuencia')->nullable(); $t->string('numero_anulado', 30)->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->string('variante_detalle')->nullable(); $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 15, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('retapizar')->default(false);
            $t->boolean('es_regalo')->default(false); $t->boolean('usa_stock_tienda')->default(false);
            $t->json('specs_personalizacion')->nullable(); $t->string('boceto_url')->nullable();
            $t->json('boceto_fotos')->nullable(); $t->date('fecha_entrega_prom')->nullable();
            $t->date('devuelto_en')->nullable(); $t->text('motivo_devolucion')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal'); $t->unsignedTinyInteger('orden')->default(1);
            $t->string('estado')->default('pendiente'); $t->timestamp('iniciado_at')->nullable();
            $t->timestamp('completado_at')->nullable(); $t->unsignedBigInteger('completado_por')->nullable();
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable();
            $t->text('notas')->nullable(); $t->timestamps();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1); $t->string('descripcion')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(1);
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
            $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
            $t->boolean('es_covendedor')->default(false); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('consultas_costo', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('asignado_a_id')->nullable();
            $t->string('estado')->default('pendiente'); $t->timestamp('respondido_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 15, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->completarEsquemaDeEntregas();

        DB::table('tiendas')->insert([
            ['id' => 1,             'nombre' => 'Decasa Norte', 'es_fabrica' => false],
            ['id' => self::FABRICA, 'nombre' => 'Fábrica',      'es_fabrica' => true],
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => self::ESCRITORIO, 'nombre' => 'ESCRITORIO OSLO', 'categoria' => 'escritorios', 'precio_base' => 900000]);
        // El escritorio está en la fábrica y la orden lo tiene apartado.
        DB::table('inventario')->insert(['producto_id' => self::ESCRITORIO, 'tienda_id' => self::FABRICA, 'cantidad_disponible' => 1, 'cantidad_reservada' => 1]);
    }

    private function jefe(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create(['nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
                                'tienda_default_id' => 1, 'created_at' => now()]);
    }

    /** La venta del escritorio de fábrica, ya marcada lista para entregar. */
    private function ordenLista(): Orden
    {
        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 99, 'canal' => 'fisica',
            'estado' => 'listo_entrega', 'listo_entrega_at' => now(), 'valor_total' => 900000, 'numero_orden' => 4300,
        ]);
        OrdenItem::create([
            'orden_id' => $orden->id, 'producto_id' => self::ESCRITORIO, 'tienda_origen_id' => self::FABRICA,
            'cantidad' => 1, 'precio_unitario' => 900000, 'es_personalizado' => false,
        ]);
        return $orden;
    }

    private function reservadoEnFabrica(): int
    {
        return (int) DB::table('inventario')->where('producto_id', self::ESCRITORIO)
            ->where('tienda_id', self::FABRICA)->value('cantidad_reservada');
    }

    public function test_el_supervisor_la_devuelve_a_espera_y_ya_se_puede_editar(): void
    {
        $orden = $this->ordenLista();
        $jefe  = $this->jefe();

        $this->actingAs($jefe)
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'pendiente_anticipo'])
            ->assertOk();

        $orden->refresh();
        $this->assertSame('pendiente_anticipo', $orden->estado);
        $this->assertNull($orden->listo_entrega_at);
        // Sigue apartado: devolverla a espera no toca inventario.
        $this->assertSame(1, $this->reservadoEnFabrica());
        // Al vendedor se le avisa que ya no está lista.
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', 99)->count());

        // Ahora sí: fuera el escritorio, entra el diseño a la medida.
        $escritorio = OrdenItem::first();
        $this->actingAs($jefe)
            ->patchJson("/api/ordenes/{$orden->id}", [
                'items_eliminar' => [$escritorio->id],
                'items_nuevos'   => [[
                    'nombre_custom' => 'Escritorio en L a la medida', 'categoria_custom' => 'escritorios',
                    'cantidad' => 1, 'precio_unitario' => 1500000,
                    'specs_personalizacion' => ['medidas' => '1.80 × 1.20'],
                ]],
            ])
            ->assertOk();

        // El escritorio de fábrica quedó libre para venderlo (o llevarlo a
        // exhibición), y el nuevo ya le cayó al taller.
        $this->assertSame(0, $this->reservadoEnFabrica());
        $this->assertSame(1, OrdenItem::count());
        $this->assertSame('diseno_especial', OrdenItem::first()->tipo_item);
        $this->assertSame(1, Produccion::where('orden_item_id', OrdenItem::first()->id)->count());
        $this->assertEquals(1500000, (float) $orden->fresh()->valor_total);
    }

    public function test_con_una_pieza_abierta_en_el_taller_vuelve_a_en_produccion(): void
    {
        $orden = $this->ordenLista();
        // Además del escritorio, un mueble que se fabrica y todavía no está.
        $item = OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Mesa a la medida',
                                   'cantidad' => 1, 'precio_unitario' => 500000, 'es_personalizado' => true]);
        Produccion::create(['orden_item_id' => $item->id, 'estado' => 'en_proceso', 'fecha_inicio' => now()->toDateString()]);

        // Se pide "espera" pero el taller manda: queda en producción.
        $this->actingAs($this->jefe())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'pendiente_anticipo'])
            ->assertOk();

        $this->assertSame('en_produccion', $orden->fresh()->estado);
        // Y la pieza sigue como estaba: desmarcar la orden no toca el taller.
        $this->assertSame('en_proceso', Produccion::first()->estado);
    }

    public function test_si_ya_va_en_una_ruta_no_se_puede(): void
    {
        $orden = $this->ordenLista();
        $ruta  = Despacho::create(['estado' => 'asignado', 'tipo' => 'ruta', 'fecha_despacho' => now()->toDateString()]);
        DespachoItem::create(['despacho_id' => $ruta->id, 'orden_id' => $orden->id, 'posicion' => 1, 'estado' => 'pendiente']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'pendiente_anticipo'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Esta orden ya está en una ruta o en una entrega en curso. Sácala de ahí en Despacho antes de devolverla a espera.']);

        $this->assertSame('listo_entrega', $orden->fresh()->estado);
    }

    public function test_una_ruta_ya_cerrada_no_la_bloquea(): void
    {
        $orden = $this->ordenLista();
        // Salió en una ruta que se completó sin entregarla: esa fila vieja no
        // la está despachando nadie.
        $ruta = Despacho::create(['estado' => 'completado', 'tipo' => 'ruta', 'fecha_despacho' => now()->toDateString()]);
        DespachoItem::create(['despacho_id' => $ruta->id, 'orden_id' => $orden->id, 'posicion' => 1, 'estado' => 'pendiente']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'pendiente_anticipo'])
            ->assertOk();

        $this->assertSame('pendiente_anticipo', $orden->fresh()->estado);
    }

    public function test_el_vendedor_no_puede_y_desde_lista_no_se_va_a_otro_lado(): void
    {
        $orden = $this->ordenLista();

        $this->actingAs($this->vendedor())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'pendiente_anticipo'])
            ->assertForbidden();

        // Cancelar o entregar una lista sigue siendo cosa de Despacho.
        $this->actingAs($this->jefe())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'cancelado'])
            ->assertForbidden();

        $this->assertSame('listo_entrega', $orden->fresh()->estado);
    }

    public function test_en_camino_sigue_bloqueada(): void
    {
        $orden = $this->ordenLista();
        $orden->update(['estado' => 'en_camino']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/ordenes/{$orden->id}/estado", ['estado' => 'pendiente_anticipo'])
            ->assertForbidden();
    }
}
