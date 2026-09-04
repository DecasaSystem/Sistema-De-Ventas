<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El comando que limpia lo que dejó el corrector de numeración cuando movía
 * solo el número: órdenes con número de venta normal cuyos muebles seguían
 * marcados como del cliente, así que comisiones las cobraba como restauración
 * (5% aparte, sin sumarle a la meta). Es el caso de la #1242.
 *
 * Lo que hay que fijar aquí: NO barre con todo. Solo cuadra las que pasaron
 * por el corrector; una restauración vieja numerada normal —de cuando no
 * existía la serie R— se queda quieta, porque cambiarla movería una comisión
 * histórica que nadie pidió cambiar.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RevisarRestauracionesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('tipo')->default('venta');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('grupo_secuencia', 50)->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_restauracion')->default(false); $t->timestamps();
        });
        // Como la de verdad: sin updated_at —una edición no se edita
        // (OrdenEdicion tiene $timestamps = false)— y con usuario obligatorio.
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->json('cambios')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable();
        });
        DB::table('usuarios')->insert(['id' => 3, 'nombre' => 'Jefa', 'rol' => 'supervisor']);
    }

    /**
     * @param  bool  $itemsRestauracion  si los muebles están marcados como del cliente
     * @return int  id de la orden
     */
    private function orden(?string $serie, bool $itemsRestauracion, ?int $productoId = null): int
    {
        $clienteId = DB::table('clientes')->insertGetId(['nombre' => 'Doña Marta', 'created_at' => now(), 'updated_at' => now()]);
        $tiendaId  = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Unicentro Pereira']);

        $id = DB::table('ordenes')->insertGetId([
            'cliente_id' => $clienteId, 'tienda_id' => $tiendaId, 'estado' => 'entregado',
            'valor_total' => 5000000, 'tipo' => $serie === 'R' ? 'restauracion' : 'venta',
            'serie' => $serie, 'serie_numero' => $serie ? 1103 : null,
            'numero_orden' => $serie ? null : 1242, 'grupo_secuencia' => $serie ? null : 'pereira',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('orden_items')->insert([
            'orden_id' => $id, 'producto_id' => $productoId, 'es_restauracion' => $itemsRestauracion,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Deja el rastro que deja el corrector de numeración al convertir. */
    private function seConvirtio(int $ordenId): void
    {
        DB::table('orden_ediciones')->insert([
            'orden_id' => $ordenId, 'usuario_id' => 25,
            'cambios'  => json_encode([['campo' => 'numeracion', 'label' => 'Convertida a venta normal',
                                        'antes' => 'R-1103', 'despues' => '#1242']]),
            'created_at' => now(),
        ]);
    }

    private function itemEsRestauracion(int $ordenId): bool
    {
        return (bool) DB::table('orden_items')->where('orden_id', $ordenId)->value('es_restauracion');
    }

    public function test_cuadra_la_orden_que_se_corrigio_a_venta_normal(): void
    {
        $id = $this->orden(serie: null, itemsRestauracion: true);
        $this->seConvirtio($id);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true])->assertExitCode(0);

        $this->assertFalse($this->itemEsRestauracion($id));
        $this->assertSame('venta', DB::table('ordenes')->where('id', $id)->value('tipo'));

        // Queda el rastro en el historial, a nombre de quien hizo la
        // conversión: es la misma corrección suya, terminada.
        $anotacion = DB::table('orden_ediciones')->where('orden_id', $id)
            ->where('cambios', 'like', '%items_restauracion%')->first();
        $this->assertNotNull($anotacion);
        $this->assertSame(25, (int) $anotacion->usuario_id);
    }

    /**
     * MySQL no guarda una columna JSON como se escribió: la normaliza —espacio
     * después de los dos puntos y claves en otro orden—. Buscar el par exacto
     * `"campo":"numeracion"` no encontraba nada en producción y la #1242
     * quedaba fuera de la corrección, que es justo lo que se venía a arreglar.
     */
    public function test_encuentra_el_rastro_aunque_mysql_le_haya_movido_el_json(): void
    {
        $id = $this->orden(serie: null, itemsRestauracion: true);
        DB::table('orden_ediciones')->insert([
            'orden_id' => $id, 'usuario_id' => 25,
            'cambios'  => '[{"antes": "R-1103", "campo": "numeracion", "label": "Convertida a venta normal", "despues": "#1242"}]',
            'created_at' => now(),
        ]);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true])->assertExitCode(0);

        $this->assertFalse($this->itemEsRestauracion($id));
    }

    public function test_sin_aplicar_no_toca_nada(): void
    {
        $id = $this->orden(serie: null, itemsRestauracion: true);
        $this->seConvirtio($id);

        $this->artisan('ordenes:revisar-restauraciones')->assertExitCode(0);

        $this->assertTrue($this->itemEsRestauracion($id));
    }

    public function test_una_restauracion_vieja_numerada_normal_se_queda_quieta(): void
    {
        // Nunca pasó por el corrector: no hay nada que cuadrar, y cambiarla
        // le movería la comisión a un mes ya cerrado.
        $id = $this->orden(serie: null, itemsRestauracion: true);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true])->assertExitCode(0);

        $this->assertTrue($this->itemEsRestauracion($id));
    }

    public function test_con_todas_si_entra_la_vieja(): void
    {
        $id = $this->orden(serie: null, itemsRestauracion: true);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true, '--todas' => true])
            ->assertExitCode(0);

        $this->assertFalse($this->itemEsRestauracion($id));
    }

    public function test_una_r_cuyos_muebles_no_estan_marcados_se_marcan(): void
    {
        $id = $this->orden(serie: 'R', itemsRestauracion: false);
        $this->seConvirtio($id);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true])->assertExitCode(0);

        $this->assertTrue($this->itemEsRestauracion($id));
        $this->assertSame('restauracion', DB::table('ordenes')->where('id', $id)->value('tipo'));
    }

    /**
     * Una restauración es el mueble del cliente: no sale de inventario. Si la
     * orden lleva productos del catálogo, marcarlos mentiría sobre un stock
     * que ya se descontó, así que se reporta y se deja para revisar a mano.
     */
    public function test_una_r_con_productos_del_inventario_no_se_toca(): void
    {
        $id = $this->orden(serie: 'R', itemsRestauracion: false, productoId: 77);
        $this->seConvirtio($id);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true])->assertExitCode(0);

        $this->assertFalse($this->itemEsRestauracion($id));
    }

    /** Cuadrar una mueve la comisión de su mes, así que se puede ir de a una. */
    public function test_con_orden_solo_toca_esa(): void
    {
        $mia  = $this->orden(serie: null, itemsRestauracion: true);
        $otra = $this->orden(serie: 'R', itemsRestauracion: false);
        $this->seConvirtio($mia);
        $this->seConvirtio($otra);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true, '--orden' => '#1242'])
            ->assertExitCode(0);

        $this->assertFalse($this->itemEsRestauracion($mia));
        $this->assertFalse($this->itemEsRestauracion($otra)); // la R-1103 sigue igual
    }

    public function test_una_orden_cuadrada_no_aparece(): void
    {
        $id = $this->orden(serie: 'R', itemsRestauracion: true);
        $this->seConvirtio($id);

        $this->artisan('ordenes:revisar-restauraciones', ['--aplicar' => true])
            ->expectsOutputToContain('Todas las órdenes están cuadradas')
            ->assertExitCode(0);
    }
}
