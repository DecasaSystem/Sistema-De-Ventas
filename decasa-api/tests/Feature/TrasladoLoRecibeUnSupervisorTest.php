<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Quien manda un traslado (no supervisor) escoge quién lo recibe, y ese puede
 * ser un supervisor (dueño, 2026-11-07), hacia una tienda o hacia la Reserva.
 *
 * Lo que faltaba: la lista solo traía vendedores (o, para la Reserva, a quien
 * tiene acceso a Reserva); al supervisor no le salía en Inventario el traslado
 * para aceptar; y si lo aceptaba él, la tienda destino nunca se enteraba de que
 * le llegó mercancía. De paso: nadie se recibe su propio traslado.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class TrasladoLoRecibeUnSupervisorTest extends TestCase
{
    private const NORTE = 1, SUR = 2, FABRICA = 3, SOFA = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_surtir')->default(false); $t->boolean('acceso_reserva')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('es_fabrica')->default(false);
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
            ['id' => self::NORTE,   'nombre' => 'Decasa Norte',  'es_fabrica' => false],
            ['id' => self::SUR,     'nombre' => 'Decasa Sur',    'es_fabrica' => false],
            ['id' => self::FABRICA, 'nombre' => 'Bodega Fábrica', 'es_fabrica' => true],
        ]);
        DB::table('productos')->insert(['id' => self::SOFA, 'nombre' => 'Sofá Telavid', 'categoria' => 'sofas']);
        DB::table('inventario')->insert([
            'producto_id' => self::SOFA, 'tienda_id' => self::NORTE, 'cantidad_disponible' => 3,
        ]);
    }

    private function usuario(string $nombre, string $rol, ?int $tienda, array $extra = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre' => $nombre, 'email' => strtolower($nombre) . '@d.com', 'password' => 'x',
            'rol' => $rol, 'tienda_default_id' => $tienda, 'created_at' => now(),
        ], $extra));
    }

    private function mandar(Usuario $quien, int $destino, int $validadorId, int $cantidad = 2)
    {
        return $this->actingAs($quien)->postJson('/api/inventario/traslados', [
            'tienda_origen_id'      => self::NORTE,
            'tienda_destino_id'     => $destino,
            'vendedor_validador_id' => $validadorId,
            'items' => [['producto_id' => self::SOFA, 'cantidad' => $cantidad]],
        ]);
    }

    private function hay(int $tienda): int
    {
        return (int) DB::table('inventario')->where('producto_id', self::SOFA)
            ->where('tienda_id', $tienda)->value('cantidad_disponible');
    }

    private function avisos(int $usuarioId, string $tipo)
    {
        return DB::table('notificaciones')->where('usuario_id', $usuarioId)->where('tipo', $tipo)->get();
    }

    public function test_la_lista_para_escoger_trae_supervisores_en_tiendas_y_en_la_reserva(): void
    {
        $vendedor = $this->usuario('Paola', 'vendedor', self::NORTE);
        $this->usuario('Gladys', 'vendedor', self::SUR);
        $this->usuario('Daniela', 'supervisor', null);
        $this->usuario('Bodeguero', 'ebanista', null, ['acceso_reserva' => true]);
        $this->usuario('Viejo', 'supervisor', null, ['activo' => false]);

        $aSur = $this->actingAs($vendedor)->getJson('/api/inventario/vendedores-tienda/' . self::SUR)->assertOk();
        $nombres = collect($aSur->json())->pluck('nombre')->all();
        // La gente de la tienda primero, después los supervisores, después el resto.
        $this->assertSame(['Gladys', 'Daniela', 'Paola'], $nombres);
        $this->assertSame('supervisor', collect($aSur->json())->firstWhere('nombre', 'Daniela')['rol']);

        $aReserva = $this->actingAs($vendedor)->getJson('/api/inventario/vendedores-tienda/' . self::FABRICA)->assertOk();
        $this->assertSame(['Bodeguero', 'Daniela'], collect($aReserva->json())->pluck('nombre')->all());
    }

    public function test_un_supervisor_recibe_lo_que_va_a_la_reserva_le_llega_el_aviso_y_lo_acepta(): void
    {
        $vendedor   = $this->usuario('Paola', 'vendedor', self::NORTE);
        $supervisor = $this->usuario('Daniela', 'supervisor', null);
        $bodeguero  = $this->usuario('Bodeguero', 'ebanista', null, ['acceso_reserva' => true]);

        $id = $this->mandar($vendedor, self::FABRICA, $supervisor->id)->assertCreated()->json('id');

        // No se movió nada todavía: espera a que lo reciban.
        $this->assertSame(3, $this->hay(self::NORTE));
        $this->assertCount(1, $this->avisos($supervisor->id, 'traslado_pendiente'));

        // Le sale para aceptar.
        $pend = $this->actingAs($supervisor)->getJson('/api/inventario/traslados/pendientes')->assertOk();
        $this->assertSame([$id], collect($pend->json())->pluck('id')->all());

        $this->actingAs($supervisor)->patchJson("/api/inventario/traslados/{$id}/aceptar")->assertOk();

        $this->assertSame(1, $this->hay(self::NORTE));
        $this->assertSame(2, $this->hay(self::FABRICA));
        // A quien lo mandó le avisan que lo aceptaron; a quien maneja la
        // Reserva, que le llegó mercancía (el supervisor no estaba allá).
        $this->assertCount(1, $this->avisos($vendedor->id, 'traslado_aceptado'));
        $this->assertCount(1, $this->avisos($bodeguero->id, 'traslado_recibido'));
        $this->assertCount(0, $this->avisos($supervisor->id, 'traslado_recibido'));
    }

    public function test_si_lo_acepta_un_supervisor_la_tienda_destino_se_entera(): void
    {
        $vendedor   = $this->usuario('Paola', 'vendedor', self::NORTE);
        $gladys     = $this->usuario('Gladys', 'vendedor', self::SUR);
        $supervisor = $this->usuario('Daniela', 'supervisor', null);

        $id = $this->mandar($vendedor, self::SUR, $supervisor->id)->assertCreated()->json('id');
        $this->actingAs($supervisor)->patchJson("/api/inventario/traslados/{$id}/aceptar")->assertOk();

        $this->assertSame(2, $this->hay(self::SUR));
        $this->assertCount(1, $this->avisos($gladys->id, 'traslado_recibido'));
    }

    public function test_si_lo_acepta_alguien_de_la_tienda_no_se_le_avisa_a_la_tienda_otra_vez(): void
    {
        $vendedor = $this->usuario('Paola', 'vendedor', self::NORTE);
        $gladys   = $this->usuario('Gladys', 'vendedor', self::SUR);
        $sebas    = $this->usuario('Sebastian', 'vendedor', self::SUR);

        $id = $this->mandar($vendedor, self::SUR, $gladys->id)->assertCreated()->json('id');
        $this->actingAs($gladys)->patchJson("/api/inventario/traslados/{$id}/aceptar")->assertOk();

        $this->assertSame(2, $this->hay(self::SUR));
        // Como siempre: quien recibe ya lo sabe y el compañero lo ve en el inventario.
        $this->assertCount(0, $this->avisos($sebas->id, 'traslado_recibido'));
    }

    public function test_nadie_se_recibe_su_propio_traslado_ni_lo_recibe_cualquiera(): void
    {
        $vendedor = $this->usuario('Paola', 'vendedor', self::NORTE);
        $chofer   = $this->usuario('Carlos', 'conductor', self::SUR);
        $gladys   = $this->usuario('Gladys', 'vendedor', self::SUR);

        $this->mandar($vendedor, self::SUR, $vendedor->id)->assertStatus(422);
        $this->mandar($vendedor, self::SUR, $chofer->id)->assertStatus(422);
        // A la Reserva, un vendedor sin acceso a Reserva tampoco.
        $this->mandar($vendedor, self::FABRICA, $gladys->id)->assertStatus(422);

        $this->assertSame(0, DB::table('traslados')->count());
        $this->assertSame(3, $this->hay(self::NORTE));
    }
}
