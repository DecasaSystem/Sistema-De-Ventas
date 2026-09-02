<?php

namespace Tests\Feature;

use App\Http\Controllers\UsuarioController;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La lista de asesores se excluye a sí misma, menos cuando no debe.
 *
 * Nació para compartir una venta, y nadie la comparte consigo mismo. Pero la
 * misma lista arma el equipo de una tienda en comisiones, y ahí sí hay que
 * poder agregarse: Juan David era el único de Tienda Virtual con acceso a
 * comisiones, así que era justo el que no podía meterse a ningún equipo — no
 * aparecía en el selector.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class SelectorDeAsesoresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            // La tabla real la tiene: de ella sale si la comision es del
            // equipo o de cada quien.
            $t->boolean('comisiones_compartidas')->default(false);
        });

        DB::table('tiendas')->insert(['id' => 7, 'nombre' => 'Tienda Virtual', 'activa' => true]);

        foreach ([
            [25, 'Juan David', 'supervisor', 7,    true],
            [18, 'Jhoan',      'vendedor',   7,    true],
            // Ninguno de estos dos sale nunca: uno no vende y el otro se fue.
            [30, 'Omar',       'ebanista',   null, true],
            [31, 'Retirado',   'vendedor',   null, false],
        ] as [$id, $nombre, $rol, $tienda, $activo]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => $rol,
                'tienda_default_id' => $tienda, 'activo' => $activo, 'created_at' => now(),
            ]);
        }
    }

    /** @return array<string> los nombres que devuelve el selector */
    private function selectorSegun(int $usuarioId, array $params = []): array
    {
        $quien = Usuario::find($usuarioId);
        $req   = Request::create('/', 'GET', $params);
        $req->setUserResolver(fn () => $quien);

        return collect(json_decode(app(UsuarioController::class)->asesores($req)->content(), true))
            ->pluck('nombre')->all();
    }

    public function test_para_compartir_una_venta_uno_no_sale_en_su_propia_lista(): void
    {
        $this->assertSame(['Jhoan'], $this->selectorSegun(25));
        $this->assertSame(['Juan David'], $this->selectorSegun(18));
    }

    public function test_para_armar_el_equipo_de_una_tienda_uno_si_sale(): void
    {
        $lista = $this->selectorSegun(25, ['incluirme' => 1]);

        $this->assertContains('Juan David', $lista, 'sin esto no podía meterse a ningún equipo');
        $this->assertContains('Jhoan', $lista);
        $this->assertCount(2, $lista);
    }

    public function test_ni_el_taller_ni_los_retirados_salen_nunca(): void
    {
        foreach ([[], ['incluirme' => 1]] as $params) {
            $lista = $this->selectorSegun(25, $params);
            $this->assertNotContains('Omar', $lista, 'un ebanista no es asesor');
            $this->assertNotContains('Retirado', $lista);
        }
    }
}
