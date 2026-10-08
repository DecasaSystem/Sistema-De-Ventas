<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\ConversacionWa;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que llega de los agentes de WhatsApp/Instagram al módulo Redes.
 *
 * - Un aviso que el agente reintenta (a los 6 s o desde su cola, horas
 *   después) es el MISMO aviso: no puede dar dos tarjetas. Antes solo se
 *   frenaba el repetido dentro del mismo minuto.
 * - Cuando el cliente cancela su cita con el agente, la cita del módulo Citas
 *   queda cancelada, y tomar la tarjeta de la cancelación (o la de la cita ya
 *   cancelada) no crea una cita "confirmada" de alguien que no va a ir.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RedesWebhookAgentesTest extends TestCase
{
    private Usuario $vendedora;

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

        $this->prestarleASqliteLoQueEsDeMysql();
        config(['app.agent_token' => 'secreto-de-prueba']);
        $this->vendedora = Usuario::forceCreate(['nombre' => 'Paola', 'rol' => 'vendedor', 'activo' => true]);
    }

    private function avisar(array $datos)
    {
        return $this->withHeader('X-Agent-Token', 'secreto-de-prueba')
            ->postJson('/api/redes/webhook', array_merge([
                'tipo' => 'asesor', 'telefono' => '+573001112233', 'resumen' => 'Quiere hablar con alguien',
            ], $datos));
    }

    public function test_el_reintento_con_la_misma_clave_no_crea_otra_tarjeta(): void
    {
        $this->avisar(['idempotencia' => 'clave-1'])->assertStatus(201);

        // El reintento desde la cola llega horas después: otro minuto, mismo aviso.
        $this->travel(3)->hours();
        $this->avisar(['idempotencia' => 'clave-1'])->assertStatus(200);

        $this->assertSame(1, ConversacionWa::count());
    }

    public function test_dos_avisos_distintos_con_el_mismo_texto_son_dos_tarjetas(): void
    {
        $this->avisar(['idempotencia' => 'clave-1'])->assertStatus(201);
        $this->avisar(['idempotencia' => 'clave-2'])->assertStatus(201);

        $this->assertSame(2, ConversacionWa::count());
    }

    public function test_sin_clave_sigue_frenando_el_repetido_del_mismo_minuto(): void
    {
        // Agente viejo, que todavía no manda la clave.
        $this->avisar([])->assertStatus(201);
        $this->avisar([])->assertStatus(200);

        $this->assertSame(1, ConversacionWa::count());
    }

    public function test_la_cancelacion_del_cliente_cancela_la_cita_del_modulo_citas(): void
    {
        $cita = Cita::create([
            'asesor_id' => $this->vendedora->id, 'telefono' => '+573001112233',
            'dia' => 'Jueves 9 de octubre', 'hora' => '10:00 am', 'estado' => 'confirmada',
        ]);
        // Otra cita del mismo cliente, otro día: no se toca.
        $otra = Cita::create([
            'asesor_id' => $this->vendedora->id, 'telefono' => '+573001112233',
            'dia' => 'Sábado 18 de octubre', 'hora' => '9:00 am', 'estado' => 'confirmada',
        ]);

        $this->avisar([
            'tipo' => 'cita', 'resumen' => 'CITA CANCELADA por el cliente', 'idempotencia' => 'c-1',
            'datos_cita' => ['cancelada' => true, 'cita_id' => 7, 'dia' => 'jueves 9 de octubre', 'hora' => '10:00 am', 'motivo' => 'Se le cruzó un viaje'],
        ])->assertStatus(201);

        $this->assertSame('cancelada', $cita->fresh()->estado);
        $this->assertStringContainsString('Se le cruzó un viaje', $cita->fresh()->notas);
        $this->assertSame('confirmada', $otra->fresh()->estado);
    }

    public function test_tomar_la_tarjeta_de_una_cancelacion_no_crea_una_cita(): void
    {
        $conv = $this->avisar([
            'tipo' => 'cita', 'resumen' => 'CITA CANCELADA por el cliente', 'idempotencia' => 'c-1',
            'datos_cita' => ['cancelada' => true, 'dia' => 'Jueves 9 de octubre', 'hora' => '10:00 am'],
        ])->json();

        $this->actingAs($this->vendedora)
            ->postJson("/api/redes/conversaciones/{$conv['id']}/tomar")
            ->assertOk()
            ->assertJson(['cita_creada' => false]);

        $this->assertSame(0, Cita::count());
    }

    public function test_tomar_una_cita_que_el_cliente_ya_cancelo_no_la_confirma(): void
    {
        $agendada = $this->avisar([
            'tipo' => 'cita', 'resumen' => 'Ana — Bolívar — Jueves 9 de octubre 10:00 am', 'idempotencia' => 'a-1',
            'datos_cita' => ['dia' => 'Jueves 9 de octubre', 'hora' => '10:00 am'],
        ])->json();
        // Nadie la tomó y el cliente canceló con el agente.
        $this->avisar([
            'tipo' => 'cita', 'resumen' => 'CITA CANCELADA por el cliente', 'idempotencia' => 'c-1',
            'datos_cita' => ['cancelada' => true, 'dia' => 'Jueves 9 de octubre', 'hora' => '10:00 am'],
        ]);

        $this->actingAs($this->vendedora)
            ->postJson("/api/redes/conversaciones/{$agendada['id']}/tomar")
            ->assertOk()
            ->assertJson(['cita_creada' => false]);

        $this->assertSame(0, Cita::count());
    }

    public function test_tomar_una_cita_normal_la_sigue_creando(): void
    {
        $agendada = $this->avisar([
            'tipo' => 'cita', 'resumen' => 'Ana — Bolívar — Jueves 9 de octubre 10:00 am', 'idempotencia' => 'a-1',
            'datos_cita' => ['dia' => 'Jueves 9 de octubre', 'hora' => '10:00 am'],
        ])->json();

        $this->actingAs($this->vendedora)
            ->postJson("/api/redes/conversaciones/{$agendada['id']}/tomar")
            ->assertOk()
            ->assertJson(['cita_creada' => true]);

        $this->assertSame('confirmada', Cita::firstOrFail()->estado);
    }

    public function test_la_cita_queda_enlazada_con_la_del_agente_y_con_su_fecha_real(): void
    {
        $agendada = $this->avisar([
            'tipo' => 'cita', 'resumen' => 'Ana — Bolívar', 'idempotencia' => 'a-1',
            // El texto no se puede leer como fecha; la fecha ISO manda.
            'datos_cita' => ['dia' => 'El jueves que viene', 'fecha' => '2026-10-09', 'hora' => '10:00 am', 'cita_agente_id' => 41],
        ])->json();

        $this->actingAs($this->vendedora)->postJson("/api/redes/conversaciones/{$agendada['id']}/tomar")->assertOk();

        $cita = Cita::firstOrFail();
        $this->assertSame(41, (int) $cita->cita_agente_id);
        $this->assertSame('2026-10-09', $cita->fecha_cita->toDateString());
    }

    public function test_la_cancelacion_se_cruza_por_id_aunque_el_texto_del_dia_no_coincida(): void
    {
        $suya = Cita::create([
            'asesor_id' => $this->vendedora->id, 'telefono' => '+573001112233', 'cita_agente_id' => 41,
            'dia' => 'El jueves que viene', 'hora' => '10:00 am', 'estado' => 'confirmada',
        ]);
        // Mismo cliente, mismo texto de día, pero OTRA cita del agente: no se toca.
        $otra = Cita::create([
            'asesor_id' => $this->vendedora->id, 'telefono' => '+573001112233', 'cita_agente_id' => 42,
            'dia' => 'Jueves 9 de octubre', 'hora' => '3:00 pm', 'estado' => 'confirmada',
        ]);

        $this->avisar([
            'tipo' => 'cita', 'resumen' => 'CITA CANCELADA por el cliente', 'idempotencia' => 'c-1',
            'datos_cita' => ['cancelada' => true, 'cita_id' => 41, 'dia' => 'Jueves 9 de octubre', 'hora' => '10:00 am'],
        ])->assertStatus(201);

        $this->assertSame('cancelada', $suya->fresh()->estado);
        $this->assertSame('confirmada', $otra->fresh()->estado);
    }

    public function test_limpiar_terminadas_las_archiva_sin_borrarlas(): void
    {
        $supervisor = Usuario::forceCreate(['nombre' => 'Laura', 'rol' => 'supervisor', 'activo' => true]);
        $terminada = ConversacionWa::create(['tipo' => 'asesor', 'telefono' => '+573001112233', 'resumen' => 'x', 'estado' => 'terminada']);
        $pendiente = ConversacionWa::create(['tipo' => 'asesor', 'telefono' => '+573004445566', 'resumen' => 'y', 'estado' => 'pendiente']);

        $this->actingAs($supervisor)->deleteJson('/api/redes/conversaciones/terminadas')
            ->assertOk()->assertJson(['eliminadas' => 1, 'archivadas' => 1]);

        // Sigue en la base (el agente y las métricas la usan)…
        $this->assertNotNull($terminada->fresh()?->archivada_at);
        $this->assertNull($pendiente->fresh()->archivada_at);
        // …pero ya no sale en la bandeja.
        $ids = collect($this->actingAs($supervisor)->getJson('/api/redes/conversaciones')->assertOk()->json())->pluck('id');
        $this->assertEquals([$pendiente->id], $ids->all());
    }
}
