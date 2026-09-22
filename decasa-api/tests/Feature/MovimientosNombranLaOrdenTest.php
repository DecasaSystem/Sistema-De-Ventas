<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El historial de movimientos dice de qué orden es cada uno.
 *
 * El motivo guardado nombra la orden por su id de tabla ("Orden #12"), que no
 * es el número que lleva la orden ("#4300", "FV2-3") ni se puede buscar en
 * ninguna parte. El texto guardado no se toca —la auditoría de entregas sin
 * descontar se apoya en ese formato—, se traduce al mostrarlo.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class MovimientosNombranLaOrdenTest extends TestCase
{
    private const TIENDA = 1, MESA = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->string('numero_anulado')->nullable();
            $t->timestamps();
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert(['id' => self::TIENDA, 'nombre' => 'Decasa Norte']);
        DB::table('productos')->insert(['id' => self::MESA, 'nombre' => 'Mesa']);
    }

    private function supervisor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Sup', 'email' => 'sup@d.com', 'password' => 'x',
            'rol' => 'supervisor', 'tienda_default_id' => self::TIENDA, 'created_at' => now(),
        ]);
    }

    private function movimiento(string $motivo): void
    {
        DB::table('inventario_movimientos')->insert([
            'producto_id' => self::MESA, 'tienda_id' => self::TIENDA,
            'tipo' => 'reserva', 'cantidad' => 1, 'motivo' => $motivo, 'created_at' => now(),
        ]);
    }

    private function primerMovimiento(): array
    {
        return $this->actingAs($this->supervisor())
            ->getJson('/api/inventario/' . self::MESA . '/movimientos?tienda_id=' . self::TIENDA)
            ->assertOk()->json()[0];
    }

    public function test_traduce_el_id_interno_al_numero_de_la_orden(): void
    {
        $orden = Orden::create(['tienda_id' => self::TIENDA, 'numero_orden' => 4300, 'estado' => 'entregado']);
        $this->movimiento("Orden #{$orden->id}");

        $m = $this->primerMovimiento();

        $this->assertSame($orden->id, $m['orden_id']);
        $this->assertSame('#4300', $m['orden_referencia']);
        // El texto guardado no cambia: la auditoría de entregas se apoya en él.
        $this->assertSame("Orden #{$orden->id}", $m['motivo']);
    }

    public function test_tambien_con_una_serie_especial(): void
    {
        $orden = Orden::create(['tienda_id' => self::TIENDA, 'serie' => 'FV2', 'serie_numero' => 3, 'estado' => 'entregado']);
        $this->movimiento("Entrega orden #{$orden->id} — mostrador");

        $this->assertSame('FV2-3', $this->primerMovimiento()['orden_referencia']);
    }

    /**
     * El riesgo de traducir a ciegas: unos pocos motivos ya nombran la orden
     * por su referencia, a mitad de frase. Tomar ese número por un id llevaría
     * a una orden distinta — aquí, a la #1.
     */
    public function test_no_confunde_una_referencia_con_un_id_interno(): void
    {
        Orden::create(['id' => 4300, 'tienda_id' => self::TIENDA, 'numero_orden' => 9999, 'estado' => 'entregado']);
        $this->movimiento('Devolución para cambio — orden #4300');

        $m = $this->primerMovimiento();

        $this->assertNull($m['orden_id'], 'ese número ya es la referencia, no un id');
        $this->assertNull($m['orden_referencia']);
    }

    public function test_un_movimiento_que_no_es_de_ninguna_orden_no_inventa_una(): void
    {
        $this->movimiento('Entrada por surtido');

        $this->assertNull($this->primerMovimiento()['orden_id']);
    }

    public function test_si_la_orden_ya_no_existe_se_deja_el_texto_como_esta(): void
    {
        $this->movimiento('Borrador eliminado (orden interna #77)');

        $m = $this->primerMovimiento();

        $this->assertNull($m['orden_id']);
        $this->assertSame('Borrador eliminado (orden interna #77)', $m['motivo']);
    }
}
