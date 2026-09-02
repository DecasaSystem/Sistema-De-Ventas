<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * En los reportes, de qué tipo de orden viene la plata.
 *
 * El total solo decía cuánto entró. Ahora dice además cuánto es de ventas,
 * cuánto de restauraciones y cuánto de la serie con descuento (FV2), y esa
 * partición tiene que cumplir dos cosas o no sirve para nada: que los tres
 * cajones sumen exactamente el total, y que el resumen, las tiendas y los
 * vendedores clasifiquen igual.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ReporteDesglosePorTipoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_reportes')->default(true);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado')->default('entregado'); $t->string('serie')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 15, 2)->default(0);
            $t->string('metodo')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte', 'activa' => true]);
        DB::table('usuarios')->insert([
            'id' => 1, 'nombre' => 'Vendedora', 'rol' => 'vendedor', 'tienda_default_id' => 1,
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<bool> $itemsRestauracion  un booleano por ítem
     * @param array<float> $pagos             lo que abonó el cliente
     */
    private function orden(float $valor, ?string $serie, array $itemsRestauracion, array $pagos): int
    {
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => 'entregado', 'serie' => $serie,
            'valor_total' => $valor, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($itemsRestauracion as $esRest) {
            DB::table('orden_items')->insert([
                'orden_id' => $id, 'es_restauracion' => $esRest, 'precio_unitario' => $valor,
            ]);
        }
        foreach ($pagos as $monto) {
            DB::table('pagos')->insert([
                'orden_id' => $id, 'monto' => $monto, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function reporte(string $ruta): array
    {
        $jefe = Usuario::create([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
            'created_at' => now(),
        ]);

        return $this->actingAs($jefe)->getJson($ruta)->assertOk()->json();
    }

    public function test_la_plata_se_parte_en_ventas_restauraciones_y_fv2(): void
    {
        $this->orden(10000000, null, [false, false], [4000000]);   // venta normal
        $this->orden(2000000,  null, [true],          [2000000]);   // solo restauración
        $this->orden(5000000, 'FV2', [false],         [1000000]);   // descuento especial
        // Mixta: un mueble y una restauración. Es una venta, que es como se
        // numera y como se comisiona.
        $this->orden(3000000,  null, [true, false],   [500000]);

        $r = $this->reporte('/api/reportes/ventas?periodo=mes')['resumen'];

        $this->assertEquals(4500000, $r['monto_venta'], 'la mixta cuenta como venta');
        $this->assertEquals(2000000, $r['monto_restauracion']);
        $this->assertEquals(1000000, $r['monto_fv2']);

        // Lo que de verdad importa: que los tres sumen el total cobrado.
        $this->assertEquals(
            (float) $r['total_cobrado'],
            $r['monto_venta'] + $r['monto_restauracion'] + $r['monto_fv2'],
            'si no suman el total, el desglose no sirve para cuadrar nada'
        );

        $this->assertSame(2, $r['ordenes_venta']);
        $this->assertSame(1, $r['ordenes_restauracion']);
        $this->assertSame(1, $r['ordenes_fv2']);
    }

    public function test_una_orden_con_varios_abonos_no_infla_el_valor_bruto(): void
    {
        // El valor de la orden se cuenta UNA vez, por más veces que abone el
        // cliente. Antes salía multiplicado por el número de pagos.
        $this->orden(10000000, null, [false], [3000000, 3000000, 4000000]);

        $r = $this->reporte('/api/reportes/ventas?periodo=mes')['resumen'];

        $this->assertEquals(10000000, $r['valor_bruto']);
        $this->assertEquals(10000000, $r['ticket_promedio']);
        $this->assertEquals(10000000, $r['total_cobrado']);
    }

    public function test_las_tiendas_parten_la_plata_igual_que_el_resumen(): void
    {
        $this->orden(10000000, null,  [false], [4000000]);
        $this->orden(2000000,  null,  [true],  [2000000]);
        $this->orden(5000000,  'FV2', [false], [1000000]);

        $data    = $this->reporte('/api/reportes/ventas?periodo=mes');
        $resumen = $data['resumen'];
        $tienda  = $data['porTienda'][0];

        foreach (['monto_venta', 'monto_restauracion', 'monto_fv2'] as $campo) {
            $this->assertEquals(
                (float) $resumen[$campo], (float) $tienda[$campo],
                "las tiendas y el resumen tienen que partir {$campo} igual"
            );
        }
    }

    public function test_los_vendedores_parten_la_plata_igual_que_el_resumen(): void
    {
        $this->orden(10000000, null,  [false], [4000000]);
        $this->orden(2000000,  null,  [true],  [2000000]);
        $this->orden(5000000,  'FV2', [false], [1000000]);

        $resumen = $this->reporte('/api/reportes/ventas?periodo=mes')['resumen'];
        $vendedor = collect($this->reporte('/api/reportes/vendedores?periodo=mes'))
            ->firstWhere('vendedor_id', 1);

        $this->assertEquals((float) $resumen['monto_venta'],        (float) $vendedor['monto_venta']);
        $this->assertEquals((float) $resumen['monto_restauracion'], (float) $vendedor['monto_restauracion']);
        $this->assertEquals((float) $resumen['monto_fv2'],          (float) $vendedor['monto_fv2']);
    }
}
