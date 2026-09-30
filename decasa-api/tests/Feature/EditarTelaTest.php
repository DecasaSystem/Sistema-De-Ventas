<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Corregir una tela que se creó mal: el nombre con la referencia pegada, un
 * color mal escrito, otro proveedor.
 *
 * Las órdenes guardan su tela como texto ("Proveedor · Nombre · Color") y con
 * eso se enlazan a lo que tienen apartado: con metros apartados, esos tres no
 * se tocan. Y una corrección no puede dejar dos telas iguales.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class EditarTelaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('recarga_telas')->default(false); $t->timestamps();
        });
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('marca'); $t->string('tipo'); $t->string('color');
            $t->string('referencia')->nullable(); $t->string('textura')->nullable(); $t->string('foto_url')->nullable();
            $t->decimal('metros_disponibles', 10, 2)->default(0); $t->decimal('metros_reservados', 10, 2)->default(0);
            $t->boolean('activo')->default(true); $t->timestamps();
            // Sin índice único: la regla (proveedor + nombre + referencia +
            // color) la cuida el programa. Ver la migración 2026_10_05_000002.
            $t->index(['marca', 'tipo', 'color']);
        });
        Schema::create('tela_reservas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->unsignedBigInteger('catalogo_tela_id');
            $t->decimal('metros', 8, 2); $t->string('estado', 20)->default('reservada'); $t->timestamps();
        });
    }

    private function supervisor(): Usuario
    {
        return Usuario::forceCreate(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor']);
    }

    private function tela(array $extra = []): int
    {
        return DB::table('catalogo_telas')->insertGetId(array_merge(
            ['marca' => 'Arthometextil', 'tipo' => 'Terciopelo Hielo', 'color' => 'Gris plata', 'metros_disponibles' => 6],
            $extra,
        ));
    }

    public function test_corrige_el_nombre_con_la_referencia_pegada(): void
    {
        $id = $this->tela();

        $this->actingAs($this->supervisor())
            ->patchJson("/api/catalogo-telas/{$id}", ['tipo' => ' Terciopelo ', 'referencia' => 'Hielo'])
            ->assertOk()
            ->assertJsonFragment(['tipo' => 'Terciopelo', 'referencia' => 'Hielo', 'color' => 'Gris plata'])
            // Los metros no se tocan al editar.
            ->assertJsonFragment(['metros_disponibles' => 6.0]);
    }

    public function test_con_metros_apartados_no_se_cambia_el_nombre_pero_la_referencia_si(): void
    {
        $id = $this->tela(['metros_reservados' => 2]);
        $jefa = $this->supervisor();

        $this->actingAs($jefa)->patchJson("/api/catalogo-telas/{$id}", ['tipo' => 'Terciopelo'])
            ->assertStatus(422);
        $this->assertSame('Terciopelo Hielo', DB::table('catalogo_telas')->where('id', $id)->value('tipo'));

        $this->actingAs($jefa)->patchJson("/api/catalogo-telas/{$id}", ['referencia' => 'Hielo', 'textura' => 'Lisa'])
            ->assertOk();
        $this->assertSame('Hielo', DB::table('catalogo_telas')->where('id', $id)->value('referencia'));
    }

    public function test_una_reserva_viva_tambien_bloquea_el_nombre(): void
    {
        $id = $this->tela();
        DB::table('tela_reservas')->insert(['orden_item_id' => 1, 'catalogo_tela_id' => $id, 'metros' => 1, 'estado' => 'reservada']);

        $this->actingAs($this->supervisor())->patchJson("/api/catalogo-telas/{$id}", ['color' => 'Gris'])
            ->assertStatus(422);
    }

    public function test_no_deja_dos_telas_iguales(): void
    {
        $this->tela(['tipo' => 'Terciopelo', 'color' => 'Gris plata']);
        $id = $this->tela();

        $this->actingAs($this->supervisor())->patchJson("/api/catalogo-telas/{$id}", ['tipo' => 'Terciopelo'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ya existe "Terciopelo" en Gris plata de Arthometextil. Si es la misma, elimina esta y recárgale los metros a esa.']);
    }

    public function test_misma_tela_y_color_con_otra_referencia_es_otra_tela(): void
    {
        // El caso real: LAYLA 01 CRUDO BEIGE ya existe, y la que se creó como
        // "LAYLA 02 PERLA" se corrige a nombre LAYLA con referencia 02 PERLA.
        $this->tela(['tipo' => 'LAYLA', 'referencia' => '01 CRUDO', 'color' => 'BEIGE', 'metros_disponibles' => 2]);
        $id = $this->tela(['tipo' => 'LAYLA 02 PERLA', 'referencia' => 'LAYLA 02 PERLA', 'color' => 'BEIGE', 'metros_disponibles' => 28]);

        $this->actingAs($this->supervisor())
            ->patchJson("/api/catalogo-telas/{$id}", ['tipo' => 'LAYLA', 'referencia' => '02 PERLA'])
            ->assertOk()
            ->assertJsonFragment(['nombre_venta' => 'LAYLA 02 PERLA', 'metros_disponibles' => 28.0]);
    }

    public function test_misma_referencia_y_color_si_es_la_misma_tela(): void
    {
        $this->tela(['tipo' => 'LAYLA', 'referencia' => '01 CRUDO', 'color' => 'BEIGE']);
        $id = $this->tela(['tipo' => 'LAYLA', 'referencia' => '02 PERLA', 'color' => 'BEIGE']);

        $this->actingAs($this->supervisor())
            ->patchJson("/api/catalogo-telas/{$id}", ['referencia' => ' 01 crudo '])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ya existe "LAYLA 01 crudo" en BEIGE de Arthometextil. Si es la misma, elimina esta y recárgale los metros a esa.']);
    }

    public function test_crear_dos_referencias_del_mismo_nombre_y_color(): void
    {
        $jefa = $this->supervisor();
        foreach (['01 CRUDO', '02 PERLA'] as $ref) {
            $this->actingAs($jefa)->postJson('/api/catalogo-telas', [
                'marca' => 'Arthometextil', 'tipo' => 'LAYLA', 'referencia' => $ref, 'color' => 'BEIGE', 'metros_iniciales' => 3,
            ])->assertCreated();
        }
        // La misma otra vez no crea una nueva: le suma los metros.
        $this->actingAs($jefa)->postJson('/api/catalogo-telas', [
            'marca' => 'Arthometextil', 'tipo' => 'LAYLA', 'referencia' => '01 CRUDO', 'color' => 'BEIGE', 'metros_iniciales' => 1,
        ])->assertCreated()->assertJsonFragment(['metros_disponibles' => 4.0]);

        $this->assertSame(2, DB::table('catalogo_telas')->where('tipo', 'LAYLA')->count());
    }

    public function test_con_metros_apartados_la_referencia_tampoco_se_cambia(): void
    {
        $id = $this->tela(['tipo' => 'LAYLA', 'referencia' => '01 CRUDO', 'color' => 'BEIGE', 'metros_reservados' => 1]);

        $this->actingAs($this->supervisor())
            ->patchJson("/api/catalogo-telas/{$id}", ['referencia' => '01 CRUDOS'])
            ->assertStatus(422);
    }

    public function test_quien_no_es_supervisor_no_edita(): void
    {
        $id = $this->tela();
        $vendedor = Usuario::forceCreate(['nombre' => 'V', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor', 'recarga_telas' => true]);

        $this->actingAs($vendedor)->patchJson("/api/catalogo-telas/{$id}", ['tipo' => 'Otro'])->assertStatus(403);
    }

    public function test_la_migracion_quita_la_referencia_unica_y_se_puede_correr_dos_veces(): void
    {
        // La tabla como estaba en producción: la referencia, única.
        Schema::drop('catalogo_telas');
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('marca'); $t->string('tipo'); $t->string('color');
            $t->string('referencia')->nullable()->unique();
            $t->unique(['marca', 'tipo', 'color']);
        });
        $this->assertTrue(Schema::hasIndex('catalogo_telas', 'catalogo_telas_referencia_unique'));

        $migracion = require database_path('migrations/2026_10_05_000001_referencia_de_tela_se_comparte_entre_colores.php');
        $migracion->up();
        $migracion->up();   // en un segundo despliegue no falla

        $this->assertFalse(Schema::hasIndex('catalogo_telas', 'catalogo_telas_referencia_unique'));
        DB::table('catalogo_telas')->insert([
            ['marca' => 'A', 'tipo' => 'T', 'color' => 'Gris',  'referencia' => 'Hielo'],
            ['marca' => 'A', 'tipo' => 'T', 'color' => 'Beige', 'referencia' => 'Hielo'],
        ]);
        $this->assertSame(2, DB::table('catalogo_telas')->where('referencia', 'Hielo')->count());
    }

    public function test_varios_colores_comparten_la_misma_referencia(): void
    {
        $jefa = $this->supervisor();
        foreach (['Gris plata', 'Beige', 'Azul'] as $color) {
            $this->actingAs($jefa)->postJson('/api/catalogo-telas', [
                'marca' => 'Arthometextil', 'tipo' => 'Terciopelo', 'referencia' => 'Hielo',
                'color' => $color, 'metros_iniciales' => 5,
            ])->assertCreated();
        }

        $this->assertSame(3, DB::table('catalogo_telas')->where('referencia', 'Hielo')->count());
    }

    // ── Cómo la encuentran las órdenes ───────────────────────────────────────

    public function test_nombre_de_venta(): void
    {
        $n = fn ($tipo, $ref) => \App\Models\CatalogoTela::nombreVenta($tipo, $ref);
        $this->assertSame('LAYLA 01 CRUDO', $n('LAYLA', '01 CRUDO'));
        // Las del Excel: nombre y referencia son el mismo texto. No cambian.
        $this->assertSame('ADARA 10 HUMO', $n('ADARA 10 HUMO', 'ADARA 10 HUMO'));
        // El nombre ya trae la referencia, o no hay referencia.
        $this->assertSame('LAYLA 02 PERLA', $n('LAYLA 02 PERLA', '02 PERLA'));
        $this->assertSame('Terciopelo', $n('Terciopelo', null));
    }

    public function test_la_orden_encuentra_su_tela_por_el_nombre_de_venta(): void
    {
        $crudo = $this->tela(['tipo' => 'LAYLA', 'referencia' => '01 CRUDO', 'color' => 'BEIGE']);
        $perla = $this->tela(['tipo' => 'LAYLA', 'referencia' => '02 PERLA', 'color' => 'BEIGE']);

        $this->assertSame($perla, \App\Services\ConsumoTelas::telaDeTexto('Arthometextil · LAYLA 02 PERLA · BEIGE')?->id);
        $this->assertSame($crudo, \App\Services\ConsumoTelas::telaDeTexto('Arthometextil · LAYLA 01 CRUDO · BEIGE')?->id);
        // Una orden de antes, que guardó el nombre a secas cuando había una
        // sola LAYLA en BEIGE: se queda con esa, la más antigua.
        $this->assertSame($crudo, \App\Services\ConsumoTelas::telaDeTexto('Arthometextil · LAYLA · BEIGE')?->id);
    }

    public function test_el_catalogo_de_las_ordenes_las_separa(): void
    {
        $this->tela(['tipo' => 'LAYLA', 'referencia' => '01 CRUDO', 'color' => 'BEIGE']);
        $this->tela(['tipo' => 'LAYLA', 'referencia' => '02 PERLA', 'color' => 'BEIGE']);

        $tipos = collect($this->actingAs($this->supervisor())->getJson('/api/catalogo-telas')->json('0.tipos'))
            ->pluck('tipo')->sort()->values()->all();
        $this->assertSame(['LAYLA 01 CRUDO', 'LAYLA 02 PERLA'], $tipos);
    }

    public function test_asegurar_no_duplica_la_tela_de_una_variante(): void
    {
        \App\Models\CatalogoTela::asegurar('Arthometextil', 'Bershka', 'Ivory');
        \App\Models\CatalogoTela::asegurar('Arthometextil', 'Bershka', 'Ivory');

        $this->assertSame(1, DB::table('catalogo_telas')->where('tipo', 'Bershka')->count());
    }

    public function test_la_segunda_migracion_quita_el_unico_de_nombre_y_color(): void
    {
        Schema::drop('catalogo_telas');
        Schema::create('catalogo_telas', function (Blueprint $t) {
            $t->id(); $t->string('marca'); $t->string('tipo'); $t->string('color'); $t->string('referencia')->nullable();
            $t->unique(['marca', 'tipo', 'color']);
        });

        $migracion = require database_path('migrations/2026_10_05_000002_tela_se_distingue_tambien_por_referencia.php');
        $migracion->up();
        $migracion->up();

        $this->assertFalse(Schema::hasIndex('catalogo_telas', 'catalogo_telas_marca_tipo_color_unique'));
        $this->assertTrue(Schema::hasIndex('catalogo_telas', 'catalogo_telas_marca_tipo_color_index'));
        DB::table('catalogo_telas')->insert([
            ['marca' => 'A', 'tipo' => 'LAYLA', 'color' => 'BEIGE', 'referencia' => '01 CRUDO'],
            ['marca' => 'A', 'tipo' => 'LAYLA', 'color' => 'BEIGE', 'referencia' => '02 PERLA'],
        ]);
        $this->assertSame(2, DB::table('catalogo_telas')->count());
    }
}
