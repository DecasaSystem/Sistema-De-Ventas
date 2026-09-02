<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cancelar una pieza tiene que sacarla del taller de verdad.
 *
 * El caso real: se marcó "para fabricar" un producto que estaba en la tienda,
 * el taller lo arrancó, se cancela al darse cuenta... y el paso seguía en la
 * lista del ebanista, que lo podía avanzar. Además el ítem no se podía quitar
 * de la orden, porque al editar se bloquea "su producción ya está en curso".
 * La pieza quedaba atrapada por los dos lados.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CancelarProduccionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('apto_produccion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('en_produccion');
            $t->decimal('valor_total', 12, 2)->default(0); $t->decimal('descuento_total', 12, 2)->default(0);
            $t->decimal('descuento_condicionado', 12, 2)->default(0); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(true);
            $t->boolean('es_restauracion')->default(false);
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable();
            $t->text('motivo_devolucion')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal');
            $t->unsignedTinyInteger('orden')->default(1); $t->string('estado')->default('pendiente');
            $t->timestamp('iniciado_at')->nullable(); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->decimal('horas', 8, 2)->nullable();
            $t->unsignedTinyInteger('calidad')->nullable(); $t->text('notas')->nullable(); $t->timestamps();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1); $t->string('descripcion')->nullable();
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('asignado_por')->nullable(); $t->timestamp('asignado_at')->nullable();
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
            $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
            $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_fijadas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 12, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    /** La pieza que se mando a fabricar por error, ya arrancada en el taller. */
    private function piezaEnProceso(): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 9, 'nombre' => 'Consola alistonada 2.25', 'categoria' => 'consolas']);
        DB::table('tipos_proceso')->insert([
            ['id' => 1, 'clave' => 'ebanisteria', 'nombre' => 'Ebanistería', 'activo' => true, 'orden' => 1],
        ]);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'en_produccion', 'valor_total' => 1200000, 'numero_orden' => 4293]);

        // Dos ítems: el que se manda a fabricar por error y otro cualquiera,
        // porque la orden no puede quedarse sin ninguno.
        $item = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 9, 'tienda_origen_id' => 1,
                                   'cantidad' => 1, 'precio_unitario' => 900000, 'es_personalizado' => true]);
        OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Otro mueble', 'cantidad' => 1,
                           'precio_unitario' => 300000, 'es_personalizado' => true]);

        $prod = Produccion::create(['orden_item_id' => $item->id, 'estado' => 'en_proceso',
                                    'fecha_inicio' => now()->toDateString()]);

        // El flujo ya armado: ebanistería arrancó, lo demás espera.
        $paso = ProduccionPaso::create(['produccion_id' => $prod->id, 'tipo_proceso' => 'ebanisteria',
                                        'orden' => 1, 'estado' => 'en_proceso', 'iniciado_at' => now()]);
        ProduccionPaso::create(['produccion_id' => $prod->id, 'tipo_proceso' => 'despacho',
                                'orden' => 2, 'estado' => 'pendiente']);

        return [$orden, $item, $prod, $paso];
    }

    private function jefe(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
                                'gestiona_produccion' => true, 'acceso_produccion' => true, 'created_at' => now()]);
    }

    public function test_cancelar_una_pieza_le_cancela_los_pasos(): void
    {
        [, , $prod, $paso] = $this->piezaEnProceso();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}", ['estado' => 'cancelado'])
            ->assertOk();

        // El paso que estaba en curso deja de estarlo: si no, sigue saliendo en
        // la lista del ebanista y lo puede avanzar.
        $this->assertSame('cancelado', $paso->fresh()->estado);
        $this->assertSame(0, ProduccionPaso::where('produccion_id', $prod->id)
            ->whereIn('estado', ['pendiente', 'en_proceso'])->count());
    }

    /** El ebanista de verdad: es el que tenía el paso en su lista. */
    private function ebanista(): Usuario
    {
        $u = Usuario::create(['nombre' => 'Adrián', 'email' => 'a@d.com', 'password' => 'x', 'rol' => 'trabajador',
                              'apto_produccion' => true, 'created_at' => now()]);
        DB::table('proceso_trabajadores')->insert(['usuario_id' => $u->id, 'tipo_proceso_id' => 1]);

        return $u;
    }

    public function test_el_paso_cancelado_desaparece_de_mis_pasos(): void
    {
        [, , $prod, $paso] = $this->piezaEnProceso();
        $ebanista = $this->ebanista();

        // Antes de cancelar lo tiene en su lista: el taller ya lo arrancó.
        $antes = $this->actingAs($ebanista)->getJson('/api/produccion/mis-pasos')->assertOk()->json();
        $this->assertContains($paso->id, collect($antes)->pluck('id')->all());

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}", ['estado' => 'cancelado'])->assertOk();

        $despues = $this->actingAs($ebanista)->getJson('/api/produccion/mis-pasos')->assertOk()->json();
        $this->assertNotContains($paso->id, collect($despues)->pluck('id')->all());
    }

    public function test_un_paso_cancelado_no_se_puede_completar(): void
    {
        [, , $prod, $paso] = $this->piezaEnProceso();
        $ebanista = $this->ebanista();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}", ['estado' => 'cancelado'])->assertOk();

        // Aunque tuviera la pantalla abierta de antes de que se cancelara.
        $this->actingAs($ebanista)
            ->patchJson("/api/produccion/pasos/{$paso->id}/completar", [
                'participantes' => [['usuario_id' => $ebanista->id, 'horas' => 2]],
            ])
            ->assertStatus(422);

        $this->assertSame('cancelado', $paso->fresh()->estado);
    }

    /**
     * Los pasos que quedaron vivos de antes del arreglo: la migración los
     * corrige, pero si alguno se escapa, completarlo tiene que seguir fallando.
     */
    public function test_ni_un_paso_vivo_de_una_pieza_cancelada(): void
    {
        [, , $prod, $paso] = $this->piezaEnProceso();
        $ebanista = $this->ebanista();

        $prod->update(['estado' => 'cancelado']);          // sin pasar por el controlador
        $paso->update(['estado' => 'en_proceso']);         // como estaban los datos viejos

        $this->actingAs($ebanista)
            ->patchJson("/api/produccion/pasos/{$paso->id}/completar", [
                'participantes' => [['usuario_id' => $ebanista->id, 'horas' => 2]],
            ])
            ->assertStatus(422);

        $this->assertSame('en_proceso', $paso->fresh()->estado);
    }

    public function test_tras_cancelar_el_item_si_se_puede_quitar_de_la_orden(): void
    {
        [$orden, $item, $prod] = $this->piezaEnProceso();
        $jefe = $this->jefe();

        // Antes de cancelar está bloqueado: la producción va en curso de verdad.
        $this->actingAs($jefe)
            ->patchJson("/api/ordenes/{$orden->id}", ['items_eliminar' => [$item->id]])
            ->assertStatus(422);

        $this->actingAs($jefe)->patchJson("/api/produccion/{$prod->id}", ['estado' => 'cancelado'])->assertOk();

        // Ya cancelada, quitarlo es justo lo que hay que poder hacer: ese
        // producto no había que fabricarlo.
        $this->actingAs($jefe)
            ->patchJson("/api/ordenes/{$orden->id}", ['items_eliminar' => [$item->id]])
            ->assertOk();

        $this->assertNull(OrdenItem::find($item->id));
        $this->assertNull(Produccion::find($prod->id));
        $this->assertSame(0, ProduccionPaso::where('produccion_id', $prod->id)->count());
    }
}
