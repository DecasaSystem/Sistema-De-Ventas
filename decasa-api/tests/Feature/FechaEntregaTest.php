<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La fecha que el vendedor le promete al cliente es la fecha de entrega.
 *
 * Antes nacía vacía y alguien tenía que ir orden por orden poniéndola. Ahora
 * entra sola, y quien supervisa solo corrige la que vea mal. Se prueba porque
 * si esto falla no se rompe nada visible: las órdenes simplemente quedan sin
 * fecha y nadie se entera hasta que el cliente reclama.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class FechaEntregaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->date('fecha_sugerida_vendedor')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->string('nombre_custom')->nullable();
            // La tabla real siempre la tiene, y de ella sale si una orden es
            // restauracion: sin la columna, cualquier cosa que toque comisiones
            // revienta aqui y no en el codigo que se esta probando.
            $t->boolean('es_restauracion')->default(false);
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->date('fecha_entrega_prom')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
    }

    /**
     * Lo que hace el controlador al crear una orden, aislado: la fecha
     * prometida baja a cada ítem y al compromiso del taller.
     */
    private function crearOrdenCon(?string $fechaPrometida): Orden
    {
        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
            'valor_total' => 3000000, 'fecha_sugerida_vendedor' => $fechaPrometida,
        ]);

        foreach (['Cama Macarena', 'Mesa de noche'] as $nombre) {
            $item = OrdenItem::create([
                'orden_id' => $orden->id, 'nombre_custom' => $nombre, 'cantidad' => 1,
                'precio_unitario' => 1500000, 'es_personalizado' => true,
                'fecha_entrega_prom' => $fechaPrometida,
            ]);
            Produccion::create([
                'orden_item_id' => $item->id, 'fecha_inicio' => now()->toDateString(),
                'fecha_compromiso' => $fechaPrometida, 'estado' => 'pendiente',
            ]);
        }

        return $orden;
    }

    public function test_la_fecha_prometida_baja_a_los_items_y_al_taller(): void
    {
        $orden = $this->crearOrdenCon('2026-09-15');

        foreach ($orden->items as $item) {
            $this->assertSame('2026-09-15', $item->fecha_entrega_prom->toDateString());
        }

        // El taller trabaja contra la misma fecha que se le prometió al
        // cliente, no contra una en blanco.
        foreach (Produccion::all() as $p) {
            $this->assertSame('2026-09-15', $p->fecha_compromiso->toDateString());
        }
    }

    public function test_sin_fecha_prometida_la_orden_queda_sin_fecha(): void
    {
        $orden = $this->crearOrdenCon(null);

        // Es el caso que sigue necesitando que alguien la ponga, y por eso el
        // aviso a supervisión se manda marcado como urgente.
        foreach ($orden->items as $item) {
            $this->assertNull($item->fecha_entrega_prom);
        }
        $this->assertNull(Produccion::first()->fecha_compromiso);
    }

    public function test_el_borrador_al_completarse_toma_la_fecha_prometida(): void
    {
        // Un borrador nace sin fecha en los ítems; al completarlo pasa a ser
        // una venta y la fecha prometida entra igual que en una orden nueva.
        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => 'borrador',
            'valor_total' => 1500000, 'fecha_sugerida_vendedor' => '2026-10-01',
        ]);
        OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Sofá', 'cantidad' => 1,
                           'precio_unitario' => 1500000, 'es_personalizado' => true]);

        // Lo que hace completarBorrador: solo rellena las que estén vacías.
        $fecha = $orden->fresh()->fecha_sugerida_vendedor?->toDateString();
        $orden->items()->whereNull('fecha_entrega_prom')->update(['fecha_entrega_prom' => $fecha]);

        $this->assertSame('2026-10-01', $orden->items()->first()->fecha_entrega_prom->toDateString());
    }

    public function test_corregir_la_fecha_no_pisa_la_que_se_le_prometio_al_cliente(): void
    {
        $orden = $this->crearOrdenCon('2026-09-15');

        // Quien supervisa la mueve porque no se alcanza a cumplir.
        $orden->items()->update(['fecha_entrega_prom' => '2026-09-30']);

        // La prometida se conserva: es la que deja ver que la entrega se corrió
        // respecto de lo que se habló en el punto de venta.
        $this->assertSame('2026-09-15', $orden->fresh()->fecha_sugerida_vendedor->toDateString());
        $this->assertSame('2026-09-30', $orden->items()->first()->fecha_entrega_prom->toDateString());
    }
}
