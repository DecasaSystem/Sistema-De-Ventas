<?php

namespace Tests\Feature;

use App\Jobs\AlertarRetrasoProduccion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un pedido "listo" esperando que lo despachen, con la fecha de entrega ya
 * vencida, no cambia de estado solo: se queda "listo" días. La sección 4 de
 * AlertarRetrasoProduccion() lo detecta por fecha, no por una transición de
 * estado como la sección 1 — así que sin freno, el job corriendo a diario le
 * mandaba la MISMA alerta a soporte y al vendedor todos los días, para
 * siempre, mientras nadie lo despachara.
 *
 * De paso, Carbon 3 cambió el default de diffInDays() de absoluto a con
 * signo: sin pedirlo explícito, una fecha pasada daba un número NEGATIVO
 * ("-9 días de retraso"), y ese negativo dejaba sin efecto cualquier freno
 * basado en "si lleva más de un día, no lo repitas".
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class AlertarRetrasoNoSeRepiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('pendiente_anticipo');
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->date('fecha_entrega_prom')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });

        // La sección "por vencer" del job usa DATEDIFF/CURDATE de MySQL.
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CURDATE', fn () => Carbon::now()->toDateString());
        $pdo->sqliteCreateFunction('DATEDIFF', fn ($a, $b) => Carbon::parse($a)->startOfDay()
            ->diffInDays(Carbon::parse($b)->startOfDay(), false) * -1);
    }

    /** Un item "listo" (sin producción activa) con fecha de entrega vencida. */
    private function itemAtrasado(int $diasAtras, string $estadoProduccion = 'listo'): OrdenItem
    {
        $tienda   = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Norte']);
        $vendedor = Usuario::create(['nombre' => 'Vendedor', 'rol' => 'vendedor', 'tienda_default_id' => $tienda]);
        $cliente  = DB::table('clientes')->insertGetId(['nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        $producto = Producto::create(['nombre' => 'Mesa']);

        $orden = Orden::create([
            'cliente_id' => $cliente, 'tienda_id' => $tienda, 'vendedor_id' => $vendedor->id,
            'estado' => 'confirmado',
        ]);

        $item = OrdenItem::create([
            'orden_id' => $orden->id, 'producto_id' => $producto->id,
            'fecha_entrega_prom' => Carbon::today()->subDays($diasAtras)->toDateString(),
        ]);

        DB::table('produccion')->insert([
            'orden_item_id' => $item->id, 'estado' => $estadoProduccion,
        ]);

        return $item;
    }

    public function test_los_dias_de_retraso_se_muestran_en_positivo(): void
    {
        // Lunes, para que el freno semanal no se cruce con lo que se prueba aquí.
        Carbon::setTestNow(Carbon::parse('2026-09-21'));
        $this->itemAtrasado(9);

        (new AlertarRetrasoProduccion())->handle();

        $mensaje = DB::table('notificaciones')->where('tipo', 'retrasado')
            ->where('titulo', 'Entrega retrasada')->value('mensaje');

        $this->assertNotNull($mensaje);
        $this->assertStringContainsString('9 día(s) de retraso', $mensaje);
        $this->assertStringNotContainsString('-9', $mensaje);

        Carbon::setTestNow();
    }

    /**
     * El bug real: el job corre todos los días, y sin freno un item que sigue
     * "listo" sin despachar recibía la MISMA alerta cada mañana, para siempre.
     */
    public function test_un_atrasado_no_se_repite_al_dia_siguiente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15')); // martes
        $this->itemAtrasado(1); // recién atrasado: se avisa

        (new AlertarRetrasoProduccion())->handle();
        $diaUno = DB::table('notificaciones')->where('tipo', 'retrasado')->count();
        $this->assertGreaterThan(0, $diaUno, 'El primer día sí debe avisar.');

        Carbon::setTestNow(Carbon::parse('2026-09-16')); // miércoles: el mismo item, un día más atrasado
        (new AlertarRetrasoProduccion())->handle();
        $diaDos = DB::table('notificaciones')->where('tipo', 'retrasado')->count();

        $this->assertSame($diaUno, $diaDos, 'No debería repetir la misma alerta al día siguiente.');

        Carbon::setTestNow();
    }

    public function test_un_atrasado_recien_llegado_si_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15')); // martes
        $this->itemAtrasado(1);

        (new AlertarRetrasoProduccion())->handle();

        $this->assertGreaterThan(0, DB::table('notificaciones')->where('tipo', 'retrasado')->count());

        Carbon::setTestNow();
    }

    public function test_un_atrasado_viejo_si_se_recuerda_los_lunes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15')); // martes
        $this->itemAtrasado(5);
        (new AlertarRetrasoProduccion())->handle();
        $antes = DB::table('notificaciones')->where('tipo', 'retrasado')->count();

        Carbon::setTestNow(Carbon::parse('2026-09-21')); // lunes siguiente
        (new AlertarRetrasoProduccion())->handle();
        $despues = DB::table('notificaciones')->where('tipo', 'retrasado')->count();

        $this->assertGreaterThan($antes, $despues, 'Los lunes sí debe recordar los atrasados viejos.');

        Carbon::setTestNow();
    }
}
