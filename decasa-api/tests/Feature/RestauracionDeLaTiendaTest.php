<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El 5% de una restauración hecha en una tienda es del equipo, no de quien la
 * hizo.
 *
 * Se estaba pagando entero a quien la hacía. En una tienda ese 5% se parte
 * entre los que están, igual que el pool y que lo que deja un independiente:
 * si son dos, 2,5% para cada uno; si son tres, el 5% partido en tres.
 *
 * Es lo que hacía que Paola saliera con $19.000 más que Marta en agosto —una
 * restauración de $380.000 que cobró completa— cuando en Decasa Norte todos
 * cobran lo mismo.
 *
 * Quien trabaja por su cuenta no reparte con nadie: sigue cobrando el 5%.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RestauracionDeLaTiendaTest extends TestCase
{
    private const MES = '2026-08';

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
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable();
            $t->string('nota')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 15, 2)->default(0);
            $t->string('metodo')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Norte', 'activa' => true],
            ['id' => 2, 'nombre' => 'Decasa Vía El Edén', 'activa' => true],
        ]);

        // Las dos con meta: sin meta no hay reparto de ninguna clase, ni del
        // pool ni de las restauraciones.
        DB::table('metas_tienda')->insert([
            ['tienda_id' => 1, 'mes' => self::MES, 'meta' => 40_000_000,
             'divisor_asesores' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['tienda_id' => 2, 'mes' => self::MES, 'meta' => 20_000_000,
             'divisor_asesores' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([[1, 'Paola', 1], [2, 'Marta', 1], [3, 'NN', 1],
                  [4, 'Gladys', 2], [5, 'Sebastián', 2]] as [$id, $nombre, $tienda]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor',
                'tienda_default_id' => $tienda, 'created_at' => now(),
            ]);
        }

        // Henry trabaja por su cuenta: no pertenece a ninguna tienda.
        DB::table('usuarios')->insert([
            'id' => 9, 'nombre' => 'Henry', 'rol' => 'vendedor',
            'independiente' => true, 'tienda_default_id' => null, 'created_at' => now(),
        ]);

        // Los equipos del mes: tres en Norte, dos en El Edén.
        foreach ([[1, 1], [1, 2], [1, 3], [2, 4], [2, 5]] as [$tienda, $vendedor]) {
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => $tienda, 'mes' => self::MES, 'vendedor_id' => $vendedor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->olvidarLoQueEstabaEnMemoria();
    }

    /**
     * Los equipos y los reemplazos se guardan en memoria por mes para no
     * preguntarlos varias veces por petición. Entre pruebas eso no se borra
     * solo —son estáticos y el proceso es el mismo—, y armar el escenario con
     * el query builder tampoco dispara los eventos que lo limpiarían.
     */
    private function olvidarLoQueEstabaEnMemoria(): void
    {
        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
    }

    /** Una restauración: todos sus ítems lo son. */
    private function restauracion(int $vendedorId, ?int $tiendaId, float $valor, string $dia = '15'): Orden
    {
        $orden = Orden::create([
            'tienda_id' => $tiendaId, 'vendedor_id' => $vendedorId, 'estado' => 'entregado',
            'valor_total' => $valor,
        ]);

        // La fecha va aparte: `created_at` no se asigna en masa, y de ella
        // dependen el mes de la comisión y quién estaba en la tienda ese día.
        DB::table('ordenes')->where('id', $orden->id)
            ->update(['created_at' => self::MES . '-' . $dia . ' 15:00:00']);

        DB::table('orden_items')->insert([
            'orden_id' => $orden->id, 'es_restauracion' => true, 'precio_unitario' => $valor,
        ]);
        DB::table('pagos')->insert([
            'orden_id' => $orden->id, 'monto' => $valor, 'created_at' => now(),
        ]);

        ComisionController::crearParaOrden($orden->fresh());

        return $orden->fresh();
    }

    /** [vendedor_id => valor_orden] de lo que quedó registrado para esa orden. */
    private function repartoDe(Orden $orden): array
    {
        return Comision::where('orden_id', $orden->id)
            ->orderBy('vendedor_id')->pluck('valor_orden', 'vendedor_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    public function test_entre_tres_el_cinco_por_ciento_se_parte_en_tres(): void
    {
        // La restauración de Paola que la hacía cobrar más que Marta.
        $orden = $this->restauracion(1, 1, 380_000, '31');

        $reparto = $this->repartoDe($orden);

        $this->assertCount(3, $reparto, 'le toca a todo el equipo de la tienda');
        foreach ([1, 2, 3] as $uid) {
            $this->assertEqualsWithDelta(126_666.67, $reparto[$uid], 1,
                'la base de cada uno es el valor partido en tres');
        }

        // Sobre esa base, el 5% de siempre: $6.333 para cada uno en vez de los
        // $19.000 completos para Paola.
        foreach ($reparto as $base) {
            $this->assertEqualsWithDelta(6_333, round($base * ComisionController::PORCENTAJE_DIRECTO), 1);
        }

        $this->assertSame(
            [ComisionController::ORIGEN_RESTAURACION_EQUIPO],
            Comision::where('orden_id', $orden->id)->pluck('origen')->unique()->values()->all()
        );
    }

    public function test_entre_dos_le_toca_dos_y_medio_por_ciento_a_cada_uno(): void
    {
        $orden = $this->restauracion(4, 2, 1_000_000);

        $reparto = $this->repartoDe($orden);

        $this->assertCount(2, $reparto);
        $this->assertEquals([4 => 500_000.0, 5 => 500_000.0], $reparto);

        // 5% de medio millón = $25.000, que es el 2,5% del millón.
        $suCinco = round(500_000 * ComisionController::PORCENTAJE_DIRECTO);
        $this->assertEquals(25_000, $suCinco);
        $this->assertEquals(1_000_000 * 0.025, $suCinco);
    }

    public function test_quien_trabaja_por_su_cuenta_no_reparte_con_nadie(): void
    {
        $orden = $this->restauracion(9, null, 5_000_000);

        // Sin tienda no hay equipo detrás: la comisión ni siquiera se crea por
        // este camino, y si la hay es suya entera.
        $filas = Comision::where('orden_id', $orden->id)->get();

        $this->assertLessThanOrEqual(1, $filas->count());
        foreach ($filas as $f) {
            $this->assertSame(9, (int) $f->vendedor_id);
            $this->assertNotSame(ComisionController::ORIGEN_RESTAURACION_EQUIPO, $f->origen);
        }
    }

    public function test_solo_en_la_tienda_se_lleva_el_cinco_por_ciento_entero(): void
    {
        // Se queda sola: los otros dos salen del equipo del mes.
        DB::table('tienda_asesores_comision')->whereIn('vendedor_id', [2, 3])->delete();
        $this->olvidarLoQueEstabaEnMemoria();

        $orden = $this->restauracion(1, 1, 380_000);

        $reparto = $this->repartoDe($orden);

        $this->assertCount(1, $reparto, 'no hay con quién partir');
        $this->assertEquals(380_000.0, $reparto[1]);
    }

    public function test_quien_no_es_del_equipo_de_esa_tienda_cobra_lo_suyo(): void
    {
        // Gladys es de El Edén; la orden quedó cargada a Decasa Norte.
        $orden = $this->restauracion(4, 1, 600_000);

        $reparto = $this->repartoDe($orden);

        $this->assertCount(1, $reparto, 'no entra en el reparto de una tienda que no es la suya');
        $this->assertEquals(600_000.0, $reparto[4]);
    }

    public function test_quien_vino_a_cubrir_ese_dia_entra_y_el_cubierto_no(): void
    {
        // Marta cubre a Paola del 10 al 20.
        DB::table('tienda_reemplazos')->insert([
            'tienda_id' => 1, 'tipo' => 'reemplazo', 'usuario_id' => 2, 'reemplaza_a_id' => 1,
            'desde' => self::MES . '-10', 'hasta' => self::MES . '-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->olvidarLoQueEstabaEnMemoria();

        // Restauración del día 15: ese día Paola no estaba.
        $orden = $this->restauracion(2, 1, 900_000, '15');

        $reparto = $this->repartoDe($orden);

        $this->assertArrayNotHasKey(1, $reparto, 'Paola estaba fuera ese día');
        $this->assertArrayHasKey(2, $reparto);
        $this->assertArrayHasKey(3, $reparto);
        $this->assertEquals([2 => 450_000.0, 3 => 450_000.0], $reparto);
    }

    public function test_si_deja_de_ser_restauracion_vuelve_a_ser_de_quien_la_hizo(): void
    {
        $orden = $this->restauracion(1, 1, 380_000);
        $this->assertCount(3, $this->repartoDe($orden));

        // Se corrigió: no era restauración.
        DB::table('orden_items')->where('orden_id', $orden->id)->update(['es_restauracion' => false]);

        ComisionController::sincronizarValorOrden($orden->fresh());

        $reparto = $this->repartoDe($orden);
        $this->assertCount(1, $reparto, 'a los demás se les quita');
        $this->assertEquals(380_000.0, $reparto[1], 'y a ella se le devuelve entera');
        $this->assertSame('venta', Comision::where('orden_id', $orden->id)->value('origen'));
    }

    public function test_lo_ya_pagado_no_se_reparte(): void
    {
        $orden = $this->restauracion(1, 1, 380_000);

        // Se le pagó a Paola con la regla vieja, el 5% entero.
        Comision::where('orden_id', $orden->id)->delete();
        Comision::create([
            'orden_id' => $orden->id, 'vendedor_id' => 1, 'tienda_id' => 1,
            'mes_venta' => self::MES, 'valor_orden' => 380_000, 'estado' => 'pagada',
            'fecha_venta' => self::MES . '-15', 'fecha_disponible' => '2026-09-20',
        ]);

        ComisionController::sincronizarValorOrden($orden->fresh());

        $reparto = $this->repartoDe($orden);
        $this->assertCount(1, $reparto, 'quitarle plata que ya recibió no es corregir');
        $this->assertEquals(380_000.0, $reparto[1]);
    }
}
