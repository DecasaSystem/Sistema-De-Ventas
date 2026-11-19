<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 7 de docs/metodologia-de-trabajo-por-rol.md: Surtir (de la fábrica a
 * las tiendas) y Traslado (de una tienda a otra) son permisos aparte. Antes
 * eran uno solo, y crear un traslado no pedía ningún permiso.
 */
class SurtirYTrasladoSeparadosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->default('vendedor'); $t->unsignedBigInteger('rol_id')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('acceso_surtir')->default(false);
            $t->timestamp('created_at')->nullable();
        });
    }

    private function conLaColumna(): void
    {
        (require database_path('migrations/2026_11_20_000005_surtir_y_traslado_separados.php'))->up();
    }

    public function test_la_migracion_deja_a_cada_uno_con_lo_que_usaba(): void
    {
        DB::table('usuarios')->insert([
            ['nombre' => 'Sup',     'rol' => 'supervisor', 'acceso_surtir' => true],
            ['nombre' => 'SupSin',  'rol' => 'supervisor', 'acceso_surtir' => false],
            ['nombre' => 'Vende',   'rol' => 'vendedor',   'acceso_surtir' => true],
            ['nombre' => 'Conduce', 'rol' => 'conductor',  'acceso_surtir' => false],
        ]);
        $this->conLaColumna();

        $fila = fn ($n) => DB::table('usuarios')->where('nombre', $n)->first();
        // Supervisor: los dos (como antes).
        $this->assertSame([1, 1], [(int) $fila('Sup')->acceso_surtir, (int) $fila('Sup')->acceso_traslado]);
        $this->assertSame(1, (int) $fila('SupSin')->acceso_traslado);
        // Vendedor con "Surtir": en pantalla solo hacía traslados.
        $this->assertSame([0, 1], [(int) $fila('Vende')->acceso_surtir, (int) $fila('Vende')->acceso_traslado]);
        $this->assertSame([0, 0], [(int) $fila('Conduce')->acceso_surtir, (int) $fila('Conduce')->acceso_traslado]);
    }

    public function test_crear_un_traslado_pide_el_permiso(): void
    {
        $this->conLaColumna();
        $sin = Usuario::create(['nombre' => 'Sin', 'email' => 's@x.co', 'rol' => 'conductor', 'acceso_traslado' => false]);
        $con = Usuario::create(['nombre' => 'Con', 'email' => 'c@x.co', 'rol' => 'vendedor', 'acceso_traslado' => true]);

        $this->actingAs($sin)->postJson('/api/inventario/traslados', [])->assertForbidden();
        $this->assertNotSame(403, $this->actingAs($con)->postJson('/api/inventario/traslados', [])->status());
    }

    public function test_surtir_desde_la_fabrica_sigue_con_su_permiso(): void
    {
        $this->conLaColumna();
        $soloTraslado = Usuario::create(['nombre' => 'T', 'email' => 't@x.co', 'rol' => 'vendedor', 'acceso_traslado' => true]);
        $fabrica      = Usuario::create(['nombre' => 'F', 'email' => 'f@x.co', 'rol' => 'ebanista', 'acceso_surtir' => true]);

        $this->actingAs($soloTraslado)->postJson('/api/inventario/surtir', [])->assertForbidden();
        // El de la fábrica, sin ser supervisor, surte si se le da.
        $this->assertNotSame(403, $this->actingAs($fabrica)->postJson('/api/inventario/surtir', [])->status());
    }

    public function test_sin_la_columna_vale_lo_de_antes(): void
    {
        $u = Usuario::create(['nombre' => 'X', 'email' => 'x@x.co', 'rol' => 'vendedor']);
        $this->assertTrue($u->tienePermiso('acceso_traslado'));
    }
}
