<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Usuario;
use App\Services\NumeracionOrdenes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Anular una orden sin dejar hueco: se subió la misma venta dos veces y la
 * repetida no debe gastar consecutivo. La orden suelta su número, las que
 * vienen después bajan uno y el contador queda donde toca. La alternativa
 * (cancelar y dejar el hueco) no toca la numeración, así que no hay nada que
 * probar de ese lado.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class AnularOrdenTest extends TestCase
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
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('tipo')->default('venta');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('grupo_secuencia', 50)->nullable();
            $t->string('numero_anulado', 30)->nullable();
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

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Armenia']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Doña Marta', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x',
                                'rol' => 'supervisor', 'created_at' => now()]);
    }

    private function venta(int $numero, string $estado = 'pendiente_anticipo'): Orden
    {
        return Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
            'estado' => $estado, 'valor_total' => 500000, 'tipo' => 'venta',
            'numero_orden' => $numero, 'grupo_secuencia' => 'armenia',
        ]);
    }

    public function test_al_anular_corriendo_las_siguientes_bajan_uno_y_la_anulada_recuerda_su_numero(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4292]);

        $o4290 = $this->venta(4290);           // la repetida
        $o4291 = $this->venta(4291, 'entregado');
        $o4292 = $this->venta(4292);

        $corridas = NumeracionOrdenes::liberarYCorrer($o4290, $this->supervisor());

        $this->assertCount(2, $corridas);
        $this->assertNull($o4290->fresh()->numero_orden);
        $this->assertSame('#4290', $o4290->fresh()->numero_anulado);
        $this->assertSame('Anulada (era #4290)', $o4290->fresh()->referencia);
        $this->assertSame(4290, $o4291->fresh()->numero_orden);
        $this->assertSame(4291, $o4292->fresh()->numero_orden);
        // La próxima venta toma el 4292, no salta al 4293.
        $this->assertSame(4291, DB::table('orden_secuencias')->where('grupo', 'armenia')->value('ultimo_numero'));

        // Cada orden movida queda con su rastro.
        $this->assertSame(3, DB::table('orden_ediciones')->count());
    }

    public function test_anular_la_ultima_numerada_solo_baja_el_contador(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4290]);
        $o4290 = $this->venta(4290);

        $corridas = NumeracionOrdenes::liberarYCorrer($o4290, $this->supervisor());

        $this->assertSame([], $corridas);
        $this->assertNull($o4290->fresh()->numero_orden);
        $this->assertSame(4289, DB::table('orden_secuencias')->where('grupo', 'armenia')->value('ultimo_numero'));
    }

    public function test_la_vista_previa_dice_cuales_se_correrian_sin_tocar_nada(): void
    {
        DB::table('orden_secuencias')->insert(['grupo' => 'armenia', 'ultimo_numero' => 4292]);
        $o4290 = $this->venta(4290);
        $this->venta(4291, 'entregado');
        $this->venta(4292);

        $resp = $this->actingAs($this->supervisor())->getJson("/api/ordenes/{$o4290->id}/anulacion")->assertOk();

        $resp->assertJsonPath('referencia', '#4290')
             ->assertJsonPath('tiene_numero', true)
             ->assertJsonPath('ya_entregadas', 1)
             ->assertJsonCount(2, 'corridas')
             ->assertJsonPath('corridas.0.de', '#4291')
             ->assertJsonPath('corridas.0.a', '#4290');

        $this->assertSame(4290, $o4290->fresh()->numero_orden);
    }

    public function test_una_orden_sin_consecutivo_no_tiene_nada_que_correr(): void
    {
        $borrador = Orden::create([
            'cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
            'estado' => 'borrador', 'valor_total' => 0, 'tipo' => 'venta',
        ]);

        $this->assertSame([], NumeracionOrdenes::liberarYCorrer($borrador, $this->supervisor()));
        $this->actingAs($this->supervisor())->getJson("/api/ordenes/{$borrador->id}/anulacion")
            ->assertOk()->assertJsonPath('tiene_numero', false);
    }
}
