<?php

namespace Tests\Feature;

use App\Jobs\EnviarPush;
use App\Models\Notificacion;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El chat de la orden avisa a quien arroban, con el mensaje, como WhatsApp.
 *
 * Y los avisos se quedan: leerlos no los borra, nadie más los puede borrar, y
 * los viejos se siguen pudiendo ver aunque haya muchos nuevos encima.
 */
class ChatOrdenNotificacionTest extends TestCase
{
    private Usuario $paola;
    private Usuario $admin;
    private Usuario $otroSup;
    private Orden $orden;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
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

        $this->paola   = Usuario::forceCreate(['nombre' => 'Paola', 'rol' => 'vendedor']);
        $this->admin   = Usuario::forceCreate(['nombre' => 'Admin', 'rol' => 'supervisor']);
        $this->otroSup = Usuario::forceCreate(['nombre' => 'Carlos', 'rol' => 'supervisor']);
        $this->orden   = Orden::forceCreate([
            'vendedor_id' => $this->paola->id, 'estado' => 'en_produccion',
            'serie' => 'FV2', 'serie_numero' => 150,
        ]);
    }

    private function escribir(Usuario $quien, array $body)
    {
        return $this->actingAs($quien)->postJson("/api/ordenes/{$this->orden->id}/mensajes", $body);
    }

    /** Lee una propiedad privada del job de push. */
    private function del(EnviarPush $job, string $prop)
    {
        return (fn () => $this->{$prop})->call($job);
    }

    public function test_al_arrobado_le_llega_la_notificacion_con_el_mensaje(): void
    {
        Queue::fake();

        $this->escribir($this->paola, [
            'mensaje'     => '¿Se le puede hacer 10% al cliente?',
            'mencionados' => [$this->admin->id],
        ])->assertCreated();

        $n = Notificacion::where('usuario_id', $this->admin->id)->sole();
        $this->assertSame('orden_mensaje', $n->tipo);
        $this->assertSame('Te preguntaron en la orden FV2-150', $n->titulo);
        $this->assertSame('Paola: ¿Se le puede hacer 10% al cliente?', $n->mensaje);
        $this->assertSame($this->orden->id, $n->datos['orden_id']);

        // Y al teléfono, con el mismo texto
        Queue::assertPushed(EnviarPush::class, fn ($job) =>
            $this->del($job, 'usuarioId') === $this->admin->id
            && $this->del($job, 'titulo') === 'Te preguntaron en la orden FV2-150'
            && $this->del($job, 'cuerpo') === 'Paola: ¿Se le puede hacer 10% al cliente?'
            && $this->del($job, 'tipo') === 'orden_mensaje'
            && $this->del($job, 'datos') === ['orden_id' => $this->orden->id]);

        // Al supervisor que nadie arrobó no se le molesta, ni a quien escribe
        $this->assertSame(0, Notificacion::where('usuario_id', $this->otroSup->id)->count());
        $this->assertSame(0, Notificacion::where('usuario_id', $this->paola->id)->count());
    }

    public function test_la_respuesta_sin_arrobar_tambien_le_llega_al_que_pregunto(): void
    {
        Queue::fake();

        $this->escribir($this->paola, ['mensaje' => '¿Hay tela?', 'mencionados' => [$this->admin->id]]);
        $this->escribir($this->admin, ['mensaje' => 'Sí, hay']);

        $n = Notificacion::where('usuario_id', $this->paola->id)->sole();
        $this->assertSame('Mensaje nuevo en la orden FV2-150', $n->titulo);
        $this->assertSame('Admin: Sí, hay', $n->mensaje);
    }

    public function test_una_foto_sola_tambien_avisa(): void
    {
        Queue::fake();

        $this->escribir($this->paola, ['imagen_url' => 'https://x/foto.jpg', 'mencionados' => [$this->admin->id]])
            ->assertCreated();

        $this->assertSame('Paola: te mandó una foto',
            Notificacion::where('usuario_id', $this->admin->id)->sole()->mensaje);
    }

    public function test_leer_la_notificacion_no_la_borra(): void
    {
        Queue::fake();
        $this->escribir($this->paola, ['mensaje' => 'Hola', 'mencionados' => [$this->admin->id]]);
        $n = Notificacion::where('usuario_id', $this->admin->id)->sole();

        $this->actingAs($this->admin)->patchJson("/api/notificaciones/{$n->id}/leida")->assertOk();
        $this->actingAs($this->admin)->patchJson('/api/notificaciones/leer-todas')->assertOk();

        $this->assertTrue($n->fresh()->leida);
        $this->actingAs($this->admin)->getJson('/api/notificaciones')
            ->assertOk()->assertJsonPath('items.0.id', $n->id);
    }

    public function test_nadie_mas_puede_borrar_la_notificacion_de_otro(): void
    {
        Queue::fake();
        $this->escribir($this->paola, ['mensaje' => 'Hola', 'mencionados' => [$this->admin->id]]);
        $n = Notificacion::where('usuario_id', $this->admin->id)->sole();

        // Ni otro supervisor, ni el que escribió el mensaje
        $this->actingAs($this->otroSup)->deleteJson("/api/notificaciones/{$n->id}")->assertForbidden();
        $this->actingAs($this->paola)->deleteJson("/api/notificaciones/{$n->id}")->assertForbidden();
        // "Limpiar" de otro solo borra las suyas
        $this->actingAs($this->otroSup)->deleteJson('/api/notificaciones/todas')->assertOk();

        $this->assertNotNull($n->fresh());

        // El dueño sí
        $this->actingAs($this->admin)->deleteJson("/api/notificaciones/{$n->id}")->assertOk();
        $this->assertNull($n->fresh());
    }

    public function test_las_viejas_se_siguen_pudiendo_ver_aunque_haya_muchas_nuevas(): void
    {
        $vieja = Notificacion::create([
            'usuario_id' => $this->admin->id, 'tipo' => 'orden_mensaje',
            'titulo' => 'La vieja', 'mensaje' => 'x', 'datos' => ['orden_id' => 1],
        ]);
        for ($i = 0; $i < 60; $i++) {
            Notificacion::create(['usuario_id' => $this->admin->id, 'tipo' => 'x', 'titulo' => "n{$i}", 'mensaje' => 'x']);
        }

        $pag1 = $this->actingAs($this->admin)->getJson('/api/notificaciones')->assertOk();
        $this->assertCount(50, $pag1->json('items'));
        $this->assertTrue($pag1->json('hay_mas'));
        $this->assertSame(61, $pag1->json('no_leidas'));

        $pag2 = $this->actingAs($this->admin)
            ->getJson('/api/notificaciones?antes_de=' . $pag1->json('siguiente'))->assertOk();
        $this->assertFalse($pag2->json('hay_mas'));
        $this->assertContains($vieja->id, collect($pag2->json('items'))->pluck('id'));
    }

    public function test_una_urgente_sin_leer_no_queda_enterrada(): void
    {
        $urgente = Notificacion::create([
            'usuario_id' => $this->admin->id, 'tipo' => 'solicitud_cambio',
            'titulo' => 'Aprobar cambio', 'mensaje' => 'x', 'urgente' => true,
        ]);
        for ($i = 0; $i < 60; $i++) {
            Notificacion::create(['usuario_id' => $this->admin->id, 'tipo' => 'x', 'titulo' => "n{$i}", 'mensaje' => 'x']);
        }

        $pag1 = $this->actingAs($this->admin)->getJson('/api/notificaciones')->assertOk();
        $this->assertContains($urgente->id, collect($pag1->json('items'))->pluck('id'));
    }
}
