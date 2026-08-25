<?php

namespace Tests\Feature;

use App\Models\Surtido;
use App\Models\SurtidoItem;
use App\Models\SurtidoTienda;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La remisión del surtido en PDF.
 *
 * Se prueba que SALGA, no cómo se ve: una plantilla puede compilar y aun así
 * reventar al renderizar —una relación sin cargar, un método que no existe—, y
 * eso no se descubre hasta que alguien necesita imprimir la hoja para mandar
 * un camión.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite
 * (hay `ALTER ... MODIFY`, sintaxis de MySQL).
 */
class SurtidoPdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_surtir')->default(false); $t->boolean('no_usa_programa')->default(false);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('categoria')->nullable();
        });
        Schema::create('surtidos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('supervisor_id')->nullable(); $t->text('notas')->nullable();
            $t->string('estado')->default('enviado'); $t->boolean('fuente_fabrica')->default(false);
            $t->timestamp('programado_para')->nullable(); $t->timestamps();
        });
        Schema::create('surtido_tiendas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('vendedor_validador_id')->nullable(); $t->string('estado')->default('pendiente');
            $t->text('notas_vendedor')->nullable(); $t->timestamp('respondido_at')->nullable();
        });
        Schema::create('surtido_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('surtido_tienda_id'); $t->unsignedBigInteger('producto_id');
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->integer('cantidad'); $t->integer('cantidad_aceptada')->nullable(); $t->json('especificaciones')->nullable();
        });
        // Vacías, pero tienen que existir: el PDF carga las variantes de cada
        // línea aunque el surtido no lleve ninguna.
        Schema::create('producto_variantes', function (Blueprint $t) {
            $t->id(); $t->string('nombre')->nullable(); $t->string('color')->nullable();
        });
        Schema::create('producto_variante_configs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tipo_variante_id')->nullable(); $t->unsignedBigInteger('opcion_id')->nullable();
        });
    }

    private function armarSurtido(bool $variasTiendas = false): Surtido
    {
        $jefe = Usuario::create(['nombre' => 'Mónica', 'email' => 'm@d.com', 'password' => 'x',
                                 'rol' => 'supervisor', 'acceso_surtir' => true, 'created_at' => now()]);

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Bodega Fábrica', 'es_fabrica' => true],
            ['id' => 2, 'nombre' => 'Decasa Norte',   'es_fabrica' => false],
            ['id' => 3, 'nombre' => 'Decasa Circunvalar', 'es_fabrica' => false],
        ]);
        DB::table('productos')->insert([
            ['id' => 1, 'nombre' => 'Cama Macarena 1.40', 'categoria' => 'camas'],
            ['id' => 2, 'nombre' => 'Mesa de noche',      'categoria' => 'mesas'],
        ]);

        $surtido = Surtido::create([
            'supervisor_id' => $jefe->id, 'estado' => 'enviado',
            'fuente_fabrica' => true, 'notas' => 'Va con el camión del martes',
        ]);

        $st = SurtidoTienda::create(['surtido_id' => $surtido->id, 'tienda_id' => 2,
                                     'vendedor_validador_id' => $jefe->id, 'estado' => 'aceptado',
                                     'notas_vendedor' => 'Llegó una mesa golpeada']);
        // Una línea completa y otra incompleta: lo que falta es justo lo que la
        // remisión tiene que dejar en evidencia.
        SurtidoItem::create(['surtido_tienda_id' => $st->id, 'producto_id' => 1, 'cantidad' => 3, 'cantidad_aceptada' => 3]);
        SurtidoItem::create(['surtido_tienda_id' => $st->id, 'producto_id' => 2, 'cantidad' => 2, 'cantidad_aceptada' => 1]);

        if ($variasTiendas) {
            $st2 = SurtidoTienda::create(['surtido_id' => $surtido->id, 'tienda_id' => 3,
                                          'vendedor_validador_id' => $jefe->id, 'estado' => 'pendiente']);
            SurtidoItem::create(['surtido_tienda_id' => $st2->id, 'producto_id' => 1, 'cantidad' => 1]);
        }

        return $surtido;
    }

    private function jefe(): Usuario
    {
        return Usuario::where('acceso_surtir', true)->firstOrFail();
    }

    public function test_la_remision_sale_en_pdf(): void
    {
        $surtido = $this->armarSurtido();

        $res = $this->actingAs($this->jefe())->get("/api/inventario/surtidos/{$surtido->id}/pdf");

        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_se_puede_sacar_la_de_una_sola_tienda(): void
    {
        $surtido = $this->armarSurtido(variasTiendas: true);
        $primera = $surtido->tiendas()->first();

        $res = $this->actingAs($this->jefe())
            ->get("/api/inventario/surtidos/{$surtido->id}/pdf?tienda={$primera->id}");

        $res->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());

        // Una tienda que no está en este envío no devuelve la hoja de otra.
        $this->actingAs($this->jefe())
            ->get("/api/inventario/surtidos/{$surtido->id}/pdf?tienda=9999")
            ->assertStatus(404);
    }

    public function test_sin_permiso_de_surtir_no_se_baja_la_remision(): void
    {
        $surtido = $this->armarSurtido();
        $ajeno = Usuario::create(['nombre' => 'Ajeno', 'email' => 'a@d.com', 'password' => 'x',
                                  'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($ajeno)->get("/api/inventario/surtidos/{$surtido->id}/pdf")->assertStatus(403);
    }
}
