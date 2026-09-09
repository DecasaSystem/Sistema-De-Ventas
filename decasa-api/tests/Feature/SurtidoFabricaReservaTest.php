<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un surtido "desde fábrica" reserva stock en la fábrica al crearse (para que
 * nadie más se lo lleve mientras el vendedor de destino valida). Esa reserva
 * tiene que soltarse sea lo que decida el vendedor: si acepta, se convierte en
 * salida real; si rechaza, se libera sin más.
 *
 * `Surtido::fuente_fabrica` no estaba en el `$fillable` del modelo: `crear()`
 * SÍ reservaba (usa la variable local del request, no el modelo), pero
 * `aceptar()` y `rechazar()` leen `$surtido->fuente_fabrica` de la fila
 * guardada — que, al no persistirse, siempre volvía `false`. Resultado: la
 * reserva de fábrica se hacía siempre, y no se soltaba nunca, sin importar
 * qué respondiera el vendedor.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class SurtidoFabricaReservaTest extends TestCase
{
    private const FABRICA = 1;
    private const NORTE   = 2;
    private const PRODUCTO = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->default('vendedor');
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('activo')->default(true);
            $t->boolean('acceso_surtir')->default(false);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activo')->default(true);
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(0);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('surtidos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->string('notas')->nullable(); $t->string('estado')->default('enviado');
            $t->timestamp('programado_para')->nullable();
            $t->boolean('fuente_fabrica')->default(false);
            $t->timestamps();
        });
        Schema::create('surtido_tiendas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('vendedor_validador_id');
            $t->string('estado')->default('pendiente');
            $t->string('notas_vendedor')->nullable(); $t->timestamp('respondido_at')->nullable();
        });
        Schema::create('surtido_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_tienda_id'); $t->unsignedBigInteger('producto_id');
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->integer('cantidad'); $t->integer('cantidad_aceptada')->nullable();
            $t->text('especificaciones')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->string('tipo', 50); $t->string('titulo'); $t->text('mensaje');
            $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->text('datos')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::FABRICA, 'nombre' => 'Bodega Fábrica', 'es_fabrica' => true],
            ['id' => self::NORTE,   'nombre' => 'Decasa Norte',   'es_fabrica' => false],
        ]);
        DB::table('productos')->insert(['id' => self::PRODUCTO, 'nombre' => 'Base 2K']);
        Inventario::create(['producto_id' => self::PRODUCTO, 'tienda_id' => self::FABRICA, 'cantidad_disponible' => 10, 'cantidad_reservada' => 0]);

        Queue::fake(); // el push de la notificación sale por cola; aquí no se manda a ningún lado
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_surtir' => true, 'created_at' => now()]);
    }

    private function validador(): Usuario
    {
        return Usuario::create(['nombre' => 'Validador Norte', 'rol' => 'vendedor', 'tienda_default_id' => self::NORTE, 'created_at' => now()]);
    }

    private function crearSurtido(Usuario $supervisor, Usuario $validador): int
    {
        $r = $this->actingAs($supervisor)->postJson('/api/inventario/surtir', [
            'fuente_fabrica' => true,
            'tiendas' => [[
                'tienda_id'              => self::NORTE,
                'vendedor_validador_id'  => $validador->id,
                'items'                  => [['producto_id' => self::PRODUCTO, 'cantidad' => 3]],
            ]],
        ])->assertCreated();

        return $r->json('tiendas.0.id');
    }

    private function inventarioFabrica(): Inventario
    {
        return Inventario::where('producto_id', self::PRODUCTO)->where('tienda_id', self::FABRICA)->first();
    }

    public function test_crear_reserva_en_fabrica(): void
    {
        $this->crearSurtido($this->supervisor(), $this->validador());

        $this->assertSame(3, $this->inventarioFabrica()->cantidad_reservada);
        $this->assertSame(10, $this->inventarioFabrica()->cantidad_disponible);
    }

    public function test_aceptar_libera_la_reserva_de_fabrica_y_descuenta_lo_aceptado(): void
    {
        $validador = $this->validador();
        $stId = $this->crearSurtido($this->supervisor(), $validador);

        $this->actingAs($validador)
            ->patchJson("/api/inventario/surtido-tiendas/{$stId}/aceptar", ['notas_vendedor' => 'Todo llegó bien'])
            ->assertOk();

        $fab = $this->inventarioFabrica();
        $this->assertSame(0, $fab->cantidad_reservada, 'la reserva de fábrica debía soltarse al aceptar');
        $this->assertSame(7, $fab->cantidad_disponible, 'lo aceptado sí debe salir de disponible');

        $destino = Inventario::where('producto_id', self::PRODUCTO)->where('tienda_id', self::NORTE)->first();
        $this->assertSame(3, $destino->cantidad_disponible);
    }

    public function test_rechazar_libera_la_reserva_de_fabrica_sin_tocar_disponible(): void
    {
        $validador = $this->validador();
        $stId = $this->crearSurtido($this->supervisor(), $validador);

        $this->actingAs($validador)
            ->patchJson("/api/inventario/surtido-tiendas/{$stId}/rechazar", ['notas_vendedor' => 'No era lo pedido'])
            ->assertOk();

        $fab = $this->inventarioFabrica();
        $this->assertSame(0, $fab->cantidad_reservada, 'la reserva de fábrica debía soltarse al rechazar');
        $this->assertSame(10, $fab->cantidad_disponible, 'nada salió: no se aceptó nada');
    }
}
