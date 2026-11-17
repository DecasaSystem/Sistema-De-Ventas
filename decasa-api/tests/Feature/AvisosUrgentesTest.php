<?php

namespace Tests\Feature;

use App\Models\Notificacion;
use App\Models\Orden;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Avisos urgentes (pedido del usuario, 2026-11-17).
 *
 * - Que a alguien lo arroben en el chat de una orden le llega urgente: rojo y
 *   fijado arriba de la campana hasta que lo ve o lo borra.
 * - Cada uno desde su perfil, o un supervisor desde Trabajadores, escoge qué
 *   otros avisos le llegan así, de los que tiene prendidos y le llegan por su rol.
 * - "Leer todas" no despega las urgentes; ver el chat sí las da por vistas.
 */
class AvisosUrgentesTest extends TestCase
{
    private Usuario $paola;
    private Usuario $admin;
    private Usuario $conductor;
    private Orden $orden;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('facturacion')->default(false);
            $t->boolean('acceso_comisiones')->default(false);
            $t->boolean('acceso_finanzas')->default(false);
            $t->boolean('gestiona_produccion')->default(false);
            $t->json('notificaciones_apagadas')->nullable();
            $t->json('notificaciones_urgentes')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('en_produccion');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('numero_anulado')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_mensajes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->text('mensaje'); $t->string('imagen_url')->nullable(); $t->json('mencionados')->nullable();
            $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });

        $this->paola     = Usuario::forceCreate(['nombre' => 'Paola', 'rol' => 'vendedor']);
        $this->admin     = Usuario::forceCreate(['nombre' => 'Admin', 'rol' => 'supervisor']);
        $this->conductor = Usuario::forceCreate(['nombre' => 'Jhon', 'rol' => 'conductor']);
        $this->orden     = Orden::forceCreate([
            'vendedor_id' => $this->paola->id, 'estado' => 'en_produccion',
            'serie' => 'FV2', 'serie_numero' => 150,
        ]);
        Queue::fake();
    }

    private function escribir(Usuario $quien, array $body)
    {
        return $this->actingAs($quien)->postJson("/api/ordenes/{$this->orden->id}/mensajes", $body);
    }

    public function test_cuando_lo_arroban_le_llega_urgente_y_lo_demas_no(): void
    {
        $this->escribir($this->paola, ['mensaje' => '¿Hay tela?', 'mencionados' => [$this->admin->id]])->assertCreated();
        $this->escribir($this->admin, ['mensaje' => 'Sí'])->assertCreated();

        $alAdmin = Notificacion::where('usuario_id', $this->admin->id)->sole();
        $this->assertTrue($alAdmin->urgente);
        $this->assertTrue($alAdmin->datos['mencion']);

        // La respuesta sin arrobar le llega a Paola, pero normal
        $aPaola = Notificacion::where('usuario_id', $this->paola->id)->sole();
        $this->assertFalse($aPaola->urgente);
    }

    public function test_quien_quita_las_menciones_de_urgentes_las_recibe_normales(): void
    {
        $this->actingAs($this->admin)->putJson('/api/auth/mis-notificaciones', ['urgentes' => ['menciones' => false]])
            ->assertOk();

        $this->escribir($this->paola, ['mensaje' => '¿Hay tela?', 'mencionados' => [$this->admin->id]]);
        $this->assertFalse(Notificacion::where('usuario_id', $this->admin->id)->sole()->urgente);
    }

    public function test_cada_uno_escoge_que_otros_avisos_le_llegan_urgentes(): void
    {
        $this->actingAs($this->paola)->putJson('/api/auth/mis-notificaciones', ['urgentes' => ['chats' => true]])
            ->assertOk()
            ->assertJsonFragment(['clave' => 'chats', 'urgente' => true])
            ->assertJsonFragment(['clave' => 'menciones', 'urgente' => true]);

        // Ahora todo mensaje del chat le llega urgente, aunque no la arroben
        $this->escribir($this->admin, ['mensaje' => 'Ya quedó']);
        $this->assertTrue(Notificacion::where('usuario_id', $this->paola->id)->sole()->urgente);

        // Y un aviso de otro grupo sigue normal
        NotificacionService::crear('fecha_asignada', 'Fecha', 'x', [], $this->paola->id);
        $this->assertFalse(Notificacion::where('tipo', 'fecha_asignada')->sole()->urgente);
    }

    public function test_lo_que_el_codigo_manda_urgente_sigue_urgente(): void
    {
        $this->actingAs($this->admin)->putJson('/api/auth/mis-notificaciones', ['urgentes' => ['menciones' => false]]);
        NotificacionService::crear('cambio_dinero', 'Cambió la plata', 'x', [], $this->admin->id, urgente: true);
        $this->assertTrue(Notificacion::where('tipo', 'cambio_dinero')->sole()->urgente);
    }

    public function test_solo_se_ofrece_lo_que_le_llega_por_su_rol_y_tiene_prendido(): void
    {
        $this->conductor->forceFill(['notificaciones_apagadas' => ['entregas']])->save();

        $claves = collect($this->actingAs($this->conductor)->getJson('/api/auth/mis-notificaciones')
            ->assertOk()->json('urgentes'))->pluck('clave')->all();
        $this->assertNotContains('comisiones', $claves);
        $this->assertNotContains('finanzas', $claves);
        $this->assertNotContains('menciones', $claves);   // un conductor no está en el chat
        $this->assertNotContains('entregas', $claves);    // apagado por el supervisor

        // A un supervisor le llega todo
        $deAdmin = collect($this->actingAs($this->admin)->getJson('/api/auth/mis-notificaciones')
            ->json('urgentes'))->pluck('clave')->all();
        $this->assertContains('comisiones', $deAdmin);
        $this->assertContains('menciones', $deAdmin);

        // Lo que ya le ha llegado se ofrece aunque la lista del rol no lo tenga
        Notificacion::create(['usuario_id' => $this->conductor->id, 'tipo' => 'finanzas', 'titulo' => 'x', 'mensaje' => 'x']);
        $claves = collect($this->actingAs($this->conductor)->getJson('/api/auth/mis-notificaciones')
            ->json('urgentes'))->pluck('clave')->all();
        $this->assertContains('finanzas', $claves);
    }

    public function test_un_supervisor_lo_escoge_por_otra_persona_y_la_persona_no_apaga_avisos(): void
    {
        $this->actingAs($this->admin)->putJson("/api/usuarios/{$this->paola->id}/notificaciones", [
            'urgentes' => ['solicitudes' => true],
        ])->assertOk()->assertJsonFragment(['clave' => 'solicitudes', 'urgente' => true]);
        $this->assertSame(['menciones', 'solicitudes'], $this->paola->fresh()->notificaciones_urgentes);

        // Desde su perfil solo se escogen urgentes: apagar sigue siendo del supervisor
        $this->actingAs($this->paola)->putJson('/api/auth/mis-notificaciones', ['grupos' => ['ventas' => false]])
            ->assertStatus(422);
        $this->actingAs($this->paola)->putJson("/api/usuarios/{$this->admin->id}/notificaciones", [
            'urgentes' => ['ventas' => true],
        ])->assertStatus(403);
        $this->actingAs($this->paola)->putJson('/api/auth/mis-notificaciones', ['urgentes' => ['inventado' => true]])
            ->assertStatus(422);
    }

    public function test_leer_todas_no_despega_las_urgentes(): void
    {
        $urgente = Notificacion::create(['usuario_id' => $this->admin->id, 'tipo' => 'orden_mensaje', 'titulo' => 'u', 'mensaje' => 'x', 'urgente' => true]);
        $normal  = Notificacion::create(['usuario_id' => $this->admin->id, 'tipo' => 'venta_nueva', 'titulo' => 'n', 'mensaje' => 'x']);

        $this->actingAs($this->admin)->patchJson('/api/notificaciones/leer-todas')->assertOk();

        $this->assertFalse($urgente->fresh()->leida);
        $this->assertTrue($normal->fresh()->leida);
    }

    public function test_ver_el_chat_da_por_vistos_sus_avisos_de_esa_orden(): void
    {
        $this->escribir($this->paola, ['mensaje' => '¿Hay tela?', 'mencionados' => [$this->admin->id]]);
        $otraOrden = Orden::forceCreate(['vendedor_id' => $this->paola->id, 'estado' => 'en_produccion']);
        $deOtra = Notificacion::create([
            'usuario_id' => $this->admin->id, 'tipo' => 'orden_mensaje', 'titulo' => 'x', 'mensaje' => 'x',
            'urgente' => true, 'datos' => ['orden_id' => $otraOrden->id],
        ]);

        $this->actingAs($this->admin)->postJson("/api/ordenes/{$this->orden->id}/mensajes/visto")
            ->assertOk()->assertJsonCount(1, 'leidas');

        $this->assertTrue(Notificacion::where('usuario_id', $this->admin->id)->where('id', '!=', $deOtra->id)->sole()->leida);
        $this->assertFalse($deOtra->fresh()->leida);
        // Marcar visto no borra nada
        $this->assertSame(2, Notificacion::where('usuario_id', $this->admin->id)->count());

        // Quien no participa del chat no puede
        $this->actingAs($this->conductor)->postJson("/api/ordenes/{$this->orden->id}/mensajes/visto")->assertStatus(403);
    }
}
