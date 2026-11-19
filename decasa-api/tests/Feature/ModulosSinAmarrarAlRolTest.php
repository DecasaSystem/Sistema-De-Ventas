<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 5 de docs/metodologia-de-trabajo-por-rol.md: Facturación, Redes y
 * Telas ya no dependen del rol. El caso del usuario: "un contador que puede
 * ser vendedor o solo puede ser contador, que a veces solo maneja eso".
 */
class ModulosSinAmarrarAlRolTest extends TestCase
{
    private Rol $rolSupervisor;
    private Rol $rolContador;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo');
            $t->boolean('activo')->default(true); $t->integer('orden')->default(0); $t->timestamps();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('ciudad')->nullable(); $t->boolean('activa')->default(true);
            $t->boolean('es_independientes')->default(false); $t->timestamps();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('cedula')->nullable();
            $t->string('rol')->default('vendedor'); $t->unsignedBigInteger('rol_id')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            foreach (['facturacion', 'independiente', 'acceso_redes', 'recarga_telas', 'acceso_telas'] as $c) {
                $t->boolean($c)->default(false);
            }
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('nombre')->nullable(); $t->decimal('metros_disponibles', 10, 2)->default(0);
            $t->boolean('activo')->default(true); $t->timestamps();
        });

        $this->rolSupervisor = Rol::create(['clave' => 'supervisor', 'nombre' => 'Supervisor', 'arquetipo' => 'supervisor']);
        // El contador de otra empresa: un rol de taller/fábrica que no vende.
        $this->rolContador = Rol::create(['clave' => 'contador', 'nombre' => 'Contador', 'arquetipo' => 'taller']);
    }

    private function persona(string $nombre, Rol $rol, array $mas = []): Usuario
    {
        return Usuario::create(['nombre' => $nombre, 'email' => strtolower($nombre) . '@x.co', 'rol_id' => $rol->id] + $mas);
    }

    public function test_un_contador_que_no_vende_puede_tener_facturacion_y_redes(): void
    {
        $jefe     = $this->persona('Jefe', $this->rolSupervisor);
        $contador = $this->persona('Contador', $this->rolContador);

        $this->actingAs($jefe)->putJson("/api/usuarios/{$contador->id}", [
            'facturacion' => true, 'acceso_redes' => true, 'recarga_telas' => true,
        ])->assertOk();

        $contador = $contador->fresh();
        $this->assertTrue((bool) $contador->facturacion);
        $this->assertTrue((bool) $contador->acceso_redes);
        $this->assertTrue((bool) $contador->recarga_telas);
    }

    public function test_los_avisos_de_facturar_le_llegan_al_contador(): void
    {
        \Illuminate\Support\Facades\DB::table('tiendas')->insert([['nombre' => 'Centro'], ['nombre' => 'Norte']]);
        $contador   = $this->persona('Contador', $this->rolContador, ['facturacion' => true]);
        $deCentro   = Usuario::create(['nombre' => 'Vende Centro', 'rol' => 'vendedor', 'facturacion' => true, 'tienda_default_id' => 1]);
        $deNorte    = Usuario::create(['nombre' => 'Vende Norte', 'rol' => 'vendedor', 'facturacion' => true, 'tienda_default_id' => 2]);
        $sinFactura = Usuario::create(['nombre' => 'Otro', 'rol' => 'vendedor', 'tienda_default_id' => 1]);
        $inactivo   = $this->persona('Viejo', $this->rolContador, ['facturacion' => true, 'activo' => false]);

        $ids = Usuario::facturadoresDe(1)->pluck('id')->all();

        // El vendedor que factura en esa tienda (como antes) y el contador (nuevo).
        $this->assertEqualsCanonicalizing([$contador->id, $deCentro->id], $ids);
        $this->assertNotContains($deNorte->id, $ids);
        $this->assertNotContains($sinFactura->id, $ids);
        $this->assertNotContains($inactivo->id, $ids);
    }

    public function test_recargar_y_descontar_telas_van_por_permiso_no_por_oficio(): void
    {
        $tela = \Illuminate\Support\Facades\DB::table('catalogo_telas')->insertGetId(['nombre' => 'Lino', 'metros_disponibles' => 10]);
        $recarga  = $this->persona('Recarga', $this->rolContador, ['recarga_telas' => true]);
        $descuenta = $this->persona('Descuenta', $this->rolContador, ['acceso_telas' => true]);
        $nada     = $this->persona('Nada', $this->rolContador);

        // Antes: recargar solo si además era vendedor o supervisor.
        $this->assertNotSame(403, $this->actingAs($recarga)->postJson('/api/inventario-telas/recargar', ['id' => $tela, 'metros' => 1])->status());
        $this->actingAs($nada)->postJson('/api/inventario-telas/recargar', ['id' => $tela, 'metros' => 1])->assertForbidden();

        // Antes: descontar solo si el rol se llamaba Costurero.
        $this->assertNotSame(403, $this->actingAs($descuenta)->postJson('/api/inventario-telas/descontar', [])->status());
        $this->actingAs($nada)->postJson('/api/inventario-telas/descontar', [])->assertForbidden();
    }
}
