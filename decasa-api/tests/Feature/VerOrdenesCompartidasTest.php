<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Una venta compartida tiene que salirle a todos los que cobran por ella.
 *
 * Dos formas de compartir, y ninguna de las dos se veía:
 *
 *  - Entre vendedores (`covendedor_id`): el que ayudó comisiona la mitad, pero
 *    la orden no le aparecía en ninguna parte.
 *  - Un independiente que le abona la venta a una tienda
 *    (`tienda_abonada_id`): la mitad de esa comisión se reparte entre TODA la
 *    gente de la tienda, y ninguno de ellos la veía.
 *
 * La lista filtraba solo por `vendedor_id`, así que quien no la vendió no la
 * veía aunque le tocara parte.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class VerOrdenesCompartidasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(false); $t->boolean('independiente')->default(false);
            $t->boolean('facturacion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('pendiente_anticipo'); $t->string('tipo')->default('venta');
            $t->decimal('valor_total', 15, 2)->default(0); $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamp('confirmada_en')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 15, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('es_restauracion')->default(false);
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->string('estado')->default('pendiente');
            $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable();
            $t->date('fecha_real')->nullable(); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0); $t->string('tipo')->nullable();
            $t->string('metodo')->nullable(); $t->string('referencia')->nullable();
            $t->string('comprobante_url')->nullable(); $t->text('notas')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('orden_fijadas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
        });
        // Registrar un pago recalcula las comisiones de la orden.
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
            $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
            $t->boolean('es_covendedor')->default(false); $t->string('origen')->nullable();
            $t->timestamps();
        });
        // El detalle la carga siempre, aunque la orden no tenga ediciones.
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Norte'],
            ['id' => 2, 'nombre' => 'Independientes'],
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vendedor(string $nombre, ?int $tienda, bool $independiente = false): Usuario
    {
        return Usuario::create([
            'nombre' => $nombre, 'email' => "$nombre@d.com", 'password' => 'x', 'rol' => 'vendedor',
            've_todas_ordenes' => false, 'independiente' => $independiente,
            'tienda_default_id' => $tienda, 'created_at' => now(),
        ]);
    }

    /** Los ids que le salen a alguien en su lista de órdenes. */
    private function loQueVe(Usuario $u): array
    {
        return collect($this->actingAs($u)->getJson('/api/ordenes')->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_la_venta_que_un_independiente_abona_a_una_tienda_le_sale_a_esa_tienda(): void
    {
        $henry = $this->vendedor('Henry', 2, independiente: true);
        $marta = $this->vendedor('Marta', 1);
        $ajena = $this->vendedor('Ajena', null);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 2, 'vendedor_id' => $henry->id,
            'tienda_abonada_id' => 1, 'estado' => 'en_produccion', 'valor_total' => 2000000,
        ]);

        $this->assertContains($orden->id, $this->loQueVe($marta), 'la tienda abonada tiene que verla');
        $this->assertContains($orden->id, $this->loQueVe($henry), 'quien la vendió la sigue viendo');
        $this->assertNotContains($orden->id, $this->loQueVe($ajena), 'quien no tiene parte, no');
    }

    public function test_la_orden_compartida_con_otro_vendedor_le_sale_al_covendedor(): void
    {
        $genesis = $this->vendedor('Genesis', 1);
        $juan    = $this->vendedor('Juan', 1);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $genesis->id,
            'covendedor_id' => $juan->id, 'es_compartida' => true,
            'estado' => 'en_produccion', 'valor_total' => 1000000,
        ]);

        $this->assertContains($orden->id, $this->loQueVe($juan));
    }

    public function test_el_borrador_de_un_independiente_todavia_no_se_comparte(): void
    {
        $henry = $this->vendedor('Henry', 2, independiente: true);
        $marta = $this->vendedor('Marta', 1);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 2, 'vendedor_id' => $henry->id,
            'tienda_abonada_id' => 1, 'estado' => 'borrador', 'valor_total' => 500000,
        ]);

        // Todavía se está armando: aparecería a medio hacer en la lista de una
        // tienda que aún no sabe que existe.
        $this->assertNotContains($orden->id, $this->loQueVe($marta));
    }

    public function test_si_le_sale_en_la_lista_la_puede_abrir(): void
    {
        $henry = $this->vendedor('Henry', 2, independiente: true);
        $marta = $this->vendedor('Marta', 1);
        $ajena = $this->vendedor('Ajena', null);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 2, 'vendedor_id' => $henry->id,
            'tienda_abonada_id' => 1, 'estado' => 'en_produccion', 'valor_total' => 2000000,
        ]);

        $this->actingAs($marta)->getJson("/api/ordenes/{$orden->id}")->assertOk();
        $this->actingAs($ajena)->getJson("/api/ordenes/{$orden->id}")->assertStatus(403);
    }

    public function test_la_tienda_con_la_que_se_comparte_tambien_puede_cobrar(): void
    {
        $henry = $this->vendedor('Henry', 2, independiente: true);
        $marta = $this->vendedor('Marta', 1);
        $ajena = $this->vendedor('Ajena', null);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 2, 'vendedor_id' => $henry->id,
            'tienda_abonada_id' => 1, 'estado' => 'en_produccion', 'valor_total' => 1000000,
        ]);

        // El cliente llega a la tienda con la que se compartió y abona ahí.
        $this->actingAs($marta)
            ->postJson("/api/ordenes/{$orden->id}/pagos", [
                'monto' => 300000, 'tipo' => 'abono', 'metodo' => 'efectivo',
                'comprobante_url' => 'https://ejemplo/recibo.jpg',
            ])
            ->assertCreated();

        $this->assertSame(300000.0, (float) DB::table('pagos')->where('orden_id', $orden->id)->sum('monto'));

        $this->actingAs($ajena)
            ->postJson("/api/ordenes/{$orden->id}/pagos", [
                'monto' => 100000, 'tipo' => 'abono', 'metodo' => 'efectivo',
                'comprobante_url' => 'https://ejemplo/recibo.jpg',
            ])
            ->assertStatus(403);
    }

    public function test_una_orden_ajena_sigue_sin_verse(): void
    {
        $marta = $this->vendedor('Marta', 1);
        $otra  = $this->vendedor('Otra', 1);

        $orden = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $otra->id,
            'estado' => 'en_produccion', 'valor_total' => 800000,
        ]);

        // Compartir es lo que abre la puerta; estar en la misma tienda no.
        $this->assertNotContains($orden->id, $this->loQueVe($marta));
        $this->actingAs($marta)->getJson("/api/ordenes/{$orden->id}")->assertStatus(403);
    }
}
