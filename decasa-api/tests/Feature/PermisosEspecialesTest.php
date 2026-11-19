<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 6 de docs/metodologia-de-trabajo-por-rol.md: asignar fechas de entrega
 * y editar el catálogo dejan de ser solo del supervisor; se dan por persona.
 * Aprobar cambios de plata sigue siendo solo de supervisores (decisión del
 * usuario, 2026-11-19): esta prueba no lo toca.
 *
 * Se mira el portero: 403 o que lo deja pasar.
 */
class PermisosEspecialesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->default('vendedor'); $t->unsignedBigInteger('rol_id')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('ve_todas_ordenes')->default(false);
            $t->timestamp('created_at')->nullable();
        });
    }

    private function conLasColumnas(): void
    {
        (require database_path('migrations/2026_11_20_000004_permisos_especiales_en_usuarios.php'))->up();
    }

    private function persona(string $rol, array $mas = []): Usuario
    {
        return Usuario::create(['nombre' => $rol, 'email' => uniqid() . '@x.co', 'rol' => $rol] + $mas);
    }

    public function test_la_migracion_se_los_deja_a_los_supervisores(): void
    {
        DB::table('usuarios')->insert([['nombre' => 'Sup', 'rol' => 'supervisor'], ['nombre' => 'Ven', 'rol' => 'vendedor']]);
        $this->conLasColumnas();

        $this->assertSame(1, (int) DB::table('usuarios')->where('nombre', 'Sup')->value('asigna_fechas'));
        $this->assertSame(1, (int) DB::table('usuarios')->where('nombre', 'Sup')->value('edita_catalogo'));
        $this->assertSame(0, (int) DB::table('usuarios')->where('nombre', 'Ven')->value('asigna_fechas'));
    }

    public function test_editar_el_catalogo_va_por_el_permiso(): void
    {
        $this->conLasColumnas();
        $vendedor = $this->persona('vendedor');
        $conPermiso = $this->persona('vendedor', ['edita_catalogo' => true]);
        $supervisorSin = $this->persona('supervisor', ['edita_catalogo' => false]);

        $this->assertSame(403, $this->actingAs($vendedor)->patchJson('/api/productos/1', ['precio' => 1])->status());
        $this->assertNotSame(403, $this->actingAs($conPermiso)->patchJson('/api/productos/1', ['precio' => 1])->status());
        // Al supervisor se le puede quitar.
        $this->assertSame(403, $this->actingAs($supervisorSin)->patchJson('/api/productos/1', ['precio' => 1])->status());
    }

    public function test_asignar_fechas_va_por_el_permiso(): void
    {
        $this->conLasColumnas();
        $vendedor = $this->persona('vendedor');

        $this->actingAs($vendedor)->patchJson('/api/ordenes/1/fechas-entrega', ['items' => [['id' => 1, 'fecha' => '2026-12-01']]])
            ->assertForbidden()->assertJsonPath('message', 'No tienes el permiso de asignar fechas de entrega.');
    }

    public function test_sin_las_columnas_vale_lo_de_antes(): void
    {
        $this->assertTrue($this->persona('supervisor')->tienePermiso('asigna_fechas'));
        $this->assertFalse($this->persona('vendedor')->tienePermiso('edita_catalogo'));
    }
}
