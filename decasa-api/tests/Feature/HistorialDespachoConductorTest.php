<?php

namespace Tests\Feature;

use App\Models\Despacho;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Buscar en el historial de despacho por nombre de conductor — la forma de
 * acotar "¿cuántas entregas más hizo esta cuenta?" (por ejemplo una cuenta de
 * prueba que marcó entregas que no ocurrieron) sin tener que revisar orden
 * por orden desde inventario.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class HistorialDespachoConductorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->default('supervisor');
            $t->boolean('acceso_despacho')->default(false);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('despachos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('camion_id')->nullable(); $t->unsignedBigInteger('conductor_id')->nullable();
            $t->unsignedBigInteger('entregado_por_id')->nullable(); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->date('fecha_despacho')->nullable(); $t->string('estado')->default('completado');
            $t->string('tipo')->default('ruta'); $t->text('notas')->nullable();
            $t->string('nombre_ruta')->nullable(); $t->text('instrucciones')->nullable(); $t->timestamps();
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id');
            $t->unsignedInteger('posicion')->default(1); $t->string('estado')->default('pendiente');
            $t->timestamp('entregado_at')->nullable();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable();
        });
        Schema::create('camiones', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('placa')->nullable(); });
    }

    private function supervisor(): Usuario
    {
        return Usuario::create(['nombre' => 'Sup', 'rol' => 'supervisor', 'acceso_despacho' => true, 'created_at' => now()]);
    }

    public function test_filtra_el_historial_por_nombre_de_conductor(): void
    {
        $prueba = Usuario::create(['nombre' => 'Conductor Prueba', 'rol' => 'conductor', 'created_at' => now()]);
        $real   = Usuario::create(['nombre' => 'Carlos Pérez', 'rol' => 'conductor', 'created_at' => now()]);

        Despacho::create(['conductor_id' => $prueba->id, 'estado' => 'completado', 'tipo' => 'ruta', 'fecha_despacho' => now()]);
        Despacho::create(['conductor_id' => $prueba->id, 'estado' => 'completado', 'tipo' => 'ruta', 'fecha_despacho' => now()]);
        Despacho::create(['conductor_id' => $real->id,   'estado' => 'completado', 'tipo' => 'ruta', 'fecha_despacho' => now()]);

        $r = $this->actingAs($this->supervisor())
            ->getJson('/api/despacho/historial?conductor=Prueba')
            ->assertOk();

        $this->assertSame(2, $r->json('total'));
        foreach ($r->json('data') as $d) {
            $this->assertSame('Conductor Prueba', $d['conductor']['nombre']);
        }
    }

    public function test_sin_filtro_salen_todos(): void
    {
        $prueba = Usuario::create(['nombre' => 'Conductor Prueba', 'rol' => 'conductor', 'created_at' => now()]);
        Despacho::create(['conductor_id' => $prueba->id, 'estado' => 'completado', 'tipo' => 'ruta', 'fecha_despacho' => now()]);

        $r = $this->actingAs($this->supervisor())->getJson('/api/despacho/historial')->assertOk();

        $this->assertSame(1, $r->json('total'));
    }
}
