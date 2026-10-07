<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El número de consultas de costo pendientes del menú.
 *
 * Antes salía de descargar TODAS las consultas —con ítems y desgloses— en
 * cada apertura de la app. Ahora hay un conteo aparte, y tiene que contar
 * exactamente las que esa persona vería en su lista: si no, el número del
 * menú y la pantalla dirían cosas distintas.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ConteoConsultasCostoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_costos')->default(false); $t->timestamp('created_at')->nullable();
        });
        Schema::create('consultas_costo', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable();
            $t->unsignedBigInteger('asignado_a_id')->nullable(); $t->unsignedBigInteger('solicitado_por_id')->nullable();
            $t->string('estado')->default('pendiente'); $t->timestamps();
        });
    }

    private function persona(string $rol, bool $costos = false): Usuario
    {
        return Usuario::create(['nombre' => $rol, 'email' => $rol . rand() . '@d.com', 'password' => 'x',
                                'rol' => $rol, 'acceso_costos' => $costos, 'created_at' => now()]);
    }

    private function consulta(Usuario $pide, Usuario $atiende, string $estado = 'pendiente'): void
    {
        DB::table('consultas_costo')->insert([
            'solicitado_por_id' => $pide->id, 'asignado_a_id' => $atiende->id,
            'estado' => $estado, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function conteo(Usuario $u): int
    {
        return $this->actingAs($u)->getJson('/api/consultas-costo/conteo')->assertOk()->json('pendientes');
    }

    public function test_cada_quien_cuenta_solo_las_pendientes_que_le_tocan(): void
    {
        $marta   = $this->persona('vendedor');
        $pedro   = $this->persona('vendedor');
        $jefa    = $this->persona('supervisor');
        $otraJefa = $this->persona('supervisor');
        $ebanista = $this->persona('ebanista', costos: true);

        $this->consulta($marta, $ebanista);                 // pendiente, al ebanista
        $this->consulta($marta, $jefa);                     // pendiente, a la jefa
        $this->consulta($marta, $jefa, 'respondida');       // ya respondida: no cuenta
        $this->consulta($pedro, $ebanista);                 // de otro vendedor

        $this->assertSame(2, $this->conteo($marta), 'las suyas pendientes');
        $this->assertSame(1, $this->conteo($pedro));
        $this->assertSame(1, $this->conteo($jefa), 'las asignadas a ella o pedidas por ella');
        $this->assertSame(0, $this->conteo($otraJefa), 'sin monitoreo: no ve las de los demás');
        $this->assertSame(2, $this->conteo($ebanista), 'las asignadas a él');
    }

    public function test_quien_no_atiende_consultas_ve_cero(): void
    {
        $this->consulta($this->persona('vendedor'), $this->persona('ebanista', costos: true));

        $this->assertSame(0, $this->conteo($this->persona('conductor')));
    }
}
