<?php

namespace Tests\Feature;

use App\Http\Controllers\OrdenController;
use App\Models\Orden;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Buscar una orden por su número, sea de la serie que sea.
 *
 * La venta normal lleva el consecutivo (#2567), la de descuento especial
 * "FV2-45" y la restauración "R-1098". Antes una restauración solo aparecía
 * escribiendo "R-1098" completo: "1098" a secas no la encontraba, y "R" o
 * "fv2" no traían nada.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class BuscarOrdenPorNumeroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre');
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->string('numero_anulado')->nullable();
        });

        DB::table('clientes')->insert([
            ['id' => 1, 'nombre' => 'Rosa Martínez'],
            ['id' => 2, 'nombre' => 'Carlos Pérez'],
        ]);
        DB::table('ordenes')->insert([
            ['id' => 1, 'cliente_id' => 2, 'numero_orden' => 2567, 'serie' => null,  'serie_numero' => null],
            ['id' => 2, 'cliente_id' => 2, 'numero_orden' => 12567, 'serie' => null, 'serie_numero' => null],
            ['id' => 3, 'cliente_id' => 2, 'numero_orden' => null, 'serie' => 'R',   'serie_numero' => 1098],
            ['id' => 4, 'cliente_id' => 2, 'numero_orden' => null, 'serie' => 'R',   'serie_numero' => 1099],
            ['id' => 5, 'cliente_id' => 2, 'numero_orden' => null, 'serie' => 'FV2', 'serie_numero' => 45],
            ['id' => 6, 'cliente_id' => 2, 'numero_orden' => null, 'serie' => 'FV2', 'serie_numero' => 450],
            ['id' => 7, 'cliente_id' => 2, 'numero_orden' => null, 'serie' => 'FV2', 'serie_numero' => 7],
            ['id' => 8, 'cliente_id' => 1, 'numero_orden' => 3000, 'serie' => null,  'serie_numero' => null],
        ]);
    }

    /** Los ids que encuentra la búsqueda, en el orden en que salen. */
    private function buscar(string $texto): array
    {
        $query = Orden::query();
        $metodo = new \ReflectionMethod(OrdenController::class, 'buscarPorClienteONumero');
        $metodo->invoke(app(OrdenController::class), $query, $texto);

        return $query->orderBy('id')->pluck('id')->all();
    }

    public function test_un_numero_solo_encuentra_tambien_las_series(): void
    {
        // "1098" es la restauración R-1098; antes no aparecía.
        $this->assertSame([3], $this->buscar('1098'));
        // "45" trae la FV2-45 y la FV2-450, que lo contienen.
        $this->assertSame([5, 6], $this->buscar('45'));
    }

    public function test_el_consecutivo_se_sigue_buscando_por_pedazos(): void
    {
        $this->assertSame([1, 2], $this->buscar('2567'));
        $this->assertSame([1, 2], $this->buscar('#2567'));
    }

    public function test_la_serie_sola_lista_todas_las_suyas(): void
    {
        // "R" es la serie de restauración: no trae a Rosa ni a Carlos por la
        // erre de su nombre, que llenaría la lista de lo que no se buscó.
        $this->assertSame([3, 4], $this->buscar('R'));
        $this->assertSame([3, 4], $this->buscar('r'));
        $this->assertSame([3, 4], $this->buscar('R-'));
        $this->assertSame([5, 6, 7], $this->buscar('fv2'));
        $this->assertSame([5, 6, 7], $this->buscar('FV2-'));
    }

    public function test_serie_con_numero_trae_las_que_empiezan_asi(): void
    {
        $this->assertSame([5, 6], $this->buscar('FV2-45'));
        $this->assertSame([5, 6], $this->buscar('fv2 45'));
        $this->assertSame([5, 6], $this->buscar('fv245'));
        $this->assertSame([3, 4], $this->buscar('r-109'));
        $this->assertSame([3], $this->buscar('R-1098'));
    }

    public function test_la_exacta_sale_primero(): void
    {
        $query = Orden::query();
        (new \ReflectionMethod(OrdenController::class, 'buscarPorClienteONumero'))
            ->invoke(app(OrdenController::class), $query, 'FV2-45');
        // Sin el orden de relevancia la 450 y la 45 saldrían en cualquier orden.
        $this->assertSame([5, 6], $query->orderByDesc('id')->pluck('id')->all());
    }

    public function test_el_nombre_del_cliente_se_sigue_buscando(): void
    {
        $this->assertSame([8], $this->buscar('rosa'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->buscar('carlos'));
    }
}
