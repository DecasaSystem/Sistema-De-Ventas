<?php

namespace Tests\Feature;

use App\Events\InventarioActualizado;
use App\Models\Traslado;
use App\Services\AvisoTraslado;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un traslado de supervisor se ejecuta de una, y por eso mismo hay que contarlo.
 *
 * No hay nada que aceptar: el stock sale de una tienda y entra en la otra en el
 * acto. Eso está bien, pero pasaba en silencio — a la tienda destino no le
 * llegaba aviso ninguno y la mercancía le aparecía sola en el inventario. Desde
 * el otro lado se veía como una falla ("mandé las sillas y a ellos no les sale
 * nada"), cuando lo que faltaba era justamente el aviso.
 *
 * Lo que se comprueba es a quién le llega y qué dice: no basta con que se cree
 * una notificación, tiene que llegarle a la gente de la tienda que recibe y
 * nombrar lo que de verdad llegó.
 */
class AvisoTrasladoTest extends TestCase
{
    private const EDEN  = 1;
    private const NORTE = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre');
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->default('vendedor');
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('activo')->default(true);
            $t->boolean('acceso_surtir')->default(false);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre');
        });
        Schema::create('traslados', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->unsignedBigInteger('vendedor_validador_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id');
            $t->unsignedBigInteger('tienda_destino_id');
            $t->string('notas')->nullable();
            $t->timestamp('programado_para')->nullable();
            $t->string('estado')->default('completado');
            $t->timestamps();
        });
        Schema::create('traslado_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('traslado_id');
            $t->unsignedBigInteger('producto_id');
            $t->integer('cantidad');
            $t->integer('cantidad_aceptada')->nullable();
            $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('usuario_id')->nullable();
            $t->string('tipo', 50); $t->string('titulo'); $t->text('mensaje');
            $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false);
            $t->text('datos')->nullable();
            $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::EDEN,  'nombre' => 'Decasa El Edén'],
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte'],
        ]);
        DB::table('productos')->insert([
            ['id' => 1, 'nombre' => 'Silla Dubái'],
            ['id' => 2, 'nombre' => 'Mesa de centro'],
        ]);
        $usuario = fn(int $id, string $nombre, string $rol, ?int $tienda, bool $activo = true, bool $surtir = false) => [
            'id' => $id, 'nombre' => $nombre, 'rol' => $rol,
            'tienda_default_id' => $tienda, 'activo' => $activo, 'acceso_surtir' => $surtir,
        ];

        DB::table('usuarios')->insert([
            // Quien manda el traslado. Es supervisor: no está asignado a tienda.
            $usuario(1, 'Supervisora', 'supervisor', null),
            // La gente del Norte, que es la que recibe.
            $usuario(2, 'Vendedor Norte',  'vendedor',  self::NORTE),
            $usuario(3, 'Costurera Norte', 'costurero', self::NORTE, surtir: true),
            $usuario(4, 'Ebanista Norte',  'ebanista',  self::NORTE, surtir: false),
            $usuario(5, 'Retirada Norte',  'vendedor',  self::NORTE, activo: false),
            // La del Edén, de donde sale: a ella no le llega nada.
            $usuario(6, 'Vendedor Edén', 'vendedor', self::EDEN),
        ]);

        Queue::fake();    // el push sale por cola; aquí no se manda a ningún lado
    }

    /** Un traslado del Edén al Norte, ya ejecutado. */
    private function traslado(array $items): Traslado
    {
        $traslado = Traslado::create([
            'supervisor_id'     => 1,
            'tienda_origen_id'  => self::EDEN,
            'tienda_destino_id' => self::NORTE,
            'estado'            => 'completado',
        ]);

        foreach ($items as $item) {
            DB::table('traslado_items')->insert([
                'traslado_id'       => $traslado->id,
                'producto_id'       => $item['producto_id'],
                'cantidad'          => $item['cantidad'],
                'cantidad_aceptada' => $item['cantidad_aceptada'] ?? null,
            ]);
        }

        return $traslado;
    }

    private function avisos(): \Illuminate\Support\Collection
    {
        return DB::table('notificaciones')->where('tipo', 'traslado_recibido')->get();
    }

    public function test_a_la_tienda_destino_le_avisan_que_le_llego_mercancia(): void
    {
        AvisoTraslado::llegada($this->traslado([
            ['producto_id' => 1, 'cantidad' => 4],
        ]), autorId: 1);

        $avisos = $this->avisos();

        // El vendedor del Norte y la costurera con acceso_surtir; el ebanista
        // sin acceso no, la retirada tampoco, y el del Edén menos.
        $this->assertEqualsCanonicalizing([2, 3], $avisos->pluck('usuario_id')->all());
        $this->assertStringContainsString('4 Silla Dubái', $avisos->first()->mensaje);
        $this->assertStringContainsString('Decasa El Edén', $avisos->first()->mensaje);
    }

    public function test_a_quien_lo_mando_no_se_le_avisa(): void
    {
        // El caso raro pero real: quien hace el traslado atiende la tienda que
        // recibe. Ya sabe que lo mandó; avisarle es ruido.
        AvisoTraslado::llegada($this->traslado([
            ['producto_id' => 1, 'cantidad' => 2],
        ]), autorId: 2);

        $this->assertEqualsCanonicalizing([3], $this->avisos()->pluck('usuario_id')->all());
    }

    public function test_se_cuenta_lo_que_de_verdad_llego_no_lo_que_se_pidio(): void
    {
        // Aceptado a medias: salieron 10, se recibieron 3.
        AvisoTraslado::llegada($this->traslado([
            ['producto_id' => 1, 'cantidad' => 10, 'cantidad_aceptada' => 3],
        ]), autorId: 1);

        $mensaje = $this->avisos()->first()->mensaje;
        $this->assertStringContainsString('3 Silla Dubái', $mensaje);
        $this->assertStringNotContainsString('10 Silla Dubái', $mensaje);
    }

    public function test_lo_rechazado_entero_no_genera_aviso(): void
    {
        // Aceptar en cero es no recibir nada: no hay llegada que anunciar.
        AvisoTraslado::llegada($this->traslado([
            ['producto_id' => 1, 'cantidad' => 5, 'cantidad_aceptada' => 0],
        ]), autorId: 1);

        $this->assertCount(0, $this->avisos());
    }

    public function test_el_aviso_lleva_los_productos_para_abrirlos_en_el_inventario(): void
    {
        AvisoTraslado::llegada($this->traslado([
            ['producto_id' => 1, 'cantidad' => 4],
            ['producto_id' => 2, 'cantidad' => 1],
        ]), autorId: 1);

        $datos = json_decode($this->avisos()->first()->datos, true);

        $this->assertEqualsCanonicalizing([1, 2], $datos['productos']);
        $this->assertSame(self::NORTE, $datos['tienda_id']);
    }

    public function test_las_dos_tiendas_refrescan_su_inventario(): void
    {
        Event::fake([InventarioActualizado::class]);

        AvisoTraslado::refrescarInventario($this->traslado([
            ['producto_id' => 1, 'cantidad' => 4],
        ]));

        // La que recibe ve entrar el producto y la que envía ve que le bajó:
        // sin las dos, quien tenga el inventario abierto se queda con el número
        // viejo hasta que recargue.
        Event::assertDispatched(
            InventarioActualizado::class,
            fn($e) => $e->tiendaId === self::NORTE && $e->productoId === 1 && $e->tipo === 'entrada'
        );
        Event::assertDispatched(
            InventarioActualizado::class,
            fn($e) => $e->tiendaId === self::EDEN && $e->productoId === 1 && $e->tipo === 'salida'
        );
    }
}
