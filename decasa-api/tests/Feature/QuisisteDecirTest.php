<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\NombresParecidos;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "¿Quisiste decir…?" del buscador de órdenes.
 *
 * Quien busca a veces no se acuerda bien del nombre o lo escribe como lo oyó.
 * Se prueba qué errores se perdonan, cuáles no (para no sugerir a medio
 * mundo), y que a un vendedor no se le sugieran clientes de órdenes ajenas.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class QuisisteDecirTest extends TestCase
{
    private const CLIENTES = [
        'Mayra González', 'María José Pérez', 'Ana Ruiz', 'Ana Ruiz de la Torre',
        'Carlos Restrepo', 'Luis Díaz', 'Jhon Fredy Ocampo',
    ];

    public function test_perdona_letras_cambiadas_tildes_y_mayusculas(): void
    {
        $this->assertSame(['Mayra González'], NombresParecidos::para('maira gonsales', self::CLIENTES));
        $this->assertSame(['Carlos Restrepo'], NombresParecidos::para('CARLOS RESTREPO', self::CLIENTES));
        $this->assertSame(['Jhon Fredy Ocampo'], NombresParecidos::para('john fredi', self::CLIENTES));
    }

    public function test_en_cualquier_orden_y_a_medio_escribir(): void
    {
        $this->assertSame(['Mayra González'], NombresParecidos::para('gonzales mayra', self::CLIENTES));
        // "restrep" con la palabra a medias, y "gonsal" a medias y con un error.
        $this->assertSame(['Carlos Restrepo'], NombresParecidos::para('restrep', self::CLIENTES));
        $this->assertSame(['Mayra González'], NombresParecidos::para('gonsal', self::CLIENTES));
    }

    public function test_el_mas_parecido_va_primero(): void
    {
        // Los dos se llaman Ana Ruiz; el que no tiene palabras de más va antes.
        $this->assertSame(['Ana Ruiz', 'Ana Ruiz de la Torre'], NombresParecidos::para('ana ruis', self::CLIENTES));
    }

    public function test_no_sugiere_lo_que_no_se_parece(): void
    {
        $this->assertSame([], NombresParecidos::para('pedro', self::CLIENTES));
        // En una palabra corta una letra ya es otra palabra: "luz" no es "Luis".
        $this->assertSame([], NombresParecidos::para('luz', self::CLIENTES));
        // Solo palabras de relleno: no dicen a quién se busca.
        $this->assertSame([], NombresParecidos::para('de la', self::CLIENTES));
    }

    public function test_el_mismo_nombre_repetido_sale_una_vez(): void
    {
        $this->assertSame(['Luis Díaz'], NombresParecidos::para('luis dias', ['Luis Díaz', 'LUIS DIAZ', 'luis díaz']));
    }

    // ── El endpoint ──────────────────────────────────────────────────────────

    private function esquema(): void
    {
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(false); $t->boolean('facturacion')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('estado')->default('pendiente_anticipo'); $t->timestamps();
        });
    }

    private function usuario(string $rol, string $email): Usuario
    {
        return Usuario::forceCreate(['nombre' => $email, 'email' => $email, 'password' => 'x', 'rol' => $rol]);
    }

    public function test_a_un_vendedor_solo_le_sugiere_sus_clientes(): void
    {
        $this->esquema();
        $ana   = $this->usuario('vendedor', 'ana@d.com');
        $otro  = $this->usuario('vendedor', 'otro@d.com');
        $jefa  = $this->usuario('supervisor', 'jefa@d.com');

        $suyo  = DB::table('clientes')->insertGetId(['nombre' => 'Mayra González']);
        $ajeno = DB::table('clientes')->insertGetId(['nombre' => 'Maira Gonzaga']);
        DB::table('ordenes')->insert([
            ['cliente_id' => $suyo,  'vendedor_id' => $ana->id],
            ['cliente_id' => $ajeno, 'vendedor_id' => $otro->id],
        ]);

        $this->actingAs($ana)->getJson('/api/ordenes/sugerencias?q=maira gonsales')
            ->assertOk()->assertExactJson([['nombre' => 'Mayra González']]);

        // Quien ve todas las órdenes ve las dos.
        $nombres = collect($this->actingAs($jefa)->getJson('/api/ordenes/sugerencias?q=maira gonza')->json())
            ->pluck('nombre')->sort()->values()->all();
        $this->assertSame(['Maira Gonzaga', 'Mayra González'], $nombres);
    }

    public function test_un_numero_o_una_serie_no_pide_sugerencias(): void
    {
        $this->esquema();
        $jefa = $this->usuario('supervisor', 'jefa@d.com');

        $this->actingAs($jefa)->getJson('/api/ordenes/sugerencias?q=R-1098')->assertExactJson([]);
        $this->actingAs($jefa)->getJson('/api/ordenes/sugerencias?q=ma')->assertExactJson([]);
    }
}
