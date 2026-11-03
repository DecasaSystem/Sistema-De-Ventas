<?php

namespace Tests\Feature;

use App\Jobs\AlertarRetrasoProduccion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\Usuario;
use App\Support\AjustesSistema;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Entrega próxima": con cuántos días de anticipación se avisa que una pieza
 * del taller se vence.
 *
 * Antes eran 3, 2 y 1 día fijos y cada pieza avisaba tres veces. El dueño
 * (2026-11-03): "solo notifique cuando la entrega sea en 1 día… que se pueda
 * personalizar". Lo que importa:
 *  - sin tocar nada, solo el día antes;
 *  - lo que se escoja en Gestión → Ajustes manda (y nada = solo "Entrega hoy");
 *  - solo un supervisor lo cambia, y solo a días permitidos.
 *
 * El esquema se monta a mano, como en AlertarRetrasoNoSeRepiteTest.
 */
class AvisoEntregaProximaTest extends TestCase
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
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });

        // La sección "por vencer" del job usa DATEDIFF/CURDATE de MySQL.
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CURDATE', fn () => Carbon::now()->toDateString());
        $pdo->sqliteCreateFunction('DATEDIFF', fn ($a, $b) => Carbon::parse($a)->startOfDay()
            ->diffInDays(Carbon::parse($b)->startOfDay(), false) * -1);
    }

    /** Una pieza en proceso que vence en tantos días. */
    private function piezaQueVenceEn(int $dias): void
    {
        $tienda   = DB::table('tiendas')->insertGetId(['nombre' => 'Decasa Norte']);
        $vendedor = Usuario::create(['nombre' => "Vendedor {$dias}", 'rol' => 'vendedor', 'tienda_default_id' => $tienda]);
        $cliente  = DB::table('clientes')->insertGetId(['nombre' => "Cliente {$dias}", 'created_at' => now(), 'updated_at' => now()]);
        $producto = Producto::create(['nombre' => 'Mesa']);

        $orden = Orden::create([
            'cliente_id' => $cliente, 'tienda_id' => $tienda, 'vendedor_id' => $vendedor->id, 'estado' => 'en_produccion',
        ]);
        $item = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => $producto->id]);

        DB::table('produccion')->insert([
            'orden_item_id' => $item->id, 'estado' => 'en_proceso',
            'fecha_compromiso' => Carbon::today()->addDays($dias)->toDateString(),
        ]);
    }

    /** Los días con los que salió un aviso de vencer pronto al vendedor. */
    private function diasAvisados(): array
    {
        return DB::table('notificaciones')->where('tipo', 'por_vencer')->whereNotNull('usuario_id')
            ->pluck('datos')->map(fn ($d) => json_decode($d, true)['dias_restantes'])
            ->sort()->values()->all();
    }

    private function correrTarea(): void
    {
        foreach ([1, 2, 3, 4] as $d) $this->piezaQueVenceEn($d);
        (new AlertarRetrasoProduccion())->handle();
    }

    public function test_sin_tocar_nada_solo_avisa_el_dia_antes(): void
    {
        $this->correrTarea();

        $this->assertSame([1], $this->diasAvisados());
    }

    public function test_avisa_los_dias_que_se_escojan(): void
    {
        AjustesSistema::definirDiasAvisoEntrega([3, 1]);

        $this->correrTarea();

        $this->assertSame([1, 3], $this->diasAvisados());
    }

    public function test_sin_dias_no_avisa_con_anticipacion_pero_si_el_mismo_dia(): void
    {
        AjustesSistema::definirDiasAvisoEntrega([]);
        $this->piezaQueVenceEn(0);

        $this->correrTarea();

        $this->assertSame([], $this->diasAvisados());
        $this->assertTrue(DB::table('notificaciones')->where('tipo', 'entrega_hoy')->exists());
    }

    public function test_un_valor_raro_en_la_tabla_no_rompe_la_tarea(): void
    {
        DB::table('configuracion')->insert(['clave' => AjustesSistema::DIAS_AVISO_ENTREGA, 'valor' => '2, x,9,2']);

        $this->assertSame([2], AjustesSistema::diasAvisoEntrega());
    }

    public function test_solo_un_supervisor_lo_cambia_y_solo_a_dias_permitidos(): void
    {
        $vendedor = Usuario::create(['nombre' => 'Mónica', 'rol' => 'vendedor']);
        $jefe     = Usuario::create(['nombre' => 'Jefe', 'rol' => 'supervisor']);

        $this->actingAs($vendedor)->getJson('/api/ajustes-sistema')
            ->assertOk()->assertJsonPath('avisar_entrega_dias_antes', [1]);

        $this->actingAs($vendedor)->patchJson('/api/ajustes-sistema', ['avisar_entrega_dias_antes' => [2]])
            ->assertForbidden();
        $this->actingAs($jefe)->patchJson('/api/ajustes-sistema', ['avisar_entrega_dias_antes' => [5]])
            ->assertUnprocessable();

        $this->actingAs($jefe)->patchJson('/api/ajustes-sistema', ['avisar_entrega_dias_antes' => [2, 1]])
            ->assertOk()->assertJsonPath('avisar_entrega_dias_antes', [1, 2]);
        $this->actingAs($jefe)->patchJson('/api/ajustes-sistema', ['avisar_entrega_dias_antes' => []])
            ->assertOk()->assertJsonPath('avisar_entrega_dias_antes', []);
    }
}
