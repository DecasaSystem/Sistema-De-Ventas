<?php

namespace Tests\Feature;

use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Entrega directa: un vendedor/supervisor autorizado entrega su propia orden
 * sin ruta ni conductor.
 *
 * Se prueba la reja nueva —quién puede abrirla y sobre qué órdenes— y que
 * reusa el despacho del conductor sin pisarlo. El acto de entregar en sí
 * (fotos, inventario) es el mismo código del conductor y ya está cubierto.
 *
 * Esquema a mano: las migraciones no corren en SQLite.
 */
class EntregaDirectaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_entregas')->default(false);
            $t->boolean('ve_todas_ordenes')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('listo_entrega'); $t->decimal('valor_total', 12, 2)->default(0);
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->boolean('es_restauracion')->default(false); $t->string('nombre_custom')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->timestamps();
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('foto_url')->nullable();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('tipo')->nullable(); $t->decimal('monto', 12, 2)->default(0);
            $t->string('metodo')->nullable(); $t->string('referencia')->nullable(); $t->timestamps();
        });
        Schema::create('despachos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('camion_id')->nullable(); $t->unsignedBigInteger('conductor_id')->nullable();
            $t->unsignedBigInteger('entregado_por_id')->nullable(); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->date('fecha_despacho')->nullable(); $t->string('estado')->default('en_ruta');
            $t->string('tipo')->default('ruta'); $t->text('notas')->nullable();
            $t->string('nombre_ruta')->nullable(); $t->text('instrucciones')->nullable(); $t->timestamps();
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id');
            $t->unsignedInteger('posicion')->default(1); $t->string('estado')->default('pendiente');
            $t->string('foto_producto')->nullable(); $t->string('foto_pago')->nullable();
            $t->timestamp('entregado_at')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Norte'],
            ['id' => 2, 'nombre' => 'Sur'],
        ]);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa']);
    }

    private function usuario(string $rol, array $extra = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre' => ucfirst($rol), 'email' => $rol . rand() . '@d.com', 'password' => 'x',
            'rol' => $rol, 'created_at' => now(),
        ], $extra));
    }

    private function orden(array $extra = []): Orden
    {
        $orden = Orden::create(array_merge([
            'cliente_id' => 1, 'tienda_id' => 1, 'estado' => 'listo_entrega', 'valor_total' => 500000,
        ], $extra));
        OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 1,
                           'precio_unitario' => 500000, 'es_personalizado' => false]);

        return $orden;
    }

    private function abrir(Usuario $u, int $ordenId)
    {
        return $this->actingAs($u)->postJson('/api/despacho/entrega-directa', ['orden_id' => $ordenId]);
    }

    // ── Quién puede abrirla ──────────────────────────────────────────────────

    public function test_el_vendedor_dueno_abre_la_entrega_de_su_orden(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $r = $this->abrir($v, $orden->id)->assertCreated();

        $item = DespachoItem::find($r->json('despacho_item_id'));
        $this->assertNotNull($item);
        $this->assertSame('directa', $item->despacho->tipo);
        $this->assertSame($v->id, $item->despacho->entregado_por_id);
        $this->assertNull($item->despacho->conductor_id);
        $this->assertSame('en_ruta', $item->despacho->estado);
    }

    public function test_sin_permiso_no_puede(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => false, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $this->abrir($v, $orden->id)->assertStatus(403);
    }

    public function test_no_puede_entregar_una_orden_de_otra_tienda(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id, 'tienda_id' => 2]);

        $this->abrir($v, $orden->id)->assertStatus(422);
    }

    public function test_no_puede_entregar_una_orden_de_otro_vendedor(): void
    {
        $v    = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $otro = $this->usuario('vendedor', ['tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $otro->id]);

        $this->abrir($v, $orden->id)->assertStatus(422);
    }

    public function test_no_puede_si_todavia_no_esta_lista(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id, 'estado' => 'en_produccion']);

        $this->abrir($v, $orden->id)->assertStatus(422);
    }

    public function test_no_puede_si_ya_esta_en_una_ruta_de_conductor(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $ruta = Despacho::create(['tipo' => 'ruta', 'estado' => 'asignado', 'fecha_despacho' => now()->toDateString()]);
        DespachoItem::create(['despacho_id' => $ruta->id, 'orden_id' => $orden->id, 'posicion' => 1, 'estado' => 'pendiente']);

        $this->abrir($v, $orden->id)->assertStatus(422);
    }

    public function test_el_supervisor_puede_entregar_cualquier_orden_lista(): void
    {
        $sup   = $this->usuario('supervisor', ['acceso_entregas' => true]);
        $otro  = $this->usuario('vendedor', ['tienda_default_id' => 2]);
        $orden = $this->orden(['vendedor_id' => $otro->id, 'tienda_id' => 2]);

        $this->abrir($sup, $orden->id)->assertCreated();
    }

    public function test_abrirla_dos_veces_reusa_la_misma(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $a = $this->abrir($v, $orden->id)->json('despacho_item_id');
        $b = $this->abrir($v, $orden->id)->json('despacho_item_id');

        $this->assertSame($a, $b);
        $this->assertSame(1, DespachoItem::where('orden_id', $orden->id)->count());
    }

    // ── Operarla / cancelarla ────────────────────────────────────────────────

    public function test_otro_vendedor_no_opera_mi_entrega_directa(): void
    {
        $v    = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $otro = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $itemId = $this->abrir($v, $orden->id)->json('despacho_item_id');

        $this->actingAs($otro)->getJson("/api/despacho/mis-entregas/{$itemId}")->assertStatus(403);
        $this->actingAs($v)->getJson("/api/despacho/mis-entregas/{$itemId}")->assertOk();
    }

    public function test_cancelar_libera_la_orden(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $this->abrir($v, $orden->id)->assertCreated();
        $this->actingAs($v)->deleteJson("/api/despacho/entrega-directa/{$orden->id}")->assertOk();

        $this->assertSame(0, DespachoItem::where('orden_id', $orden->id)->count());
        $this->assertSame(0, Despacho::count());
    }

    public function test_no_se_cancela_si_ya_hay_foto_y_pago(): void
    {
        $v = $this->usuario('vendedor', ['acceso_entregas' => true, 'tienda_default_id' => 1]);
        $orden = $this->orden(['vendedor_id' => $v->id]);

        $itemId = $this->abrir($v, $orden->id)->json('despacho_item_id');
        DespachoItem::where('id', $itemId)->update(['foto_producto' => 'https://x/foto.jpg']);

        $this->actingAs($v)->deleteJson("/api/despacho/entrega-directa/{$orden->id}")->assertStatus(422);
        $this->assertSame(1, DespachoItem::where('orden_id', $orden->id)->count());
    }
}
