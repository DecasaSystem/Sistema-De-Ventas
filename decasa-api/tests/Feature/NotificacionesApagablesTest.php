<?php

namespace Tests\Feature;

use App\Events\NuevaNotificacion;
use App\Jobs\EnviarPush;
use App\Models\Usuario;
use App\Services\NotificacionService;
use App\Support\TiposNotificacion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Trabajadores → Notificaciones: apagar grupos de avisos por persona.
 *
 * Pedido del dueño: hay supervisores que no necesitan los cambios de plata,
 * los atrasados ni los próximos a entregar, pero sí las ventas nuevas y los
 * chats. Lo apagado no se guarda ni sale al celular; a los demás les llega igual.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class NotificacionesApagablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('notif_asignar_fecha')->default(true); $t->boolean('notif_stock')->default(false);
            $t->json('notificaciones_apagadas')->nullable();
            $t->timestamp('created_at')->nullable(); $t->timestamp('updated_at')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo');
            $t->string('titulo'); $t->text('mensaje'); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });

        Event::fake([NuevaNotificacion::class]);
        Queue::fake();
    }

    private function usuario(string $nombre, string $rol = 'supervisor', array $extra = []): Usuario
    {
        // forceCreate: `notificaciones_apagadas` no es asignable en masa (solo
        // lo cambia NotificacionesUsuarioController).
        return Usuario::forceCreate(['nombre' => $nombre, 'email' => strtolower($nombre) . '@d.com', 'password' => 'x', 'rol' => $rol] + $extra);
    }

    private function avisosDe(Usuario $u): array
    {
        return DB::table('notificaciones')->where('usuario_id', $u->id)->pluck('tipo')->all();
    }

    /** Si alguien agrega un aviso nuevo sin grupo, no se podría apagar: que la prueba lo diga. */
    public function test_cada_tipo_de_aviso_del_codigo_tiene_grupo(): void
    {
        $tipos = [];
        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($archivos as $f) {
            if (substr($f, -4) !== '.php') continue;
            preg_match_all("/NotificacionService::crear\(\s*(?:tipo:\s*)?'([a-z_]+)'/", file_get_contents($f), $m);
            foreach ($m[1] as $t) $tipos[$t] = true;
        }
        $this->assertGreaterThan(40, count($tipos), 'No se encontraron los avisos: ¿cambió la forma de llamar a crear()?');

        $sinGrupo = array_filter(array_keys($tipos), fn ($t) => TiposNotificacion::grupoDe($t) === null);
        $this->assertSame([], array_values($sinGrupo), 'Agrega estos tipos a un grupo de TiposNotificacion.');
    }

    public function test_un_tipo_no_esta_en_dos_grupos(): void
    {
        $vistos = [];
        foreach (TiposNotificacion::GRUPOS as $clave => $g) {
            foreach ($g['tipos'] as $t) {
                $this->assertArrayNotHasKey($t, $vistos, "«{$t}» está en dos grupos (el otro es {$clave}).");
                $vistos[$t] = $clave;
            }
        }
    }

    public function test_al_supervisor_que_lo_apago_no_le_llega_y_a_los_demas_si(): void
    {
        $ana  = $this->usuario('Ana', 'supervisor', ['notificaciones_apagadas' => ['cambios_dinero', 'atrasados', 'por_entregar']]);
        $luis = $this->usuario('Luis');

        // Sin usuario = a todos los supervisores.
        NotificacionService::crear('descuento_revertido', 'Descuento perdido', 'Orden 1');
        NotificacionService::crear('venta_nueva', 'Nueva venta', 'Orden 2', [], $ana->id);
        NotificacionService::crear('retrasado', 'Atrasado', 'Orden 3', [], $ana->id);
        NotificacionService::crear('entrega_hoy', 'Hoy', 'Orden 4', [], $ana->id);
        NotificacionService::crear('orden_mensaje', 'Chat', 'Orden 5', [], $ana->id);
        NotificacionService::crear('retrasado', 'Atrasado', 'Orden 3', [], $luis->id);

        $this->assertSame(['venta_nueva', 'orden_mensaje'], $this->avisosDe($ana));
        $this->assertSame(['descuento_revertido', 'retrasado'], $this->avisosDe($luis));

        // Tampoco le sale al celular: un push por cada aviso guardado (2 de Ana, 2 de Luis).
        Queue::assertPushed(EnviarPush::class, 4);
    }

    public function test_si_todos_los_supervisores_lo_apagaron_no_se_guarda(): void
    {
        $this->usuario('Ana', 'supervisor', ['notificaciones_apagadas' => ['entregas']]);
        $this->assertNull(NotificacionService::crear('entregado', 'Entregada', 'Orden 1'));
        $this->assertSame(0, DB::table('notificaciones')->count());
    }

    public function test_un_tipo_sin_grupo_llega_siempre(): void
    {
        $ana = $this->usuario('Ana', 'supervisor', ['notificaciones_apagadas' => array_keys(TiposNotificacion::GRUPOS)]);
        NotificacionService::crear('algo_nuevo', 'Nuevo', 'x', [], $ana->id);
        $this->assertSame(['algo_nuevo'], $this->avisosDe($ana));
    }

    public function test_el_supervisor_ve_y_cambia_los_avisos_de_un_trabajador(): void
    {
        $jefe = $this->usuario('Jefe');
        $ana  = $this->usuario('Ana', 'vendedor');

        $grupos = collect($this->actingAs($jefe)->getJson("/api/usuarios/{$ana->id}/notificaciones")
            ->assertOk()->json('grupos'))->keyBy('clave');
        $this->assertCount(count(TiposNotificacion::GRUPOS), $grupos);
        $this->assertTrue($grupos['cambios_dinero']['prendido']);
        $this->assertFalse($grupos['agotados']['prendido']);   // al revés: solo a quien lo prende
        $this->assertNotNull($grupos['solicitudes']['aviso']);

        $grupos = collect($this->actingAs($jefe)->putJson("/api/usuarios/{$ana->id}/notificaciones", [
            'grupos' => ['cambios_dinero' => false, 'atrasados' => false, 'agotados' => true, 'asignar_fecha' => false],
        ])->assertOk()->json('grupos'))->keyBy('clave');

        $this->assertFalse($grupos['cambios_dinero']['prendido']);
        $this->assertFalse($grupos['atrasados']['prendido']);
        $this->assertTrue($grupos['ventas']['prendido']);
        $this->assertTrue($grupos['agotados']['prendido']);
        $ana->refresh();
        $this->assertSame(['cambios_dinero', 'atrasados'], $ana->notificaciones_apagadas);
        $this->assertTrue($ana->notif_stock);
        $this->assertFalse($ana->notif_asignar_fecha);

        // Prender de nuevo; lo que no se manda queda igual.
        $this->actingAs($jefe)->putJson("/api/usuarios/{$ana->id}/notificaciones", ['grupos' => ['cambios_dinero' => true]])->assertOk();
        $this->assertSame(['atrasados'], $ana->fresh()->notificaciones_apagadas);

        // Ya rige en la misma petición siguiente.
        NotificacionService::crear('retrasado', 'Atrasado', 'x', [], $ana->id);
        NotificacionService::crear('cambio_dinero', 'Plata', 'x', [], $ana->id);
        $this->assertSame(['cambio_dinero'], $this->avisosDe($ana));
    }

    public function test_grupo_que_no_existe_no_entra(): void
    {
        $jefe = $this->usuario('Jefe');
        $this->actingAs($jefe)->putJson("/api/usuarios/{$jefe->id}/notificaciones", ['grupos' => ['inventado' => false]])
            ->assertStatus(422);
    }

    public function test_un_vendedor_no_cambia_los_avisos_de_nadie(): void
    {
        $ana = $this->usuario('Ana', 'vendedor');
        $this->actingAs($ana)->getJson("/api/usuarios/{$ana->id}/notificaciones")->assertForbidden();
        $this->actingAs($ana)->putJson("/api/usuarios/{$ana->id}/notificaciones", ['grupos' => ['ventas' => false]])->assertForbidden();
    }
}
