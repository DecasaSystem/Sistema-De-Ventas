<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ClienteRed;
use App\Models\ConversacionWa;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Clientes → Redes (dueño, 2026-10-08): antes de pasar a alguien con un asesor,
 * Elena le pide nombre y celular, y eso queda como ficha del cliente de redes.
 *
 * - Cada aviso del agente crea o actualiza UNA ficha por persona y canal.
 * - Lo que llega del chat se limpia (celular normalizado) y lo vacío no borra lo
 *   que ya se sabía.
 * - La ficha nunca tumba la tarjeta: un contacto raro no da 422.
 * - El equipo cambia el estado, pone notas y la convierte en cliente sin duplicar.
 *
 * La tabla clientes_redes se crea con su migración real (así se prueba también en
 * SQLite); el resto del esquema se monta a mano como en RedesWebhookAgentesTest.
 */
class ClientesRedesTest extends TestCase
{
    private Usuario $supervisora;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('acceso_redes')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('conversaciones_wa', function (Blueprint $t) {
            $t->id(); $t->string('hash_idempotencia', 64)->nullable()->unique();
            $t->string('tipo'); $t->string('telefono'); $t->string('nombre_cliente')->nullable();
            $t->text('resumen'); $t->json('historial')->nullable(); $t->json('carrito')->nullable();
            $t->json('datos_cita')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('whatsapp_url')->nullable(); $t->string('contacto_url')->nullable();
            $t->string('fuente')->default('whatsapp'); $t->string('estado')->default('pendiente');
            $t->unsignedBigInteger('tomada_por')->nullable(); $t->timestamp('tomada_at')->nullable();
            $t->timestamp('terminada_at')->nullable(); $t->timestamp('archivada_at')->nullable(); $t->timestamps();
        });
        Schema::create('citas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('conversacion_wa_id')->nullable(); $t->unsignedBigInteger('cita_agente_id')->nullable();
            $t->unsignedBigInteger('asesor_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('nombre_cliente')->nullable(); $t->string('telefono')->nullable();
            $t->string('contacto_url')->nullable(); $t->string('fuente')->default('whatsapp');
            $t->string('dia'); $t->string('hora'); $t->string('motivo')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('notas')->nullable();
            $t->date('fecha_cita')->nullable(); $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre', 120); $t->string('cedula', 20)->nullable();
            $t->string('telefono', 20)->nullable(); $t->string('email', 120)->nullable();
            $t->string('direccion', 200)->nullable(); $t->string('canal_pref')->nullable();
            $t->string('tipo')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->json('categorias_interes')->nullable(); $t->text('notas_interes')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        (require database_path('migrations/2026_10_16_000001_create_clientes_redes_table.php'))->up();

        $this->prestarleASqliteLoQueEsDeMysql();
        config(['app.agent_token' => 'secreto-de-prueba']);
        $this->supervisora = Usuario::forceCreate(['nombre' => 'Marta', 'rol' => 'supervisor', 'activo' => true]);
    }

    private function avisar(array $datos)
    {
        return $this->withHeader('X-Agent-Token', 'secreto-de-prueba')
            ->postJson('/api/redes/webhook', array_merge([
                'tipo' => 'asesor', 'telefono' => '+573001112233', 'resumen' => 'Quiere hablar con alguien',
                'fuente' => 'whatsapp',
            ], $datos));
    }

    public function test_el_aviso_con_contacto_crea_la_ficha_del_cliente_de_redes(): void
    {
        $this->avisar([
            'idempotencia' => 'a1',
            'nombre_cliente' => '💕Caro💕',
            'contacto' => [
                'nombre' => 'Carolina Ruiz', 'telefono' => '310 555 1234', 'ciudad' => 'Armenia',
                'presupuesto' => 3000000, 'espacio' => 'sala', 'productos_interes' => ['SOFA ROMA'],
            ],
        ])->assertStatus(201);

        $ficha = ClienteRed::sole();
        $this->assertSame('whatsapp', $ficha->canal);
        $this->assertSame('+573001112233', $ficha->identificador);
        $this->assertSame('Carolina Ruiz', $ficha->nombre);
        $this->assertSame('+573105551234', $ficha->telefono);
        $this->assertSame('Armenia', $ficha->ciudad);
        $this->assertSame(3000000, $ficha->presupuesto);
        $this->assertSame(['SOFA ROMA'], $ficha->productos_interes);
        $this->assertSame('nuevo', $ficha->estado);
        $this->assertSame(1, $ficha->total_conversaciones);
        $this->assertSame(ConversacionWa::sole()->id, $ficha->ultima_conversacion_id);
    }

    public function test_un_segundo_aviso_actualiza_la_misma_ficha_sin_borrar_lo_que_ya_dijo(): void
    {
        $this->avisar(['idempotencia' => 'a1', 'contacto' => ['nombre' => 'Carolina', 'ciudad' => 'Armenia']]);
        $this->avisar(['idempotencia' => 'a2', 'tipo' => 'pedido', 'resumen' => 'Pedido: CAMA MIAMI',
            'contacto' => ['telefono' => '+57 320 999 8877', 'forma_pago' => 'efectivo']]);

        $ficha = ClienteRed::sole();
        $this->assertSame('Carolina', $ficha->nombre);
        $this->assertSame('Armenia', $ficha->ciudad, 'un aviso sin ciudad no borra la que dio antes');
        $this->assertSame('+573209998877', $ficha->telefono);
        $this->assertSame('efectivo', $ficha->forma_pago);
        $this->assertSame('pedido', $ficha->ultimo_tipo);
        $this->assertSame(2, $ficha->total_conversaciones);
    }

    public function test_el_reintento_del_mismo_aviso_no_cuenta_dos_veces(): void
    {
        $this->avisar(['idempotencia' => 'mismo'])->assertStatus(201);
        $this->avisar(['idempotencia' => 'mismo'])->assertStatus(200);

        $this->assertSame(1, ClienteRed::sole()->total_conversaciones);
    }

    public function test_agente_viejo_sin_contacto_igual_crea_la_ficha_con_el_numero_del_chat(): void
    {
        $this->avisar(['idempotencia' => 'v1', 'nombre_cliente' => 'Ana María'])->assertStatus(201);

        $ficha = ClienteRed::sole();
        $this->assertSame('Ana María', $ficha->nombre);
        $this->assertSame('+573001112233', $ficha->telefono);
    }

    public function test_en_instagram_sin_celular_la_ficha_queda_sin_telefono_y_con_el_usuario(): void
    {
        $this->avisar([
            'idempotencia' => 'ig1', 'fuente' => 'instagram', 'telefono' => 'ig_99887766',
            'contacto_url' => 'https://ig.me/m/laura.g',
            'contacto' => ['nombre' => 'Laura', 'usuario_red' => '@laura.g', 'no_quiso_dar_datos' => true],
        ])->assertStatus(201);

        $ficha = ClienteRed::sole();
        $this->assertSame('instagram', $ficha->canal);
        $this->assertNull($ficha->telefono, 'el ig_<psid> no es un teléfono');
        $this->assertSame('@laura.g', $ficha->usuario_red);
        $this->assertSame('https://ig.me/m/laura.g', $ficha->contacto_url);
    }

    public function test_un_contacto_raro_no_tumba_la_tarjeta(): void
    {
        $this->avisar([
            'idempotencia' => 'raro',
            'contacto' => ['nombre' => ['no', 'es', 'texto'], 'telefono' => 'no sé', 'presupuesto' => 'mucho'],
        ])->assertStatus(201);

        $this->assertSame(1, ConversacionWa::count());
        $ficha = ClienteRed::sole();
        $this->assertNull($ficha->presupuesto);
        $this->assertSame('+573001112233', $ficha->telefono, 'se queda con el número del chat');
    }

    public function test_un_proveedor_no_es_un_cliente(): void
    {
        $this->avisar(['idempotencia' => 'prov', 'resumen' => "Solicitud de asesor\nPROVEEDOR / PROPUESTA COMERCIAL 🏭\nVenden telas"])
            ->assertStatus(201);

        $this->assertSame(0, ClienteRed::count());
    }

    public function test_si_ya_existe_un_cliente_con_ese_celular_se_enlaza(): void
    {
        $cliente = Cliente::create(['nombre' => 'Carolina Ruiz', 'telefono' => '310 555-1234']);

        $this->avisar(['idempotencia' => 'c1', 'contacto' => ['nombre' => 'Caro', 'telefono' => '3105551234']]);

        $this->assertSame($cliente->id, ClienteRed::sole()->cliente_id);
    }

    public function test_quien_ya_compro_y_vuelve_a_escribir_es_una_oportunidad_nueva(): void
    {
        $this->avisar(['idempotencia' => 'x1']);
        ClienteRed::query()->update(['estado' => 'compro']);

        $this->avisar(['idempotencia' => 'x2']);
        $this->assertSame('nuevo', ClienteRed::sole()->estado);

        // Pero si alguien lo está trabajando, se respeta.
        ClienteRed::query()->update(['estado' => 'contactado']);
        $this->avisar(['idempotencia' => 'x3']);
        $this->assertSame('contactado', ClienteRed::sole()->estado);
    }

    public function test_listado_con_filtros_y_conteos_por_estado(): void
    {
        $this->avisar(['idempotencia' => 'l1', 'telefono' => '+573001110001', 'contacto' => ['nombre' => 'Ana']]);
        $this->avisar(['idempotencia' => 'l2', 'telefono' => '+573001110002', 'contacto' => ['nombre' => 'Beto']]);
        $this->avisar(['idempotencia' => 'l3', 'fuente' => 'instagram', 'telefono' => 'ig_1', 'contacto' => ['nombre' => 'Caro']]);
        ClienteRed::where('nombre', 'Beto')->update(['estado' => 'contactado']);

        $r = $this->actingAs($this->supervisora)->getJson('/api/clientes-redes?estado=nuevo')->assertOk();
        $this->assertSame(['Caro', 'Ana'], collect($r->json('data'))->pluck('nombre')->sortDesc()->values()->all());
        $this->assertSame(2, $r->json('conteos.nuevo'));
        $this->assertSame(1, $r->json('conteos.contactado'));

        $r = $this->actingAs($this->supervisora)->getJson('/api/clientes-redes?canal=instagram')->assertOk();
        $this->assertSame(['Caro'], collect($r->json('data'))->pluck('nombre')->all());

        $r = $this->actingAs($this->supervisora)->getJson('/api/clientes-redes?search=1110002')->assertOk();
        $this->assertSame(['Beto'], collect($r->json('data'))->pluck('nombre')->all());
    }

    public function test_la_ficha_trae_sus_tarjetas_de_redes(): void
    {
        $this->avisar(['idempotencia' => 'f1', 'resumen' => 'Primera']);
        $this->avisar(['idempotencia' => 'f2', 'resumen' => 'Segunda']);
        $id = ClienteRed::sole()->id;

        $r = $this->actingAs($this->supervisora)->getJson("/api/clientes-redes/{$id}")->assertOk();
        $this->assertCount(2, $r->json('conversaciones'));
    }

    public function test_cambiar_estado_notas_y_celular(): void
    {
        $this->avisar(['idempotencia' => 'u1']);
        $id = ClienteRed::sole()->id;

        $this->actingAs($this->supervisora)->putJson("/api/clientes-redes/{$id}", [
            'estado' => 'contactado', 'notas' => 'Le escribí, va el sábado', 'telefono' => '300 444 5566',
        ])->assertOk()->assertJsonPath('telefono', '+573004445566');

        $this->actingAs($this->supervisora)->putJson("/api/clientes-redes/{$id}", ['estado' => 'inventado'])->assertStatus(422);
        $this->actingAs($this->supervisora)->putJson("/api/clientes-redes/{$id}", ['telefono' => 'abc'])->assertStatus(422);
        $this->assertSame('contactado', ClienteRed::sole()->estado);
    }

    public function test_convertir_en_cliente_sin_duplicar(): void
    {
        $this->avisar(['idempotencia' => 'cv1', 'contacto' => ['nombre' => 'Carolina', 'telefono' => '3105551234', 'espacio' => 'sala']]);
        $id = ClienteRed::sole()->id;

        $r = $this->actingAs($this->supervisora)->postJson("/api/clientes-redes/{$id}/convertir")->assertStatus(201);
        $cliente = Cliente::findOrFail($r->json('cliente_id'));
        $this->assertSame('Carolina', $cliente->nombre);
        $this->assertSame('interesado', $cliente->tipo);
        $this->assertSame('whatsapp', $cliente->canal_pref);
        $this->assertStringContainsString('Para: sala', $cliente->notas_interes);

        // La segunda vez devuelve el mismo, no crea otro.
        $this->actingAs($this->supervisora)->postJson("/api/clientes-redes/{$id}/convertir")
            ->assertOk()->assertJsonPath('cliente_id', $cliente->id);
        $this->assertSame(1, Cliente::count());
    }

    public function test_sin_permiso_de_redes_no_se_ve(): void
    {
        $vendedor = Usuario::forceCreate(['nombre' => 'Luis', 'rol' => 'vendedor', 'activo' => true, 'acceso_redes' => false]);
        $this->actingAs($vendedor)->getJson('/api/clientes-redes')->assertStatus(403);

        $conRedes = Usuario::forceCreate(['nombre' => 'Paola', 'rol' => 'vendedor', 'activo' => true, 'acceso_redes' => true]);
        $this->actingAs($conRedes)->getJson('/api/clientes-redes')->assertOk();
    }

    public function test_un_vendedor_ve_los_de_su_tienda_y_los_sin_tienda(): void
    {
        $this->avisar(['idempotencia' => 't1', 'telefono' => '+573001110001', 'contacto' => ['nombre' => 'Sin tienda']]);
        $this->avisar(['idempotencia' => 't2', 'telefono' => '+573001110002', 'contacto' => ['nombre' => 'De la 2']]);
        ClienteRed::where('nombre', 'De la 2')->update(['tienda_id' => 2]);

        $deLa1 = Usuario::forceCreate(['nombre' => 'Paola', 'rol' => 'vendedor', 'activo' => true, 'acceso_redes' => true, 'tienda_default_id' => 1]);
        $r = $this->actingAs($deLa1)->getJson('/api/clientes-redes')->assertOk();
        $this->assertSame(['Sin tienda'], collect($r->json('data'))->pluck('nombre')->all());
    }
}
