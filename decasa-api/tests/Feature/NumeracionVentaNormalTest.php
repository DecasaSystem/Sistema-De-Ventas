<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Corregir al revés: una orden que se marcó FV2 o R por error y en realidad
 * era una venta normal. Antes el corrector solo sabía ir HACIA una serie —
 * volver no estaba, así que quedaba pegada con un número que no es de ningún
 * talonario.
 *
 * Lo que hace que esto no sea trivial: el número que le toca sale del
 * consecutivo de SU TIENDA, y Pereira lleva el suyo aparte de Armenia. Tomar
 * cualquier número (o siempre el de Armenia) numeraría una venta de Pereira
 * con el consecutivo equivocado.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class NumeracionVentaNormalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('tipo')->default('venta');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('grupo_secuencia', 50)->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->string('motivo_serie')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->json('cambios')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_personalizado')->default(false); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->timestamps();
        });
    }

    /** Un mueble dentro de la orden. Sin producto_id = no salió de inventario. */
    private function item(Orden $orden, bool $restauracion, ?int $productoId = null): int
    {
        return DB::table('orden_items')->insertGetId([
            'orden_id'         => $orden->id,
            'producto_id'      => $productoId,
            'es_personalizado' => true,
            'es_restauracion'  => $restauracion,
            'precio_unitario'  => 250000,
            'created_at'       => now(), 'updated_at' => now(),
        ]);
    }

    private function esRestauracion(int $itemId): bool
    {
        return (bool) DB::table('orden_items')->where('id', $itemId)->value('es_restauracion');
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    private function ordenFv2(string $tienda): Orden
    {
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => $tienda]);
        $clienteId = DB::table('clientes')->insertGetId(['nombre' => 'Doña Marta', 'created_at' => now(), 'updated_at' => now()]);

        return Orden::create([
            'cliente_id' => $clienteId, 'tienda_id' => $tiendaId, 'vendedor_id' => 1,
            'estado' => 'entregado', 'valor_total' => 500000, 'tipo' => 'venta',
            'serie' => Orden::SERIE_FV2, 'serie_numero' => 7, 'motivo_serie' => 'familiar de los dueños',
        ]);
    }

    /** Una restauración de verdad: el carrito la marcó 'restauracion' al crearla. */
    private function ordenRestauracion(string $tienda): Orden
    {
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => $tienda]);
        $clienteId = DB::table('clientes')->insertGetId(['nombre' => 'Karolay', 'created_at' => now(), 'updated_at' => now()]);

        return Orden::create([
            'cliente_id' => $clienteId, 'tienda_id' => $tiendaId, 'vendedor_id' => 1,
            'estado' => 'entregado', 'valor_total' => 300000, 'tipo' => 'restauracion',
            'serie' => Orden::SERIE_RESTAURACION, 'serie_numero' => 1103,
        ]);
    }

    public function test_una_fv2_de_armenia_vuelve_a_numero_normal_de_armenia(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4260]);
        $orden = $this->ordenFv2('Decasa Norte');

        $resp = $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'NORMAL',
        ])->assertOk();

        $resp->assertJson(['referencia' => '#4261']);

        $orden->refresh();
        $this->assertNull($orden->serie);
        $this->assertNull($orden->serie_numero);
        $this->assertNull($orden->motivo_serie);
        $this->assertSame(4261, $orden->numero_orden);
        $this->assertSame('armenia', $orden->grupo_secuencia);
        $this->assertSame(4261, DB::table('orden_secuencias')->where('grupo', 'armenia')->value('ultimo_numero'));
    }

    public function test_una_fv2_de_pereira_toma_el_numero_de_pereira_no_el_de_armenia(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4260]);
        DB::table('orden_secuencias')->insert(['grupo' => 'pereira', 'ultimo_numero' => 1300]);
        $orden = $this->ordenFv2('Decasa Unicentro Pereira');

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'NORMAL',
        ])->assertOk()->assertJson(['referencia' => '#1301']);

        $orden->refresh();
        $this->assertSame(1301, $orden->numero_orden);
        $this->assertSame('pereira', $orden->grupo_secuencia);

        // Armenia no se tocó: son consecutivos independientes.
        $this->assertSame(4260, DB::table('orden_secuencias')->where('grupo', 'armenia')->value('ultimo_numero'));
        $this->assertSame(1301, DB::table('orden_secuencias')->where('grupo', 'pereira')->value('ultimo_numero'));
    }

    public function test_la_vista_previa_no_cambia_nada(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 100]);
        $orden = $this->ordenFv2('Decasa Norte');

        $this->actingAs($this->supervisor())
            ->getJson("/api/ordenes/{$orden->id}/numeracion?serie=NORMAL")
            ->assertOk()
            ->assertJson(['orden' => ['a' => '#101']]);

        $this->assertSame(100, DB::table('orden_secuencias')->where('grupo', 'armenia')->value('ultimo_numero'));
        $this->assertSame(Orden::SERIE_FV2, $orden->fresh()->serie);
    }

    /**
     * `tipo` se guarda aparte de `serie` desde que se creó la orden y nada la
     * mantenía sincronizada: si no se corrige junto con el número, la lista
     * le sigue mostrando la etiqueta "Restauración" a una orden que ya se
     * corrigió como venta normal. Es el caso real de la orden R-1103.
     */
    public function test_al_volver_a_normal_una_r_de_verdad_tambien_se_le_corrige_el_tipo(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'pereira', 'ultimo_numero' => 1241]);
        $orden = $this->ordenRestauracion('Decasa Unicentro Pereira');
        $this->assertSame('restauracion', $orden->tipo);

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'NORMAL',
        ])->assertOk()->assertJson(['referencia' => '#1242']);

        $this->assertSame('venta', $orden->fresh()->tipo);
    }

    /**
     * El pedido explícito: si se corrige R-1103 y había R-1104 y R-1105
     * detrás, esas dos tienen que bajar a R-1103 y R-1104 -- si no, el
     * consecutivo de la serie R se queda con un hueco para siempre.
     */
    public function test_al_volver_a_normal_se_puede_correr_el_hueco_que_deja_en_la_serie(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'pereira', 'ultimo_numero' => 1241]);
        DB::table('orden_secuencias')->insert(['grupo' => 'r', 'ultimo_numero' => 1105]);

        $r1103 = $this->ordenRestauracion('Decasa Unicentro Pereira'); // serie_numero 1103
        $r1104 = $this->otraRestauracion(1104);
        $r1105 = $this->otraRestauracion(1105);

        $resp = $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$r1103->id}/numeracion/convertir", [
            'serie'  => 'NORMAL',
            'correr' => true,
        ])->assertOk();

        $resp->assertJsonCount(2, 'corridas');

        $this->assertSame(1242, $r1103->fresh()->numero_orden);
        $this->assertSame(1103, $r1104->fresh()->serie_numero);
        $this->assertSame(1104, $r1105->fresh()->serie_numero);
        // El contador de la serie R también baja: la próxima restauración
        // toma 1105, no repite un número que ya se corrió.
        $this->assertSame(1104, DB::table('orden_secuencias')->where('grupo', 'r')->value('ultimo_numero'));
    }

    /** Otra restauración cualquiera, para armar el "detrás de la que se corrige". */
    private function otraRestauracion(int $serieNumero): Orden
    {
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Vía El Edén']);
        $clienteId = DB::table('clientes')->insertGetId(['nombre' => 'Otro cliente', 'created_at' => now(), 'updated_at' => now()]);

        return Orden::create([
            'cliente_id' => $clienteId, 'tienda_id' => $tiendaId, 'vendedor_id' => 1,
            'estado' => 'entregado', 'valor_total' => 200000, 'tipo' => 'restauracion',
            'serie' => Orden::SERIE_RESTAURACION, 'serie_numero' => $serieNumero,
        ]);
    }

    public function test_al_convertir_una_normal_a_r_tambien_se_le_pone_el_tipo_restauracion(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 100]);
        DB::table('orden_secuencias')->insert(['grupo' => 'r', 'ultimo_numero' => 5]);
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Norte']);
        $orden = Orden::create([
            'tienda_id' => $tiendaId, 'vendedor_id' => 1, 'estado' => 'entregado', 'tipo' => 'venta',
            'valor_total' => 300000, 'numero_orden' => 101, 'grupo_secuencia' => 'armenia',
        ]);

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'R',
        ])->assertOk();

        $this->assertSame('restauracion', $orden->fresh()->tipo);
    }

    /**
     * El caso real de la #1242, y la parte que faltaba.
     *
     * Corregir la numeración no basta: comisiones, reportes y el taller no
     * miran ni la serie ni `tipo`, deducen la restauración de que TODOS los
     * muebles sean del cliente. La R-1103 se corrigió a #1242 y sus muebles
     * se quedaron marcados, así que la orden siguió cobrándose como
     * restauración —al 5% aparte y sin sumarle a la meta de la tienda— con un
     * número de venta normal.
     */
    public function test_al_volver_a_normal_los_muebles_dejan_de_ser_restauracion(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'pereira', 'ultimo_numero' => 1241]);
        $orden = $this->ordenRestauracion('Decasa Unicentro Pereira');
        $mueble = $this->item($orden, restauracion: true);
        $otro   = $this->item($orden, restauracion: true);

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'NORMAL',
        ])->assertOk()->assertJson(['referencia' => '#1242', 'items_tocados' => 2]);

        $this->assertFalse($this->esRestauracion($mueble));
        $this->assertFalse($this->esRestauracion($otro));
    }

    /** Y al revés: la que sí era restauración y se numeró como venta normal. */
    public function test_al_convertir_a_r_los_muebles_pasan_a_ser_del_cliente(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 100]);
        DB::table('orden_secuencias')->insert(['grupo' => 'r', 'ultimo_numero' => 5]);
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Norte']);
        $orden = Orden::create([
            'tienda_id' => $tiendaId, 'vendedor_id' => 1, 'estado' => 'entregado', 'tipo' => 'venta',
            'valor_total' => 300000, 'numero_orden' => 100, 'grupo_secuencia' => 'armenia',
        ]);
        $mueble = $this->item($orden, restauracion: false);

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'R',
        ])->assertOk();

        $this->assertTrue($this->esRestauracion($mueble));
    }

    /**
     * Una restauración es el mueble del cliente: no sale de inventario. Si la
     * orden lleva productos del catálogo, marcarlos como del cliente mentiría
     * sobre un stock que ya se descontó. Es la misma regla que impide mezclar
     * venta y restauración al crear la orden.
     */
    public function test_no_se_convierte_a_r_una_orden_con_productos_del_inventario(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 100]);
        DB::table('orden_secuencias')->insert(['grupo' => 'r', 'ultimo_numero' => 5]);
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Norte']);
        $orden = Orden::create([
            'tienda_id' => $tiendaId, 'vendedor_id' => 1, 'estado' => 'entregado', 'tipo' => 'venta',
            'valor_total' => 300000, 'numero_orden' => 100, 'grupo_secuencia' => 'armenia',
        ]);
        $item = $this->item($orden, restauracion: false, productoId: 77);

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'R',
        ])->assertStatus(422);

        $this->assertFalse($this->esRestauracion($item));
        $this->assertNull($orden->fresh()->serie);
    }

    /** La vista previa avisa cuántos muebles cambian de bando, sin tocarlos. */
    public function test_la_vista_previa_avisa_cuantos_muebles_cambian(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'pereira', 'ultimo_numero' => 1241]);
        $orden  = $this->ordenRestauracion('Decasa Unicentro Pereira');
        $mueble = $this->item($orden, restauracion: true);

        $this->actingAs($this->supervisor())
            ->getJson("/api/ordenes/{$orden->id}/numeracion?serie=NORMAL")
            ->assertOk()
            ->assertJson(['items_a_tocar' => 1]);

        $this->assertTrue($this->esRestauracion($mueble));
    }

    public function test_una_orden_que_ya_es_normal_no_se_puede_volver_a_convertir_a_normal(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4260]);
        $tiendaId = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Norte']);
        $orden = Orden::create([
            'tienda_id' => $tiendaId, 'vendedor_id' => 1, 'estado' => 'entregado',
            'valor_total' => 500000, 'numero_orden' => 4261, 'grupo_secuencia' => 'armenia',
        ]);

        $this->actingAs($this->supervisor())->postJson("/api/ordenes/{$orden->id}/numeracion/convertir", [
            'serie' => 'NORMAL',
        ])->assertStatus(422);
    }
}
