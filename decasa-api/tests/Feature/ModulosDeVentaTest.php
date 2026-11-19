<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\ModulosDeVenta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Vender como módulos (docs/metodologia-de-trabajo-por-rol.md, fase 2).
 *
 * Antes Nueva orden, Órdenes, Clientes, Cotizaciones, Caja, Consultar costo y
 * Estadísticas los tenía cualquiera que no fuera conductor o despachador, y el
 * servidor no los frenaba (un conductor listaba todas las órdenes por la API).
 * Ahora cada uno es un interruptor por persona, y el servidor lo revisa.
 *
 * Solo se mira el portero: 403 "No autorizado." o que lo deja pasar (lo que
 * haga el controlador después, con este esquema mínimo, no importa aquí).
 */
class ModulosDeVentaTest extends TestCase
{
    private const RUTAS = [
        'acceso_ordenes'         => ['GET', '/api/ordenes'],
        'acceso_nueva_orden'     => ['POST', '/api/ordenes'],
        'acceso_cotizaciones'    => ['GET', '/api/cotizaciones'],
        'acceso_caja'            => ['GET', '/api/caja/balance'],
        'acceso_consultas_costo' => ['POST', '/api/consultas-costo'],
        'acceso_estadisticas'    => ['GET', '/api/stats/vendedores/me'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->default('vendedor'); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->timestamp('created_at')->nullable();
        });
    }

    private function conLasColumnas(): void
    {
        $migracion = require database_path('migrations/2026_11_20_000002_modulos_de_venta_en_usuarios.php');
        $migracion->up();
    }

    private function persona(string $rol, array $modulos = []): Usuario
    {
        return Usuario::create(['nombre' => $rol, 'email' => uniqid() . '@x.co', 'rol' => $rol] + $modulos);
    }

    private function pide(Usuario $u, string $columna)
    {
        [$metodo, $url] = self::RUTAS[$columna];

        return $this->actingAs($u)->json($metodo, $url);
    }

    private function frenado($respuesta): bool
    {
        return $respuesta->status() === 403 && $respuesta->json('message') === 'No autorizado.';
    }

    public function test_la_migracion_se_los_deja_a_todos_los_que_los_tenian(): void
    {
        DB::table('usuarios')->insert([
            ['nombre' => 'Vendedora', 'rol' => 'vendedor'],
            ['nombre' => 'Super',     'rol' => 'supervisor'],
            ['nombre' => 'Ebanista',  'rol' => 'ebanista'],
            ['nombre' => 'Conductor', 'rol' => 'conductor'],
            ['nombre' => 'Desp',      'rol' => 'despachador'],
        ]);

        $this->conLasColumnas();

        foreach (array_keys(ModulosDeVenta::MODULOS) as $c) {
            $this->assertSame(1, (int) DB::table('usuarios')->where('nombre', 'Vendedora')->value($c), $c);
            $this->assertSame(1, (int) DB::table('usuarios')->where('nombre', 'Super')->value($c), $c);
            $this->assertSame(1, (int) DB::table('usuarios')->where('nombre', 'Ebanista')->value($c), $c);
            $this->assertSame(0, (int) DB::table('usuarios')->where('nombre', 'Conductor')->value($c), $c);
            $this->assertSame(0, (int) DB::table('usuarios')->where('nombre', 'Desp')->value($c), $c);
        }
    }

    public function test_sin_el_modulo_el_servidor_lo_frena(): void
    {
        $this->conLasColumnas();
        $vendedor = $this->persona('vendedor', array_fill_keys(array_keys(ModulosDeVenta::MODULOS), false));

        foreach (array_keys(self::RUTAS) as $columna) {
            $this->assertTrue($this->frenado($this->pide($vendedor, $columna)), "debía frenar $columna");
        }
    }

    public function test_con_el_modulo_lo_deja_pasar(): void
    {
        $this->conLasColumnas();
        $vendedor = $this->persona('vendedor', array_fill_keys(array_keys(ModulosDeVenta::MODULOS), true));

        foreach (array_keys(self::RUTAS) as $columna) {
            $this->assertFalse($this->frenado($this->pide($vendedor, $columna)), "no debía frenar $columna");
        }
    }

    public function test_un_conductor_que_vende_puede_vender(): void
    {
        $this->conLasColumnas();
        $sinVentas = $this->persona('conductor');
        $vende     = $this->persona('conductor', ['acceso_nueva_orden' => true, 'acceso_ordenes' => true]);

        // Antes un conductor listaba todas las órdenes por la API.
        $this->assertTrue($this->frenado($this->pide($sinVentas, 'acceso_ordenes')));
        $this->assertFalse($this->frenado($this->pide($vende, 'acceso_ordenes')));
        $this->assertFalse($this->frenado($this->pide($vende, 'acceso_nueva_orden')));
    }

    public function test_quien_vende_escoge_cliente_sin_el_modulo_clientes(): void
    {
        $this->conLasColumnas();
        $vende = $this->persona('vendedor', ['acceso_nueva_orden' => true, 'acceso_clientes' => false]);

        $this->assertFalse($this->frenado($this->actingAs($vende)->getJson('/api/clientes')));
        // Exportar y el historial del cliente sí son del módulo Clientes.
        $this->assertTrue($this->frenado($this->actingAs($vende)->getJson('/api/clientes/exportar')));
        $this->assertTrue($this->frenado($this->actingAs($vende)->getJson('/api/clientes/1/ordenes')));

        $nada = $this->persona('vendedor', ['acceso_nueva_orden' => false, 'acceso_cotizaciones' => false, 'acceso_clientes' => false]);
        $this->assertTrue($this->frenado($this->actingAs($nada)->getJson('/api/clientes')));
    }

    public function test_sin_las_columnas_vale_lo_de_antes(): void
    {
        // Esquema de pruebas montado a mano (o antes de migrar): nadie pierde la venta.
        $vendedor  = $this->persona('vendedor');
        $conductor = $this->persona('conductor');

        $this->assertTrue($vendedor->tienePermiso('acceso_ordenes'));
        $this->assertFalse($conductor->tienePermiso('acceso_ordenes'));
        $this->assertFalse($this->frenado($this->pide($vendedor, 'acceso_ordenes')));
    }

    public function test_crear_un_trabajador_arranca_con_los_de_su_rol(): void
    {
        $this->conLasColumnas();

        $ctrl = new \App\Http\Controllers\UsuarioController();
        $m = new \ReflectionMethod($ctrl, 'modulosDeVentaAlCrear');

        $rol = fn (string $a) => new \App\Models\Rol(['clave' => $a, 'nombre' => $a, 'arquetipo' => $a]);
        $vendedor  = $m->invoke($ctrl, request(), $rol('vendedor'), false);
        $conductor = $m->invoke($ctrl, request(), $rol('conductor'), false);
        $fabrica   = $m->invoke($ctrl, request()->merge(['acceso_caja' => true]), $rol('vendedor'), true);

        $this->assertTrue($vendedor['acceso_nueva_orden']);
        // El rol Vendedor no trae Caja (va aparte).
        $this->assertFalse($vendedor['acceso_caja']);
        $this->assertFalse($conductor['acceso_nueva_orden']);
        // Quien no usa el programa no vende, aunque se lo manden.
        $this->assertFalse($fabrica['acceso_caja']);
    }
}
