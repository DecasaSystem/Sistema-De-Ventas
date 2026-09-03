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
            'estado' => 'entregado', 'valor_total' => 500000,
            'serie' => Orden::SERIE_FV2, 'serie_numero' => 7, 'motivo_serie' => 'familiar de los dueños',
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
