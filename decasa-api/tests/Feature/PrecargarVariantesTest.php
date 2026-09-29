<?php

namespace Tests\Feature;

use App\Models\OrdenItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `variante_texto` va en $appends: se arma al mandar CADA ítem de una lista.
 *
 * Un ítem nuevo trae su `variante_detalle` guardado y no toca la base. Uno
 * viejo, sin él, iba a buscar su tela y su opción uno por uno: una lista de
 * 50 piezas eran hasta 150 consultas antes de responder. Lo que se prueba es
 * que precargarVariantes() deja todo listo en tres consultas para todos, que
 * el texto sigue diciendo lo mismo, y que no consulta nada si no hace falta.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class PrecargarVariantesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->default(1);
            $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->string('variante_detalle')->nullable();
            $t->text('specs_personalizacion')->nullable();
            $t->timestamps();
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id')->default(1);
            $t->string('marca')->nullable(); $t->string('marca_tela')->nullable();
            $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id')->default(1);
            $t->unsignedBigInteger('tipo_variante_id')->default(1);
            $t->unsignedBigInteger('opcion_id');
        });
        Schema::create('tipo_variante_opciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id')->default(1); $t->string('nombre');
        });
    }

    private function itemsViejos(int $cuantos): \Illuminate\Database\Eloquent\Collection
    {
        DB::table('tipo_variante_opciones')->insert(['id' => 1, 'nombre' => '140x190']);
        DB::table('producto_variante_configs')->insert(['id' => 1, 'opcion_id' => 1]);

        for ($i = 1; $i <= $cuantos; $i++) {
            DB::table('producto_variantes')->insert([
                'id' => $i, 'marca_tela' => 'Lino', 'nombre_color' => "Color {$i}",
            ]);
            DB::table('orden_items')->insert([
                'variante_id' => $i, 'combo_config_id' => 1, 'variante_detalle' => null,
            ]);
        }

        return OrdenItem::orderBy('id')->get();
    }

    public function test_los_items_viejos_se_arman_sin_una_consulta_por_item(): void
    {
        $items = $this->itemsViejos(10);

        DB::enableQueryLog();
        OrdenItem::precargarVariantes($items->toBase());
        $textos = $items->map->variante_texto->all();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Variantes, configs y opciones: una consulta de cada una, para los 10.
        $this->assertSame(3, $consultas);
        $this->assertSame('Lino · Color 1 · 140x190', $textos[0]);
        $this->assertSame('Lino · Color 10 · 140x190', $textos[9]);
    }

    public function test_dice_lo_mismo_que_sin_precargar(): void
    {
        $items = $this->itemsViejos(3);
        $sinPrecarga = OrdenItem::orderBy('id')->get()->map->variante_texto->all();

        OrdenItem::precargarVariantes($items->toBase());

        $this->assertSame($sinPrecarga, $items->map->variante_texto->all());
    }

    public function test_no_consulta_nada_si_todos_traen_su_texto_guardado(): void
    {
        DB::table('orden_items')->insert([
            ['variante_id' => 5, 'variante_detalle' => 'Lino · Azul'],
            ['variante_id' => 6, 'variante_detalle' => 'Pana · Gris'],
        ]);
        $items = OrdenItem::orderBy('id')->get();

        DB::enableQueryLog();
        OrdenItem::precargarVariantes($items->toBase());
        $textos = $items->map->variante_texto->all();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $consultas);
        $this->assertSame(['Lino · Azul', 'Pana · Gris'], $textos);
    }

    public function test_tolera_huecos_en_la_lista(): void
    {
        // Una producción para la Reserva no tiene ítem: pluck('ordenItem') trae null.
        $items = $this->itemsViejos(1);

        OrdenItem::precargarVariantes(collect([null, $items[0]]));

        $this->assertTrue($items[0]->relationLoaded('variante'));
    }
}
