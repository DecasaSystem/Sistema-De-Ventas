<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\Cartera;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La cartera: qué órdenes entran y quién ve cuáles.
 *
 * La lista en pantalla y el Excel salen de la misma regla. Antes el Excel
 * dejaba fuera las entregadas que deben y metía las ya pagadas del todo.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class CarteraTest extends TestCase
{
    private Usuario $supervisor;
    private Usuario $ana;       // vendedora de Norte
    private Usuario $beto;      // vendedor de Norte
    private Usuario $caro;      // vendedora de Sur
    private Usuario $indep1;    // independiente
    private Usuario $indep2;    // otro independiente, misma sede

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false); $t->boolean('ve_todas_ordenes')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('telefono')->nullable();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('estado');
            $t->decimal('valor_total', 12, 2)->default(0);
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->string('numero_anulado')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 12, 2);
        });

        DB::table('tiendas')->insert([['id' => 1, 'nombre' => 'Norte'], ['id' => 2, 'nombre' => 'Sur'], ['id' => 9, 'nombre' => 'Independientes']]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente']);

        $u = fn ($nombre, $rol, $tienda = null, $indep = false) => Usuario::forceCreate([
            'nombre' => $nombre, 'email' => "{$nombre}@d.com", 'password' => 'x', 'rol' => $rol,
            'tienda_default_id' => $tienda, 'independiente' => $indep,
        ]);
        $this->supervisor = $u('jefa', 'supervisor');
        $this->ana    = $u('ana',  'vendedor', 1);
        $this->beto   = $u('beto', 'vendedor', 1);
        $this->caro   = $u('caro', 'vendedor', 2);
        $this->indep1 = $u('ind1', 'vendedor', 9, true);
        $this->indep2 = $u('ind2', 'vendedor', 9, true);
    }

    private function orden(array $datos, float $pagado = 0): int
    {
        $id = DB::table('ordenes')->insertGetId(array_merge([
            'cliente_id' => 1, 'estado' => 'en_produccion', 'valor_total' => 1000, 'created_at' => now(),
        ], $datos));
        if ($pagado > 0) DB::table('pagos')->insert(['orden_id' => $id, 'monto' => $pagado]);
        return $id;
    }

    private function ids($filas): array
    {
        return collect($filas)->pluck('orden_id')->sort()->values()->all();
    }

    public function test_entra_lo_que_debe_entregado_o_no_y_nada_mas(): void
    {
        $debe       = $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1], 400);
        $entregada  = $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1, 'estado' => 'entregado'], 100);
        $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1], 1000);                        // pagada
        $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1, 'estado' => 'cancelado']);     // cancelada
        $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1, 'estado' => 'borrador']);      // no es venta
        $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1, 'estado' => 'cotizacion']);    // no es venta

        $filas = Cartera::de($this->supervisor);

        $this->assertSame([$debe, $entregada], $this->ids($filas));
        // De la que más debe a la que menos.
        $this->assertSame([900.0, 600.0], $filas->pluck('saldo_pendiente')->all());
    }

    public function test_el_vendedor_ve_la_de_su_tienda_con_la_de_sus_companeros(): void
    {
        $deAna  = $this->orden(['vendedor_id' => $this->ana->id,  'tienda_id' => 1]);
        $deBeto = $this->orden(['vendedor_id' => $this->beto->id, 'tienda_id' => 1]);
        // Una venta de Ana que quedó en otra tienda: es suya, la ve.
        $deAnaEnSur = $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 2]);
        $this->orden(['vendedor_id' => $this->caro->id, 'tienda_id' => 2]);   // de otra tienda: no

        $this->assertSame([$deAna, $deBeto, $deAnaEnSur], $this->ids(Cartera::de($this->ana)));
        // Y pedir otra tienda no le sirve: siempre es la suya.
        $this->assertSame([$deAna, $deBeto, $deAnaEnSur], $this->ids(Cartera::de($this->ana, 2)));
    }

    public function test_el_independiente_solo_ve_lo_suyo(): void
    {
        $suya  = $this->orden(['vendedor_id' => $this->indep1->id, 'tienda_id' => 9]);
        $this->orden(['vendedor_id' => $this->indep2->id, 'tienda_id' => 9]);
        // Compartida con él: también es suya.
        $compartida = $this->orden(['vendedor_id' => $this->indep2->id, 'covendedor_id' => $this->indep1->id, 'tienda_id' => 9]);

        $this->assertSame([$suya, $compartida], $this->ids(Cartera::de($this->indep1)));
    }

    public function test_el_supervisor_ve_todas_o_la_tienda_que_elija(): void
    {
        $norte = $this->orden(['vendedor_id' => $this->ana->id,  'tienda_id' => 1]);
        $sur   = $this->orden(['vendedor_id' => $this->caro->id, 'tienda_id' => 2]);

        $this->assertSame([$norte, $sur], $this->ids(Cartera::de($this->supervisor)));
        $this->assertSame([$sur], $this->ids(Cartera::de($this->supervisor, 2)));
    }

    public function test_cada_fila_dice_el_numero_que_tiene_en_papel(): void
    {
        $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1, 'numero_orden' => 2567]);
        $this->orden(['vendedor_id' => $this->ana->id, 'tienda_id' => 1, 'serie' => 'R', 'serie_numero' => 1098, 'valor_total' => 900]);

        $refs = Cartera::de($this->supervisor)->pluck('referencia')->sort()->values()->all();
        $this->assertSame(['#2567', 'R-1098'], $refs);
    }

    public function test_el_endpoint_y_el_excel_usan_la_misma_regla(): void
    {
        $this->orden(['vendedor_id' => $this->ana->id,  'tienda_id' => 1], 400);
        $this->orden(['vendedor_id' => $this->caro->id, 'tienda_id' => 2]);

        $this->actingAs($this->ana)->getJson('/api/stats/cartera')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.saldo_pendiente', 600);

        $this->actingAs($this->ana)->get('/api/reportes/exportar?tipo=pendientes')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
