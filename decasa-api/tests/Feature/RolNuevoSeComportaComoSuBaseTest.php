<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un rol que crea la empresa ("Gerente", "Asesor", "Soldador") se comporta
 * como su arquetipo. Antes quedaba `usuarios.rol = 'gerente'` y el programa,
 * que pregunta por `supervisor`, no lo dejaba aprobar nada ni entrar a Gestión.
 */
class RolNuevoSeComportaComoSuBaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo');
            $t->boolean('activo')->default(true); $t->integer('orden')->default(0); $t->timestamps();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->default('vendedor'); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
    }

    private function rol(string $clave, string $arquetipo): Rol
    {
        return Rol::create(['clave' => $clave, 'nombre' => ucfirst($clave), 'arquetipo' => $arquetipo]);
    }

    private function persona(Rol $rol): Usuario
    {
        return Usuario::create(['nombre' => 'Ana', 'email' => uniqid() . '@x.co', 'rol_id' => $rol->id]);
    }

    public function test_un_rol_nuevo_de_tipo_supervisor_es_supervisor(): void
    {
        $gerente = $this->persona($this->rol('gerente', 'supervisor'));

        $this->assertSame('supervisor', $gerente->rol);
        $this->assertSame($gerente->rol_id, Rol::where('clave', 'gerente')->value('id'));
    }

    public function test_cada_arquetipo_nuevo_toma_su_palabra(): void
    {
        $this->assertSame('vendedor', $this->persona($this->rol('asesor', 'vendedor'))->rol);
        $this->assertSame('conductor', $this->persona($this->rol('mensajero', 'conductor'))->rol);
        // Los de taller se quedan con su clave: así funcionan los ~22 de
        // fábrica de producción (Tapicero, Lijador…), y `ebanista` abre más cosas.
        $this->assertSame('lijador', $this->persona($this->rol('lijador', 'taller'))->rol);
    }

    public function test_los_roles_de_fabrica_siguen_con_su_clave(): void
    {
        // Costurero es de tipo taller, pero el código lo busca por su nombre
        // (la ruta de descontar telas es `role:costurero,supervisor`).
        $this->assertSame('costurero', $this->persona($this->rol('costurero', 'taller'))->rol);
        $this->assertSame('despachador', $this->persona($this->rol('despachador', 'despachador'))->rol);
        $this->assertSame('supervisor', $this->persona($this->rol('supervisor', 'supervisor'))->rol);
    }

    public function test_cambiarle_el_rol_cambia_como_se_comporta(): void
    {
        $ana = $this->persona($this->rol('asesor', 'vendedor'));
        $ana->update(['rol_id' => $this->rol('gerente', 'supervisor')->id]);

        $this->assertSame('supervisor', $ana->fresh()->rol);
    }

    public function test_un_gerente_entra_donde_entra_un_supervisor(): void
    {
        Route::middleware(['auth:sanctum', 'role:supervisor'])->get('/api/prueba-solo-supervisor', fn () => 'ok');

        $gerente  = $this->persona($this->rol('gerente', 'supervisor'));
        $asesor   = $this->persona($this->rol('asesor', 'vendedor'));

        $this->actingAs($gerente)->getJson('/api/prueba-solo-supervisor')->assertOk();
        $this->actingAs($asesor)->getJson('/api/prueba-solo-supervisor')->assertForbidden();
    }

    public function test_la_migracion_pone_al_dia_a_los_que_ya_tenian_un_rol_nuevo(): void
    {
        $gerente   = $this->rol('gerente', 'supervisor');
        $costurero = $this->rol('costurero', 'taller');
        $lijador   = $this->rol('lijador', 'taller');
        // Como quedaban antes: con la clave del rol.
        DB::table('usuarios')->insert([
            ['nombre' => 'Luis', 'rol' => 'gerente',   'rol_id' => $gerente->id],
            ['nombre' => 'Rosa', 'rol' => 'costurero', 'rol_id' => $costurero->id],
            ['nombre' => 'Dani', 'rol' => 'lijador',   'rol_id' => $lijador->id],
        ]);

        $migracion = require database_path('migrations/2026_11_20_000001_roles_nuevos_se_comportan_como_su_base.php');
        $migracion->up();

        $this->assertSame('supervisor', DB::table('usuarios')->where('nombre', 'Luis')->value('rol'));
        $this->assertSame('costurero', DB::table('usuarios')->where('nombre', 'Rosa')->value('rol'));
        $this->assertSame('lijador', DB::table('usuarios')->where('nombre', 'Dani')->value('rol'));

        $migracion->down();
        $this->assertSame('gerente', DB::table('usuarios')->where('nombre', 'Luis')->value('rol'));
    }
}
