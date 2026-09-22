<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\MovimientoTraslado;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Trasladar respeta lo apartado, y la tela viaja con el producto.
 *
 * Dos cosas que faltaban:
 *
 *   - Lo apartado no se manda. Si hay 3 y una la espera un cliente, se pueden
 *     trasladar 2; pedir las 3 se rechaza diciendo por qué. Eso ya estaba a
 *     nivel de producto, pero no por tela: la tela apartada se podía mandar
 *     igual, y al recortar el reparto del origen desaparecía la reserva sin
 *     que nadie se enterara.
 *   - La tela viaja. Antes solo se movía el producto: en el origen el reparto
 *     se recortaba a lo que quedara y al destino llegaban unidades sin color,
 *     que ya no se podían vender por su tela.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class TrasladarConTelaYApartadosTest extends TestCase
{
    private const NORTE = 1, SUR = 2, SOFA = 5;
    private const BEIGE = 10, AZUL = 11;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_surtir')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable();
            $t->string('foto_url')->nullable(); $t->boolean('activo')->default(true);
        });
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id');
            $t->string('marca')->nullable(); $t->string('marca_tela')->nullable();
            $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('inventario_variantes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('opcion_id')->nullable();
        });
        Schema::create('inventario_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_variante_combinaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('config_id');
            $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('traslados', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->unsignedBigInteger('vendedor_validador_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id'); $t->unsignedBigInteger('tienda_destino_id');
            $t->string('notas')->nullable(); $t->timestamp('programado_para')->nullable();
            $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('traslado_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('traslado_id'); $t->unsignedBigInteger('producto_id');
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->unsignedInteger('cantidad'); $t->unsignedInteger('cantidad_aceptada')->nullable();
            $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo')->nullable();
            $t->string('titulo')->nullable(); $t->text('mensaje')->nullable();
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable();
            $t->timestamp('leida_at')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte'],
            ['id' => self::SUR,   'nombre' => 'Decasa Sur'],
        ]);
        DB::table('productos')->insert(['id' => self::SOFA, 'nombre' => 'Sofá Telavid', 'categoria' => 'sofas']);
        DB::table('producto_variantes')->insert([
            ['id' => self::BEIGE, 'producto_id' => self::SOFA, 'nombre_color' => 'Beige'],
            ['id' => self::AZUL,  'producto_id' => self::SOFA, 'nombre_color' => 'Azul'],
        ]);
    }

    /** En Norte: 3 sofás, uno de ellos apartado. Beige y Azul según se pida. */
    private function stockEnNorte(int $hay, int $apartado, array $telas = []): void
    {
        DB::table('inventario')->insert([
            'producto_id' => self::SOFA, 'tienda_id' => self::NORTE,
            'cantidad_disponible' => $hay, 'cantidad_reservada' => $apartado,
        ]);
        foreach ($telas as $varianteId => [$tieneTela, $apartadaTela]) {
            DB::table('inventario_variantes')->insert([
                'variante_id' => $varianteId, 'tienda_id' => self::NORTE,
                'cantidad_disponible' => $tieneTela, 'cantidad_reservada' => $apartadaTela,
            ]);
        }
    }

    private function supervisor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Sup', 'email' => 'sup@d.com', 'password' => 'x',
            'rol' => 'supervisor', 'tienda_default_id' => self::NORTE, 'created_at' => now(),
        ]);
    }

    private function trasladar(int $cantidad, ?int $varianteId = null)
    {
        return $this->actingAs($this->supervisor())->postJson('/api/inventario/traslados', [
            'tienda_origen_id'  => self::NORTE,
            'tienda_destino_id' => self::SUR,
            'items' => [array_filter([
                'producto_id' => self::SOFA, 'cantidad' => $cantidad, 'variante_id' => $varianteId,
            ], fn ($v) => $v !== null)],
        ]);
    }

    /** [hay, apartado] del producto en una tienda. */
    private function producto(int $tienda): array
    {
        $i = DB::table('inventario')->where('producto_id', self::SOFA)->where('tienda_id', $tienda)->first();
        return [(int) ($i->cantidad_disponible ?? 0), (int) ($i->cantidad_reservada ?? 0)];
    }

    /** [hay, apartado] de una tela en una tienda. */
    private function tela(int $varianteId, int $tienda): array
    {
        $i = DB::table('inventario_variantes')->where('variante_id', $varianteId)->where('tienda_id', $tienda)->first();
        return [(int) ($i->cantidad_disponible ?? 0), (int) ($i->cantidad_reservada ?? 0)];
    }

    // ── Lo apartado no se manda ──────────────────────────────────────────────

    public function test_de_tres_con_una_apartada_se_pueden_mandar_dos(): void
    {
        $this->stockEnNorte(3, 1);

        $this->trasladar(2)->assertCreated();

        $this->assertSame([1, 1], $this->producto(self::NORTE), 'queda la apartada');
        $this->assertSame([2, 0], $this->producto(self::SUR));
    }

    public function test_pedir_las_tres_se_rechaza_y_dice_por_que(): void
    {
        $this->stockEnNorte(3, 1);

        $r = $this->trasladar(3)->assertStatus(422);

        $this->assertStringContainsString('1 ya está apartado', $r->json('message'));
        $this->assertSame([3, 1], $this->producto(self::NORTE), 'no se movió nada');
    }

    // ── La tela viaja con el producto ────────────────────────────────────────

    public function test_la_tela_que_sale_de_una_tienda_entra_en_la_otra(): void
    {
        $this->stockEnNorte(2, 0, [self::BEIGE => [1, 0], self::AZUL => [1, 0]]);

        $this->trasladar(1, self::BEIGE)->assertCreated();

        $this->assertSame([0, 0], $this->tela(self::BEIGE, self::NORTE), 'la beige se fue');
        $this->assertSame([1, 0], $this->tela(self::BEIGE, self::SUR),   'y llegó como beige');
        $this->assertSame([1, 0], $this->tela(self::AZUL, self::NORTE),  'la azul no se movió');
        $this->assertSame([1, 0], $this->producto(self::NORTE));
        $this->assertSame([1, 0], $this->producto(self::SUR));
    }

    public function test_queda_registrado_de_que_tela_era(): void
    {
        $this->stockEnNorte(2, 0, [self::BEIGE => [1, 0], self::AZUL => [1, 0]]);

        $this->trasladar(1, self::BEIGE)->assertCreated();

        $this->assertSame(self::BEIGE, (int) DB::table('traslado_items')->value('variante_id'));
    }

    /** Una tela apartada por una orden no se puede mandar a otra tienda. */
    public function test_no_se_puede_mandar_la_tela_que_esta_apartada(): void
    {
        $this->stockEnNorte(2, 1, [self::BEIGE => [1, 1], self::AZUL => [1, 0]]);

        $r = $this->trasladar(1, self::BEIGE)->assertStatus(422);

        $this->assertStringContainsString('esa tela', $r->json('message'));
        $this->assertSame([1, 1], $this->tela(self::BEIGE, self::NORTE), 'sigue ahí');
    }

    public function test_pero_la_otra_tela_si_se_puede_mandar(): void
    {
        $this->stockEnNorte(2, 1, [self::BEIGE => [1, 1], self::AZUL => [1, 0]]);

        $this->trasladar(1, self::AZUL)->assertCreated();

        $this->assertSame([0, 0], $this->tela(self::AZUL, self::NORTE));
        $this->assertSame([1, 0], $this->tela(self::AZUL, self::SUR));
        $this->assertSame([1, 1], $this->tela(self::BEIGE, self::NORTE), 'la apartada no se tocó');
    }

    /**
     * Lo que se rompía en silencio: al mandar sin decir la tela, el reparto
     * del origen se recortaba empezando por el montón más grande y podía
     * llevarse la tela que una orden tenía apartada.
     */
    public function test_trasladar_sin_decir_la_tela_no_se_lleva_la_apartada(): void
    {
        $this->stockEnNorte(2, 1, [self::BEIGE => [1, 1], self::AZUL => [1, 0]]);

        $this->trasladar(1)->assertCreated();

        $this->assertSame([1, 1], $this->tela(self::BEIGE, self::NORTE), 'la apartada se queda entera');
        $this->assertSame([0, 0], $this->tela(self::AZUL, self::NORTE),  'la que se recortó fue la libre');
    }

    // ── Lo que la pantalla necesita para poder avisar ────────────────────────

    public function test_el_stock_de_la_tienda_dice_las_telas_y_cuantas_estan_apartadas(): void
    {
        $this->stockEnNorte(2, 1, [self::BEIGE => [1, 1], self::AZUL => [1, 0]]);

        $r = $this->actingAs($this->supervisor())
            ->getJson('/api/inventario/traslados/stock-tienda/' . self::NORTE)->assertOk();

        $fila = $r->json()[0];
        $this->assertSame(1, $fila['cantidad_reservada']);
        $this->assertSame(1, $fila['stock_libre']);

        $telas = collect($fila['telas'])->keyBy('variante_id');
        $this->assertSame(0, $telas[self::BEIGE]['libre'], 'la beige está apartada');
        $this->assertSame(1, $telas[self::AZUL]['libre']);
    }

    public function test_una_tela_nunca_ofrece_mas_de_lo_que_el_producto_tiene_libre(): void
    {
        // El reparto dice 2 beige, pero del producto solo hay 1 libre.
        $this->stockEnNorte(2, 1, [self::BEIGE => [2, 0]]);

        $this->assertSame(
            1,
            MovimientoTraslado::loQueSePuedeMandar(self::SOFA, self::NORTE, self::BEIGE)['libre'],
        );
    }
}
