<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Support\RangoDeUsuarios;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Super admin, rangos y módulos por rol (docs/metodologia-de-trabajo-por-rol.md,
 * fase 3). Decisiones del usuario del 2026-11-19:
 *  - el super admin le cambia todo a todos; es una marca encima de su rol;
 *  - un supervisor le pone y quita módulos a los que no son supervisores, y
 *    los crea, pero no toca a otro supervisor ni le da a nadie rol de supervisor;
 *  - nadie se cambia sus propios permisos (salvo el único super admin);
 *  - lo que trae el rol se le puede quitar o agregar a cada persona, y se
 *    guarda solo la diferencia: lo demás sigue al rol si el rol cambia.
 */
class SuperAdminYRangosTest extends TestCase
{
    private Rol $rolVendedor;
    private Rol $rolSupervisor;

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
            foreach (['facturacion', 'independiente', 'acceso_costos', 'acceso_nomina', 'acceso_finanzas', 'acceso_compras'] as $c) {
                $t->boolean($c)->default(false);
            }
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje')->nullable(); $t->json('datos')->nullable(); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->timestamps();
        });

        (require database_path('migrations/2026_11_20_000002_modulos_de_venta_en_usuarios.php'))->up();
        (require database_path('migrations/2026_11_20_000003_super_admin_y_modulos_por_rol.php'))->up();

        $this->rolVendedor   = Rol::create(['clave' => 'vendedor', 'nombre' => 'Vendedor', 'arquetipo' => 'vendedor']);
        $this->rolSupervisor = Rol::create(['clave' => 'supervisor', 'nombre' => 'Supervisor', 'arquetipo' => 'supervisor']);
    }

    private function persona(string $nombre, Rol $rol, array $mas = []): Usuario
    {
        $u = Usuario::create(['nombre' => $nombre, 'email' => strtolower($nombre) . '@x.co', 'rol_id' => $rol->id] + $mas);
        \App\Support\ModulosDeVenta::aplicar($u);
        RangoDeUsuarios::olvidar();

        return $u->fresh();
    }

    private function superAdmin(string $nombre = 'Jefe'): Usuario
    {
        return $this->persona($nombre, $this->rolSupervisor, ['es_super_admin' => true]);
    }

    public function test_sin_super_admin_todo_sigue_como_antes(): void
    {
        $ana  = $this->persona('Ana', $this->rolSupervisor);
        $luis = $this->persona('Luis', $this->rolSupervisor);

        $this->actingAs($ana)->putJson("/api/usuarios/{$luis->id}", ['acceso_costos' => true])->assertOk();
        $this->assertTrue((bool) $luis->fresh()->acceso_costos);
    }

    public function test_un_supervisor_no_le_cambia_permisos_a_otro_supervisor(): void
    {
        $this->superAdmin();
        $ana  = $this->persona('Ana', $this->rolSupervisor);
        $luis = $this->persona('Luis', $this->rolSupervisor);

        $this->actingAs($ana)->putJson("/api/usuarios/{$luis->id}", ['acceso_costos' => true])
            ->assertForbidden()->assertJsonPath('message', 'A un supervisor solo lo cambia el super admin.');
        $this->actingAs($ana)->patchJson("/api/usuarios/{$luis->id}/toggle-activo")->assertForbidden();
        $this->actingAs($ana)->postJson("/api/usuarios/{$luis->id}/reset-password", ['password' => '12345678'])->assertForbidden();

        $this->assertFalse((bool) $luis->fresh()->acceso_costos);
        $this->assertTrue((bool) $luis->fresh()->activo);
    }

    public function test_un_supervisor_si_le_cambia_modulos_a_un_vendedor(): void
    {
        $this->superAdmin();
        $ana   = $this->persona('Ana', $this->rolSupervisor);
        $pedro = $this->persona('Pedro', $this->rolVendedor);

        // Le da Costos (cualquier módulo, aunque ella no lo tenga) y le quita Cotizaciones.
        $this->actingAs($ana)->putJson("/api/usuarios/{$pedro->id}", [
            'acceso_costos' => true, 'acceso_cotizaciones' => false,
        ])->assertOk();

        $pedro = $pedro->fresh();
        $this->assertTrue((bool) $pedro->acceso_costos);
        $this->assertFalse((bool) $pedro->acceso_cotizaciones);
        $this->assertSame(['acceso_cotizaciones' => false], $pedro->modulos_ajustados);
    }

    public function test_nadie_se_cambia_sus_permisos_salvo_el_unico_super_admin(): void
    {
        $jefe = $this->superAdmin();
        $ana  = $this->persona('Ana', $this->rolSupervisor);

        $this->actingAs($ana)->putJson("/api/usuarios/{$ana->id}", ['acceso_finanzas' => true])
            ->assertForbidden();
        // Cambiarse el nombre sí.
        $this->actingAs($ana)->putJson("/api/usuarios/{$ana->id}", ['nombre' => 'Ana María'])->assertOk();

        // El único super admin sí se ajusta lo suyo.
        $this->actingAs($jefe)->putJson("/api/usuarios/{$jefe->id}", ['acceso_finanzas' => true])->assertOk();

        // Con dos, ya no: se lo cambia el otro.
        $otro = $this->superAdmin('Otro');
        $this->actingAs($jefe->fresh())->putJson("/api/usuarios/{$jefe->id}", ['acceso_nomina' => true])->assertForbidden();
        $this->actingAs($otro)->putJson("/api/usuarios/{$jefe->id}", ['acceso_nomina' => true])->assertOk();
    }

    public function test_un_supervisor_no_da_rol_de_supervisor(): void
    {
        $this->superAdmin();
        $ana   = $this->persona('Ana', $this->rolSupervisor);
        $pedro = $this->persona('Pedro', $this->rolVendedor);

        $this->actingAs($ana)->putJson("/api/usuarios/{$pedro->id}", ['rol_id' => $this->rolSupervisor->id])
            ->assertForbidden();
        $this->assertSame('vendedor', $pedro->fresh()->rol);
    }

    public function test_la_marca_la_pone_solo_un_super_admin_y_nunca_queda_sin_ninguno(): void
    {
        $jefe = $this->superAdmin();
        $ana  = $this->persona('Ana', $this->rolSupervisor);
        $pedro = $this->persona('Pedro', $this->rolVendedor);

        $this->actingAs($ana)->putJson("/api/usuarios/{$pedro->id}", ['es_super_admin' => true])->assertForbidden();
        $this->actingAs($jefe)->putJson("/api/usuarios/{$jefe->id}", ['es_super_admin' => false])->assertStatus(422);

        $this->actingAs($jefe)->putJson("/api/usuarios/{$ana->id}", ['es_super_admin' => true])->assertOk();
        $this->assertTrue((bool) $ana->fresh()->es_super_admin);
    }

    public function test_cambiar_los_modulos_del_rol_le_cambia_a_todos_menos_lo_ajustado(): void
    {
        $jefe  = $this->superAdmin();
        $pedro = $this->persona('Pedro', $this->rolVendedor);
        $maria = $this->persona('Maria', $this->rolVendedor);
        // A María se le agregó Caja a mano.
        $this->actingAs($jefe)->putJson("/api/usuarios/{$maria->id}", ['acceso_caja' => true])->assertOk();

        // El rol Vendedor pasa a traer solo Nueva orden y Órdenes.
        $this->actingAs($jefe)->patchJson("/api/roles/{$this->rolVendedor->id}", [
            'modulos_venta' => ['acceso_nueva_orden', 'acceso_ordenes'],
        ])->assertOk();

        $pedro = $pedro->fresh(); $maria = $maria->fresh();
        $this->assertTrue((bool) $pedro->acceso_nueva_orden);
        $this->assertFalse((bool) $pedro->acceso_cotizaciones);
        $this->assertFalse((bool) $pedro->acceso_caja);
        $this->assertTrue((bool) $maria->acceso_caja, 'lo que se le agregó a mano se queda');
        $this->assertFalse((bool) $maria->acceso_clientes);
    }

    public function test_los_roles_los_cambia_solo_el_super_admin(): void
    {
        $this->superAdmin();
        $ana = $this->persona('Ana', $this->rolSupervisor);

        $this->actingAs($ana)->patchJson("/api/roles/{$this->rolVendedor->id}", ['nombre' => 'Asesor'])->assertForbidden();
        $this->actingAs($ana)->postJson('/api/roles', ['nombre' => 'Gerente', 'arquetipo' => 'supervisor'])->assertForbidden();
    }

    public function test_queda_el_rastro_y_se_avisa_al_super_admin_de_lo_delicado(): void
    {
        $jefe  = $this->superAdmin();
        $ana   = $this->persona('Ana', $this->rolSupervisor);
        $pedro = $this->persona('Pedro', $this->rolVendedor);

        $this->actingAs($ana)->putJson("/api/usuarios/{$pedro->id}", ['acceso_costos' => true])->assertOk();

        $fila = DB::table('permisos_bitacora')->where('usuario_id', $pedro->id)->latest('id')->first();
        $this->assertSame($ana->id, (int) $fila->actor_id);
        $this->assertSame([false, true], json_decode($fila->cambios, true)['acceso_costos']);

        $aviso = DB::table('notificaciones')->where('usuario_id', $jefe->id)->where('tipo', 'permiso_delicado')->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('Costos', $aviso->mensaje);
    }

    public function test_la_migracion_deja_a_cada_uno_con_lo_que_tenia(): void
    {
        // Un vendedor con Caja (el rol Vendedor ya no la trae) y uno sin Cotizaciones.
        DB::table('usuarios')->insert([
            ['nombre' => 'ConCaja', 'rol' => 'vendedor', 'rol_id' => $this->rolVendedor->id,
             'acceso_nueva_orden' => 1, 'acceso_ordenes' => 1, 'acceso_clientes' => 1, 'acceso_cotizaciones' => 1,
             'acceso_caja' => 1, 'acceso_consultas_costo' => 1, 'acceso_estadisticas' => 1],
        ]);
        (require database_path('migrations/2026_11_20_000003_super_admin_y_modulos_por_rol.php'))->up();

        $u = Usuario::where('nombre', 'ConCaja')->first();
        $this->assertSame(['acceso_caja' => true], $u->modulos_ajustados);
        \App\Support\ModulosDeVenta::aplicar($u);
        $this->assertTrue((bool) $u->fresh()->acceso_caja);
    }

    public function test_el_comando_marca_al_primero(): void
    {
        $yo = $this->persona('Yo', $this->rolSupervisor);

        $this->artisan('usuarios:super-admin', ['correo' => 'yo@x.co'])->assertSuccessful();
        $this->assertTrue((bool) $yo->fresh()->es_super_admin);
        $this->assertSame('supervisor', $yo->fresh()->rol, 'sigue siendo supervisor y vendiendo');
    }
}
