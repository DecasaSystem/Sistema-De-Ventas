<?php

namespace Tests\Feature;

use App\Models\NominaBonificacion;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El bono se guarda como el cuadro de Excel de la fábrica, de una vez:
 * de 900.000 a 950.000 paga 50.000, de 950.001 a 1.000.000 paga 75.000...
 * hasta 1.450.000, que paga 300.000. El que no llega a 900.000 no cobra.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class BonoConCuadroTest extends TestCase
{
    private Usuario $jefe;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_nomina')->default(false);
            $t->unsignedBigInteger('nomina_bonificacion_id')->nullable(); $t->timestamps();
        });
        Schema::create('nomina_bonificaciones', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('periodo')->default('ciclo');
            $t->decimal('tope', 12, 2)->default(0); $t->boolean('tope_activo')->default(true);
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_bonificacion_metas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('nomina_bonificacion_id');
            $t->decimal('desde', 12, 2); $t->decimal('hasta', 12, 2)->nullable();
            $t->decimal('monto', 12, 2); $t->boolean('activo')->default(true); $t->timestamps();
        });

        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Jefe', 'rol' => 'supervisor', 'acceso_nomina' => true, 'created_at' => now()]);
        $this->jefe = Usuario::find(1);
    }

    /** El cuadro de la foto: 11 escalones de 50.000 que suben de a 25.000. */
    private function cuadroDelExcel(bool $ultimoAbierto = false): array
    {
        $metas = [];
        for ($i = 0; $i < 11; $i++) {
            $metas[] = [
                'desde' => $i === 0 ? 900000 : 900000 + $i * 50000 + 1,
                'hasta' => 900000 + ($i + 1) * 50000,
                'monto' => 50000 + $i * 25000,
            ];
        }
        if ($ultimoAbierto) $metas[10]['hasta'] = null;

        return ['nombre' => 'Bono taller', 'periodo' => 'mensual', 'metas' => $metas];
    }

    public function test_se_crea_el_bono_con_todo_su_cuadro_de_una_vez(): void
    {
        $r = $this->actingAs($this->jefe)->postJson('/api/nomina/bonificaciones/cuadro', $this->cuadroDelExcel());

        $r->assertCreated();
        $this->assertCount(11, $r->json('metas'));
        $this->assertEquals(900000, $r->json('tope'), 'el tope es donde empieza el primer escalón');

        $bono = NominaBonificacion::with('metas')->first();
        $this->assertSame(0.0, $bono->evaluar(899999)['monto'], 'no llega: no cobra');
        $this->assertSame(50000.0, $bono->evaluar(947000)['monto']);
        $this->assertSame(75000.0, $bono->evaluar(950001)['monto']);
        $this->assertSame(300000.0, $bono->evaluar(1450000)['monto']);
    }

    public function test_por_encima_del_ultimo_escalon_sigue_pagando_si_se_deja_abierto(): void
    {
        $this->actingAs($this->jefe)->postJson('/api/nomina/bonificaciones/cuadro', $this->cuadroDelExcel(true))->assertCreated();

        $bono = NominaBonificacion::with('metas')->first();
        $this->assertSame(300000.0, $bono->evaluar(2000000)['monto']);
    }

    public function test_editar_reemplaza_el_cuadro_entero(): void
    {
        $id = $this->actingAs($this->jefe)->postJson('/api/nomina/bonificaciones/cuadro', $this->cuadroDelExcel())->json('id');

        $this->actingAs($this->jefe)->putJson("/api/nomina/bonificaciones/{$id}/cuadro", [
            'nombre' => 'Bono taller', 'periodo' => '20_dias',
            'metas'  => [['desde' => 800000, 'hasta' => null, 'monto' => 40000]],
        ])->assertOk();

        $bono = NominaBonificacion::with('metas')->first();
        $this->assertCount(1, $bono->metas);
        $this->assertSame('20_dias', $bono->periodo);
        $this->assertEquals(800000, $bono->tope);
    }

    public function test_no_deja_guardar_escalones_que_se_pisan(): void
    {
        $cuadro = $this->cuadroDelExcel();
        $cuadro['metas'][1]['desde'] = 940000;   // se mete en el primero

        $this->actingAs($this->jefe)->postJson('/api/nomina/bonificaciones/cuadro', $cuadro)
            ->assertStatus(422)->assertJsonValidationErrors('metas');
        $this->assertSame(0, NominaBonificacion::count());
    }

    public function test_solo_el_ultimo_puede_quedar_sin_hasta(): void
    {
        $cuadro = $this->cuadroDelExcel();
        $cuadro['metas'][3]['hasta'] = null;

        $this->actingAs($this->jefe)->postJson('/api/nomina/bonificaciones/cuadro', $cuadro)
            ->assertStatus(422)->assertJsonValidationErrors('metas');
    }

    public function test_sin_permiso_de_nomina_no_entra(): void
    {
        DB::table('usuarios')->insert(['id' => 2, 'nombre' => 'Otro', 'rol' => 'vendedor', 'acceso_nomina' => false, 'created_at' => now()]);

        $this->actingAs(Usuario::find(2))->postJson('/api/nomina/bonificaciones/cuadro', $this->cuadroDelExcel())
            ->assertStatus(403);
    }
}
