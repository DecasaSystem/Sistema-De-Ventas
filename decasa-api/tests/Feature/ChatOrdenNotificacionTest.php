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
            && $this->del($job, 'datos')['orden_id'] === $this->orden->id
            && $this->del($job, 'urgente') === true);

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

    public function test_al_que_le_preguntaron_le_sigue_llegando_aunque_no_haya_contestado(): void
    {
        Queue::fake();

        $this->escribir($this->paola, ['mensaje' => '¿Hay tela?', 'mencionados' => [$this->admin->id]]);
        // Admin no contesta; Paola insiste sin arrobar
        $this->escribir($this->paola, ['mensaje' => '¿?'])->assertCreated();

        $avisos = Notificacion::where('usuario_id', $this->admin->id)->orderBy('id')->get();
        $this->assertCount(2, $avisos);
        $this->assertSame('Paola: ¿?', $avisos[1]->mensaje);
        // Al otro supervisor, que nadie arrobó, sigue sin llegarle
        $this->assertSame(0, Notificacion::where('usuario_id', $this->otroSup->id)->count());
    }

    public function test_mencion_como_texto_dice_te_preguntaron_y_no_avisa_a_inactivos(): void
    {
        Queue::fake();
        $inactivo = Usuario::forceCreate(['nombre' => 'Ex', 'rol' => 'supervisor', 'activo' => false]);

        $this->escribir($this->paola, [
            'mensaje' => 'Hola', 'mencionados' => [(string) $this->admin->id, $inactivo->id],
        ])->assertCreated();

        $this->assertSame('Te preguntaron en la orden FV2-150',
            Notificacion::where('usuario_id', $this->admin->id)->sole()->titulo);
        $this->assertSame(0, Notificacion::where('usuario_id', $inactivo->id)->count());
    }

    public function test_una_foto_sola_tambien_avisa(): void
    {
        Queue::fake();

        $this->escribir($this->paola, ['imagen_url' => 'https://x/foto.jpg', 'mencionados' => [$this->admin->id]])
            ->assertCreated();

        $this->assertSame('Paola: te mandó una foto',
            Notificacion::where('usuario_id', $this->admin->id)->sole()->mensaje);
    }

    public function test_varias_fotos_en_un_mismo_mensaje(): void
    {
        Queue::fake();
        Schema::table('orden_mensajes', fn (Blueprint $t) => $t->json('imagenes')->nullable());
        \App\Support\CachesDePeticion::olvidarTodo();

        $fotos = ['https://x/tela.jpg', 'https://x/veta.jpg', 'https://x/golpe.jpg'];
        $this->escribir($this->paola, ['mensaje' => 'Así llegó', 'imagenes' => $fotos, 'mencionados' => [$this->admin->id]])
            ->assertCreated()
            ->assertJsonPath('imagenes', $fotos)
            // La primera sigue en la columna de siempre.
            ->assertJsonPath('imagen_url', 'https://x/tela.jpg');

        $this->actingAs($this->admin)->getJson("/api/ordenes/{$this->orden->id}/mensajes")
            ->assertJsonPath('mensajes.0.imagenes', $fotos);

        // Solo fotos, sin texto, también vale; el aviso dice cuántas.
        $this->escribir($this->paola, ['imagenes' => ['https://x/a.jpg', 'https://x/b.jpg'], 'mencionados' => [$this->admin->id]])->assertCreated();
        $this->assertSame('Paola: te mandó 2 fotos',
            Notificacion::where('usuario_id', $this->admin->id)->latest('id')->first()->mensaje);

        // Nada que no sea una foto por https.
        $this->escribir($this->paola, ['imagenes' => ['javascript:alert(1)']])->assertStatus(422);
        $this->escribir($this->paola, ['imagenes' => array_fill(0, 7, 'https://x/a.jpg')])->assertStatus(422);
    }

    public function test_produccion_participa_y_un_conductor_no(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('ve_todas_ordenes')->default(false);
        });
        $taller    = Usuario::forceCreate(['nombre' => 'Don Jairo', 'rol' => 'taller', 'gestiona_produccion' => true]);
        $conductor = Usuario::forceCreate(['nombre' => 'Wilson', 'rol' => 'conductor']);

        $this->actingAs($taller)->getJson("/api/ordenes/{$this->orden->id}/mensajes")->assertOk();
        $this->escribir($taller, ['mensaje' => 'La tela llega el lunes'])->assertCreated();

        $this->actingAs($conductor)->getJson("/api/ordenes/{$this->orden->id}/mensajes")->assertForbidden();
    }

    /**
     * Henry (independiente) vende y comparte con El Edén. La venta es de los
     * dos: el supervisor arroba a Henry y a los de El Edén (pedido del
     * usuario, 2026-11-25). Los de otra tienda no salen.
     */
    private function ventaCompartidaConElEden(): array
    {
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_independientes')->default(false); $t->timestamps();
        });
        Schema::table('usuarios', fn (Blueprint $t) => $t->boolean('independiente')->default(false));
        Schema::table('ordenes', fn (Blueprint $t) => $t->unsignedBigInteger('tienda_id')->nullable());
        \App\Support\CachesDePeticion::olvidarTodo();
        Orden::olvidarSedes();

        $sede  = \App\Models\Tienda::forceCreate(['nombre' => 'Independientes', 'es_independientes' => true]);
        $eden  = \App\Models\Tienda::forceCreate(['nombre' => 'El Edén']);
        $norte = \App\Models\Tienda::forceCreate(['nombre' => 'Norte']);

        $henry   = Usuario::forceCreate(['nombre' => 'Henry', 'rol' => 'vendedor', 'independiente' => true, 'tienda_default_id' => $sede->id]);
        $gladys  = Usuario::forceCreate(['nombre' => 'Gladys', 'rol' => 'vendedor', 'tienda_default_id' => $eden->id]);
        $sebas   = Usuario::forceCreate(['nombre' => 'Sebastián', 'rol' => 'vendedor', 'tienda_default_id' => $eden->id]);
        $martha  = Usuario::forceCreate(['nombre' => 'Martha', 'rol' => 'vendedor', 'tienda_default_id' => $norte->id]);

        $this->orden->forceFill([
            'vendedor_id' => $henry->id, 'tienda_id' => $sede->id, 'tienda_abonada_id' => $eden->id,
        ])->save();

        return compact('henry', 'gladys', 'sebas', 'martha', 'eden');
    }

    public function test_en_una_venta_compartida_se_puede_arrobar_al_independiente_y_a_la_tienda(): void
    {
        ['henry' => $henry, 'gladys' => $gladys, 'sebas' => $sebas, 'martha' => $martha] = $this->ventaCompartidaConElEden();

        $dest = collect($this->actingAs($this->admin)->getJson("/api/ordenes/{$this->orden->id}/mensajes")
            ->assertOk()->json('destinatarios'))->keyBy('id');

        $this->assertTrue($dest[$henry->id]['es_de_la_orden']);
        $this->assertSame('compartida', $dest[$gladys->id]['grupo']);
        $this->assertSame('El Edén', $dest[$sebas->id]['tienda']);
        $this->assertArrayNotHasKey($martha->id, $dest->all());   // otra tienda
        $this->assertArrayHasKey($this->otroSup->id, $dest->all());

        // Y Gladys, de la tienda compartida, también arroba a Henry.
        $destGladys = collect($this->actingAs($gladys)->getJson("/api/ordenes/{$this->orden->id}/mensajes")
            ->assertOk()->json('destinatarios'))->pluck('id');
        $this->assertContains($henry->id, $destGladys);
        $this->assertContains($sebas->id, $destGladys);

        Queue::fake();
        $this->escribir($this->admin, [
            'mensaje' => '¿Quién le confirma la tela?', 'mencionados' => [$henry->id, $gladys->id, $sebas->id],
        ])->assertCreated();

        foreach ([$henry, $gladys, $sebas] as $u) {
            $this->assertSame('Te preguntaron en la orden FV2-150',
                Notificacion::where('usuario_id', $u->id)->sole()->titulo);
        }
    }

    public function test_no_se_puede_arrobar_a_quien_no_puede_ver_el_chat(): void
    {
        ['martha' => $martha] = $this->ventaCompartidaConElEden();
        Queue::fake();

        // Por la API, Martha (de otra tienda) no recibe el texto.
        $this->escribir($this->admin, ['mensaje' => 'Hola', 'mencionados' => [$martha->id]])->assertCreated()
            ->assertJsonPath('mencionados', []);
        $this->assertSame(0, Notificacion::where('usuario_id', $martha->id)->count());
    }

    public function test_quien_ya_no_puede_ver_la_orden_deja_de_recibir_los_mensajes(): void
    {
        ['gladys' => $gladys, 'eden' => $eden] = $this->ventaCompartidaConElEden();
        Queue::fake();

        $this->escribir($gladys, ['mensaje' => 'Yo le confirmo'])->assertCreated();

        // Gladys se pasa a otra tienda: ya no es de la venta compartida.
        $otra = \App\Models\Tienda::forceCreate(['nombre' => 'Unicentro']);
        $gladys->forceFill(['tienda_default_id' => $otra->id])->save();

        $this->escribir($this->admin, ['mensaje' => '¿Ya le confirmaron?'])->assertCreated();
        $this->assertSame(0, Notificacion::where('usuario_id', $gladys->id)->count());
    }

    public function test_una_nota_automatica_va_marcada_y_no_mete_a_la_persona_en_la_conversacion(): void
    {
        Schema::table('orden_mensajes', fn (Blueprint $t) => $t->boolean('es_sistema')->default(false));
        \App\Support\CachesDePeticion::olvidarTodo();
        Queue::fake();

        // Carlos (supervisor) cancela un producto: queda la nota a su nombre.
        \App\Models\OrdenMensaje::delSistema([
            'orden_id' => $this->orden->id, 'usuario_id' => $this->otroSup->id,
            'mensaje'  => 'Se canceló la silla.',
        ]);

        $this->actingAs($this->admin)->getJson("/api/ordenes/{$this->orden->id}/mensajes")
            ->assertOk()
            ->assertJsonPath('mensajes.0.es_sistema', true)
            ->assertJsonPath('mensajes.0.usuario.nombre', 'Carlos');

        // Después Paola pregunta algo: a Carlos no le llega por haber cancelado.
        $this->escribir($this->paola, ['mensaje' => '¿Y la mesa?'])->assertCreated()
            ->assertJsonPath('es_sistema', false);
        $this->assertSame(0, Notificacion::where('usuario_id', $this->otroSup->id)->count());

        // Lo que escribe una persona sí la mete, como siempre.
        $this->escribir($this->otroSup, ['mensaje' => 'La mesa sigue'])->assertCreated();
        $this->escribir($this->paola, ['mensaje' => 'Gracias'])->assertCreated();
        $this->assertSame(1, Notificacion::where('usuario_id', $this->otroSup->id)->count());
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
