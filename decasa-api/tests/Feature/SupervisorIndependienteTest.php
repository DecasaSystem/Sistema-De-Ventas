<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un supervisor puede seguir vendiendo por su cuenta.
 *
 * Henry lleva el taller —y para eso necesita el rol de supervisor— pero vende
 * como independiente: su propia caja, su 5%, la mitad que le abona a un
 * almacén. Pasarlo a supervisor le quitaba la marca de independiente (solo
 * existía para vendedores), lo obligaba a tener una tienda y lo sacaba de
 * todo eso.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class SupervisorIndependienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo'); $t->boolean('activo')->default(true);
            $t->unsignedInteger('orden')->default(0);
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable(); $t->boolean('es_independientes')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('apto_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        // El equipo de comisiones se mira al cambiar de sede.
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('mes', 7); $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });

        DB::table('roles')->insert([
            ['id' => 1, 'clave' => 'vendedor',   'nombre' => 'Vendedor',   'arquetipo' => 'vendedor'],
            ['id' => 2, 'clave' => 'supervisor', 'nombre' => 'Supervisor', 'arquetipo' => 'supervisor'],
            ['id' => 3, 'clave' => 'conductor',  'nombre' => 'Conductor',  'arquetipo' => 'conductor'],
        ]);
        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Norte'],
            ['id' => 8, 'nombre' => 'Independientes', 'es_independientes' => true],
        ]);
    }

    private function jefa(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
                                'rol_id' => 2, 'created_at' => now()]);
    }

    private function henry(): Usuario
    {
        return Usuario::create(['nombre' => 'Henry', 'email' => 'h@d.com', 'password' => 'x', 'rol' => 'vendedor',
                                'rol_id' => 1, 'independiente' => true, 'tienda_default_id' => 8,
                                'apto_produccion' => true, 'acceso_produccion' => true, 'created_at' => now()]);
    }

    public function test_pasar_a_supervisor_no_le_quita_lo_de_independiente(): void
    {
        $henry = $this->henry();

        $this->actingAs($this->jefa())
            ->putJson("/api/usuarios/{$henry->id}", ['rol_id' => 2, 'independiente' => true])
            ->assertOk()
            ->assertJsonPath('arquetipo', 'supervisor')
            ->assertJsonPath('independiente', true)
            ->assertJsonPath('tienda_default_id', 8);

        $henry->refresh();
        $this->assertSame('supervisor', $henry->rol);
        $this->assertTrue($henry->independiente);
        // Lo del taller sigue igual.
        $this->assertTrue($henry->apto_produccion);
    }

    public function test_un_conductor_independiente_sigue_sin_significar_nada(): void
    {
        $henry = $this->henry();

        $this->actingAs($this->jefa())
            ->putJson("/api/usuarios/{$henry->id}", ['rol_id' => 3, 'independiente' => true])
            ->assertOk()
            ->assertJsonPath('independiente', false)
            // Y no se le exige tienda: un conductor no tiene.
            ->assertJsonPath('tienda_default_id', null);
    }
}
