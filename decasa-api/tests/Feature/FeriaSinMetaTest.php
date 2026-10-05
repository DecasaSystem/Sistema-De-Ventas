<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use App\Models\TiendaAsesor;
use App\Models\TiendaReemplazo;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Una tienda temporal de feria: sin meta, sin equipo con quién repartir y una
 * sola persona. Ella cobra el 5% de lo que vende, con las reglas de siempre:
 *
 *   venta        -> (valor − 5,5% de lo cobrado con tarjeta o Addi) ÷ 1,19 × 5%
 *   restauración -> valor × 5% (sin quitar IVA)
 *
 * y con los mismos requisitos para estar lista: el cliente pagó la mitad y
 * llegó el día 20 del mes siguiente. Lo que ella vende no toca la meta ni el
 * pool de ninguna otra tienda.
 *
 * Las cifras esperadas están sacadas a mano, para que la prueba no repita la
 * fórmula del código.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class FeriaSinMetaTest extends TestCase
{
    private const MES   = '2026-10';
    private const NORTE = 1;
    private const FERIA = 2;
    private const PAOLA = 10;   // del equipo de Norte (con meta)
    private const LAURA = 20;   // la de la feria

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_comisiones')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
            $t->boolean('es_independientes')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
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
        Schema::create('comisiones', function (Blueprint $t) { $t->string('clave_unica')->nullable()->unique(); $t->string('forma_pago_pagada')->nullable();
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
            $t->unsignedBigInteger('tienda_vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable(); $t->string('canal')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('en_produccion'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2)->default(0);
            $t->string('metodo')->nullable(); $t->string('tipo')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('tienda_trimestres', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('trimestre', 7);
            $t->decimal('deficit_inicial', 15, 2)->default(0);
            $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0);
            $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamp('cerrado_at')->nullable();
            $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => self::NORTE, 'nombre' => 'Decasa Norte', 'activa' => true, 'comisiones_compartidas' => true],
            // La feria: sin meta y sin compartir. Así se crea la tienda.
            ['id' => self::FERIA, 'nombre' => 'Feria Expo', 'activa' => true, 'comisiones_compartidas' => false],
        ]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => self::NORTE, 'mes' => self::MES, 'meta' => 40_000_000,
            'divisor_asesores' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[self::PAOLA, 'Paola', self::NORTE], [self::LAURA, 'Laura', self::FERIA]] as [$id, $nombre, $tienda]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor', 'tienda_default_id' => $tienda,
                'email' => "$id@d.com", 'password' => 'x', 'created_at' => now(),
            ]);
        }
        DB::table('tienda_asesores_comision')->insert([
            'tienda_id' => self::NORTE, 'mes' => self::MES, 'vendedor_id' => self::PAOLA,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TiendaAsesor::olvidarCache();
        TiendaReemplazo::olvidarCache();
        ComisionController::olvidarQuienComparte();
        $this->prestarleASqliteLoQueEsDeMysql();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Una orden con sus pagos, y su comisión creada como en la vida real.
     *
     * @param array<array{0:string,1:float}> $pagos [metodo, monto]
     */
    private function orden(int $vendedor, int $tienda, float $valor, array $pagos, bool $restauracion = false, string $canal = 'fisica'): int
    {
        $id = DB::table('ordenes')->insertGetId([
            'tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'tienda_vendedor_id' => $tienda,
            'canal' => $canal, 'estado' => 'en_produccion', 'valor_total' => $valor,
            'created_at' => self::MES . '-15 15:00:00', 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert(['orden_id' => $id, 'es_restauracion' => $restauracion, 'precio_unitario' => $valor]);
        foreach ($pagos as [$metodo, $monto]) {
            DB::table('pagos')->insert(['orden_id' => $id, 'monto' => $monto, 'metodo' => $metodo, 'tipo' => 'anticipo', 'created_at' => now()]);
        }
        ComisionController::crearParaOrden(Orden::with('pagos')->find($id));

        return $id;
    }

    /** Lo que muestra la pantalla de comisiones, por orden. */
    private function resumen(): array
    {
        $filas = app(ComisionController::class)->resumenDelMes(self::MES);
        $porOrden = [];
        foreach ($filas as $f) {
            foreach ($f['ordenes'] as $o) {
                $porOrden[$o['orden_id']] = $o + ['tienda_fila' => $f['tienda_id'], 'vendedor_fila' => $f['vendedor_id']];
            }
        }

        return [$filas, $porOrden];
    }

    public function test_cobra_el_5_por_ciento_de_todo_sin_meta_ni_reparto(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');   // ya pasó el 20 del mes siguiente

        // Efectivo: 1.190.000 ÷ 1,19 = 1.000.000 → 5% = 50.000
        $efectivo = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);
        // Tarjeta: 2.380.000 − 5,5% (130.900) = 2.249.100 ÷ 1,19 = 1.890.000 → 94.500
        $tarjeta  = $this->orden(self::LAURA, self::FERIA, 2_380_000, [['tarjeta', 2_380_000]]);
        // Mixto: 595.000 efectivo + 595.000 Addi → costo 32.725
        //        1.157.275 ÷ 1,19 = 972.500 → 48.625
        $mixto    = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 595_000], ['addi', 595_000]]);
        // Restauración: 400.000 × 5% = 20.000 (sin quitar IVA)
        $restaura = $this->orden(self::LAURA, self::FERIA, 400_000, [['efectivo', 400_000]], restauracion: true);
        // Por WhatsApp: sigue siendo de la feria → 595.000 ÷ 1,19 × 5% = 25.000
        $whatsapp = $this->orden(self::LAURA, self::FERIA, 595_000, [['transferencia', 595_000]], canal: 'whatsapp');
        // Le pagaron menos de la mitad: calcula, pero no está lista.
        // 1.000.000 ÷ 1,19 × 5% = 42.016,8 → 42.017
        $debe     = $this->orden(self::LAURA, self::FERIA, 1_000_000, [['efectivo', 400_000]]);

        [$filas, $o] = $this->resumen();

        $this->assertEquals(50_000, $o[$efectivo]['monto_comision']);
        $this->assertEquals(94_500, $o[$tarjeta]['monto_comision']);
        $this->assertEquals(48_625, $o[$mixto]['monto_comision']);
        $this->assertEquals(20_000, $o[$restaura]['monto_comision']);
        $this->assertEquals(25_000, $o[$whatsapp]['monto_comision']);
        $this->assertEquals(42_017, $o[$debe]['monto_comision']);

        foreach ([$efectivo, $tarjeta, $mixto, $whatsapp] as $id) {
            $this->assertSame('sin_meta_5', $o[$id]['forma_pago'], 'sin meta: 5% directo, no pool');
            $this->assertSame('lista', $o[$id]['estado']);
            $this->assertSame(self::FERIA, $o[$id]['tienda_fila']);
        }
        $this->assertSame('restauracion_5', $o[$restaura]['forma_pago'], 'sola: la restauración es entera suya');
        $this->assertSame('pendiente', $o[$debe]['estado'], 'sin la mitad pagada no se paga');

        // Una sola tarjeta, solo de ella, con todo junto.
        $suya = collect($filas)->where('vendedor_id', self::LAURA)->values();
        $this->assertCount(1, $suya);
        $this->assertEquals(50_000 + 94_500 + 48_625 + 20_000 + 25_000 + 42_017, $suya[0]['comision_total']);
    }

    public function test_antes_del_20_del_mes_siguiente_no_esta_lista(): void
    {
        Carbon::setTestNow('2026-11-19 10:00:00');
        $id = $this->orden(self::LAURA, self::FERIA, 1_190_000, [['efectivo', 1_190_000]]);

        [, $o] = $this->resumen();

        $this->assertSame('pendiente', $o[$id]['estado']);
        $this->assertSame('2026-11-20', DB::table('comisiones')->where('orden_id', $id)->value('fecha_disponible'));
    }

    public function test_lo_que_vende_no_toca_la_meta_ni_el_pool_de_otra_tienda(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');

        // Norte: 50 millones contra 40 de meta → pool = 10.000.000 ÷ 1,19 × 5% = 420.168
        $paola = $this->orden(self::PAOLA, self::NORTE, 50_000_000, [['efectivo', 50_000_000]]);
        $this->orden(self::LAURA, self::FERIA, 11_900_000, [['efectivo', 11_900_000]]);

        [, $o] = $this->resumen();

        $this->assertSame('pool', $o[$paola]['forma_pago']);
        $this->assertEquals(420_168, $o[$paola]['monto_comision'], 'la feria no le suma al pool de Norte');
    }
}
