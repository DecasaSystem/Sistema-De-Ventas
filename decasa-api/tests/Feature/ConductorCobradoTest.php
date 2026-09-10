<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El "cobrado" de un conductor (GET /api/stats/conductores) no se puede
 * inflar por órdenes con varios renglones de despacho.
 *
 * Antes la consulta unía pagos con despacho_items, así que una orden partida
 * en dos entregas sumaba su abono dos veces.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ConductorCobradoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('despachos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('conductor_id')->nullable(); $t->string('estado')->default('asignado');
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id');
            $t->string('estado')->default('pendiente'); $t->timestamp('entregado_at')->nullable();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 15, 2)->default(0);
            $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Conductor', 'rol' => 'conductor', 'created_at' => now()]);
        DB::table('usuarios')->insert(['id' => 2, 'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
    }

    public function test_una_orden_con_dos_renglones_de_despacho_no_cuenta_el_abono_dos_veces(): void
    {
        $hoy = Carbon::now('America/Bogota')->startOfDay()->addHours(12)->setTimezone('UTC');

        $orden = DB::table('ordenes')->insertGetId(['valor_total' => 5_000_000, 'created_at' => $hoy, 'updated_at' => $hoy]);
        DB::table('pagos')->insert(['orden_id' => $orden, 'monto' => 1_000_000, 'created_at' => $hoy]);

        $desp = DB::table('despachos')->insertGetId(['conductor_id' => 1, 'estado' => 'entregado']);
        // La misma orden, dos renglones (dos muebles) entregados por el conductor.
        DB::table('despacho_items')->insert([
            ['despacho_id' => $desp, 'orden_id' => $orden, 'estado' => 'entregado', 'entregado_at' => $hoy],
            ['despacho_id' => $desp, 'orden_id' => $orden, 'estado' => 'entregado', 'entregado_at' => $hoy],
        ]);

        $jefe = Usuario::find(2);
        $filas = $this->actingAs($jefe)->getJson('/api/stats/conductores?periodo=mes')->assertOk()->json();
        $c = collect($filas)->firstWhere('id', 1);

        $this->assertEquals(1_000_000, $c['cobrado'], 'el abono cuenta una sola vez');
        $this->assertEquals(2, $c['entregas']);
    }
}
