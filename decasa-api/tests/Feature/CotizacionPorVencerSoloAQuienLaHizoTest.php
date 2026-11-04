<?php

namespace Tests\Feature;

use App\Jobs\AvisarCotizacionesPorVencer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El recordatorio de cotización por vencer (y vencida) es solo para quien la
 * hizo. Una cotización sin vendedor se les mandaba a todos los supervisores.
 */
class CotizacionPorVencerSoloAQuienLaHizoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('estado'); $t->string('cotizacion_estado')->nullable(); $t->integer('cotizacion_numero')->nullable();
            $t->date('cotizacion_valida_hasta')->nullable(); $t->string('contacto_nombre')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });

        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Supervisora', 'rol' => 'supervisor', 'activo' => true],
            ['id' => 2, 'nombre' => 'Otro supervisor', 'rol' => 'supervisor', 'activo' => true],
            ['id' => 3, 'nombre' => 'Vendedora', 'rol' => 'vendedor', 'activo' => true],
        ]);

        $this->travelTo(Carbon::parse('2026-11-04 08:30'));
    }

    private function cotizacion(?int $vendedorId, string $validaHasta, int $numero): void
    {
        DB::table('ordenes')->insert([
            'vendedor_id' => $vendedorId, 'estado' => 'cotizacion', 'cotizacion_estado' => 'enviada',
            'cotizacion_numero' => $numero, 'cotizacion_valida_hasta' => $validaHasta,
            'contacto_nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_el_aviso_le_llega_solo_a_quien_la_hizo(): void
    {
        $this->cotizacion(3, '2026-11-06', 10); // por vencer
        $this->cotizacion(3, '2026-11-03', 11); // venció ayer

        (new AvisarCotizacionesPorVencer())->handle();

        $this->assertSame([3, 3], DB::table('notificaciones')->orderBy('id')->pluck('usuario_id')->all());
    }

    public function test_sin_vendedor_no_se_le_avisa_a_los_supervisores(): void
    {
        $this->cotizacion(null, '2026-11-06', 12);
        $this->cotizacion(null, '2026-11-03', 13);

        (new AvisarCotizacionesPorVencer())->handle();

        $this->assertSame(0, DB::table('notificaciones')->count());
    }
}
