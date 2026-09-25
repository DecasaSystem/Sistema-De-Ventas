<?php

namespace Tests\Feature;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Services\ComisionIndependientes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cada caso de comisión, con su cuenta hecha.
 *
 * No prueba un arreglo: fija CÓMO se paga cada situación. Son las preguntas
 * que uno se hace mirando la pantalla —¿por qué este cobra más que aquel?,
 * ¿qué pasa si el cliente paga con tarjeta?— contestadas con números que salen
 * del código, no de la memoria de nadie.
 *
 * Si mañana cambia una regla, aquí se ve cuál y cuánto.
 *
 * El escenario: dos tiendas con meta (Norte con 3, El Edén con 2), una sin
 * meta (Tienda Virtual) y dos que trabajan por su cuenta.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ComoSeCalculaCadaComisionTest extends TestCase
{
    private const MES = '2026-08';

    /** Ids: tiendas */
    private const NORTE = 1, EDEN = 2, VIRTUAL = 3;

    /** Ids: gente */
    private const PAOLA = 1, MARTA = 2, NN = 3,       // Norte, meta
                  GLADYS = 4, SEBASTIAN = 5,          // El Edén, meta
                  MANUELA = 6,                        // Tienda Virtual, sin meta
                  HENRY = 9;                          // por su cuenta

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
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->boolean('sin_descontar_iva')->default(false);
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

        // Ninguna tienda de este escenario es trimestral, pero el cálculo lee
        // la tabla siempre para saber si hay déficit arrastrado.
        Schema::create('tienda_trimestres', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('trimestre', 7);
            $t->decimal('deficit_inicial', 15, 2)->default(0);
            $t->decimal('pool_bruto', 15, 2)->default(0);
            $t->decimal('pool_pagado', 15, 2)->default(0);
            $t->decimal('deficit_final', 15, 2)->default(0);
            $t->timestamps();
        });

        // Norte y El Edén reparten entre su equipo; Tienda Virtual no: ahí
        // cada uno cobra lo suyo.
        DB::table('tiendas')->insert([
            ['id' => self::NORTE,   'nombre' => 'Decasa Norte',       'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::EDEN,    'nombre' => 'Decasa Vía El Edén', 'activa' => true, 'comisiones_compartidas' => true],
            ['id' => self::VIRTUAL, 'nombre' => 'Tienda Virtual',     'activa' => true, 'comisiones_compartidas' => false],
        ]);

        // Norte y El Edén tienen meta; Tienda Virtual no.
        DB::table('metas_tienda')->insert([
            ['tienda_id' => self::NORTE, 'mes' => self::MES, 'meta' => 40_000_000,
             'divisor_asesores' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['tienda_id' => self::EDEN,  'mes' => self::MES, 'meta' => 20_000_000,
             'divisor_asesores' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([[self::PAOLA, 'Paola', self::NORTE], [self::MARTA, 'Marta', self::NORTE],
                  [self::NN, 'NN', self::NORTE], [self::GLADYS, 'Gladys', self::EDEN],
                  [self::SEBASTIAN, 'Sebastián', self::EDEN],
                  [self::MANUELA, 'Manuela', self::VIRTUAL]] as [$id, $nombre, $tienda]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor',
                'tienda_default_id' => $tienda, 'created_at' => now(),
            ]);
        }
        DB::table('usuarios')->insert([
            'id' => self::HENRY, 'nombre' => 'Henry', 'rol' => 'vendedor',
            'independiente' => true, 'tienda_default_id' => null, 'created_at' => now(),
        ]);

        foreach ([[self::NORTE, self::PAOLA], [self::NORTE, self::MARTA], [self::NORTE, self::NN],
                  [self::EDEN, self::GLADYS], [self::EDEN, self::SEBASTIAN]] as [$tienda, $quien]) {
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => $tienda, 'mes' => self::MES, 'vendedor_id' => $quien,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();

        $this->prestarleASqliteLoQueEsDeMysql();
    }

    /**
     * Una orden ya cobrada, con sus comisiones creadas.
     *
     * @param  string $comoPago  'efectivo' | 'tarjeta' | 'addi' | 'mitad' (mitad y mitad)
     */
    private function orden(
        int $vendedor, ?int $tienda, float $valor,
        bool $restauracion = false, string $comoPago = 'efectivo',
        ?int $covendedor = null, ?int $abonaA = null,
    ): Orden {
        $orden = Orden::create([
            'tienda_id' => $tienda, 'vendedor_id' => $vendedor, 'estado' => 'entregado',
            'valor_total' => $valor,
            'es_compartida' => $covendedor !== null, 'covendedor_id' => $covendedor,
            'tienda_abonada_id' => $abonaA,
        ]);

        DB::table('ordenes')->where('id', $orden->id)
            ->update(['created_at' => self::MES . '-15 15:00:00']);

        DB::table('orden_items')->insert([
            'orden_id' => $orden->id, 'es_restauracion' => $restauracion, 'precio_unitario' => $valor,
        ]);

        // El cliente paga todo, que es lo que habilita el cobro.
        $pagos = match ($comoPago) {
            'tarjeta'  => [['tarjeta', $valor]],
            'addi'     => [['addi', $valor]],
            'mitad'    => [['tarjeta', $valor / 2], ['efectivo', $valor / 2]],
            default    => [['efectivo', $valor]],
        };
        foreach ($pagos as [$metodo, $monto]) {
            DB::table('pagos')->insert([
                'orden_id' => $orden->id, 'monto' => $monto,
                'metodo' => $metodo, 'created_at' => now(),
            ]);
        }

        ComisionController::crearParaOrden($orden->fresh());
        ComisionController::sincronizarValorOrden($orden->fresh());

        return $orden->fresh();
    }

    /** Lo que cobra cada quien ese mes, ya calculado. [nombre => monto] */
    private function loQueCobraCadaUno(): array
    {
        $ctrl = app(ComisionController::class);

        $cargar = new \ReflectionMethod($ctrl, 'cargarTotales');
        $cargar->setAccessible(true);
        [$metas, $totTienda, $totVendedor] = $cargar->invoke($ctrl);

        $pools = new \ReflectionMethod($ctrl, 'cargarPoolsTrimestrales');
        $pools->setAccessible(true);
        $poolsTrim = $pools->invoke($ctrl, $metas, $totTienda, false);

        $enriquecer = new \ReflectionMethod($ctrl, 'enriquecer');
        $enriquecer->setAccessible(true);

        $nombres = DB::table('usuarios')->pluck('nombre', 'id')->all();
        $out = [];

        foreach (Comision::with('orden.pagos', 'tienda')->get() as $c) {
            $f = $enriquecer->invoke($ctrl, $c, $metas, $totTienda, $totVendedor,
                $poolsTrim, \Carbon\Carbon::parse('2026-09-25'));

            $quien = $nombres[$c->vendedor_id];
            $out[$quien] = ($out[$quien] ?? 0) + (float) $f['monto_comision'];
        }

        return $out;
    }

    // ─────────── Tienda CON meta ───────────

    public function test_venta_en_tienda_con_meta_va_por_el_pool(): void
    {
        // Norte vende $60.000.000 contra una meta de $40.000.000, todo Paola.
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);

        // Pool = (60.000.000 − 40.000.000) ÷ 1,19 × 5% = $840.336
        // Partido entre los 3 del equipo = $280.112 a cada uno.
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(280_112, $cobra['Paola'], 2);
        $this->assertArrayNotHasKey('Marta', $cobra, 'sin renglón propio todavía');
    }

    public function test_con_tarjeta_el_pool_baja_porque_la_franquicia_se_lleva_su_parte(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 60_000_000, comoPago: 'tarjeta');

        // Base = 60.000.000 − 5,5% = 56.700.000
        // Pool = (56.700.000 − 40.000.000) ÷ 1,19 × 5% = $701.680
        // ÷ 3 = $233.893
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(233_893, $cobra['Paola'], 2);
    }

    public function test_addi_cuenta_igual_que_la_tarjeta(): void
    {
        // Addi también se queda el 5,5%: la misma cuenta que con tarjeta.
        $this->orden(self::PAOLA, self::NORTE, 60_000_000, comoPago: 'addi');

        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(233_893, $cobra['Paola'], 2);
    }

    public function test_restauracion_en_tienda_con_meta_se_parte_entre_los_companeros(): void
    {
        // El Edén: Gladys y Sebastián. Restauración de $1.000.000.
        $this->orden(self::GLADYS, self::EDEN, 1_000_000, restauracion: true);

        // 5% de 1.000.000 = 50.000, partido en 2 = 25.000 cada uno.
        // (Es el 2,5% del valor para cada uno.)
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEquals(25_000, $cobra['Gladys']);
        $this->assertEquals(25_000, $cobra['Sebastián']);
    }

    public function test_a_la_restauracion_no_se_le_quita_el_iva_pero_si_el_datafono(): void
    {
        $this->orden(self::GLADYS, self::EDEN, 1_000_000, restauracion: true, comoPago: 'tarjeta');

        // Base = 1.000.000 − 5,5% = 945.000. El IVA no se toca.
        // 5% de 945.000 = 47.250, ÷ 2 = 23.625 cada uno.
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEquals(23_625, $cobra['Gladys']);
        $this->assertEquals(23_625, $cobra['Sebastián']);
    }

    public function test_una_restauracion_no_le_suma_a_la_meta_de_la_tienda(): void
    {
        // Justo por debajo de la meta, y una restauración grande encima.
        $this->orden(self::GLADYS, self::EDEN, 19_000_000);
        $this->orden(self::SEBASTIAN, self::EDEN, 5_000_000, restauracion: true);

        $cobra = $this->loQueCobraCadaUno();

        // La venta sola no alcanza los $20.000.000: no hay pool.
        // Lo único que se paga es el 5% de la restauración, partido en dos.
        $this->assertEquals(125_000, $cobra['Gladys']);     // 5.000.000 × 5% ÷ 2
        $this->assertEquals(125_000, $cobra['Sebastián']);
    }

    // ─────────── El pool se mira a nivel de tienda ───────────

    /** Llama un método privado del controlador. */
    private function llamar(string $metodo, ...$args)
    {
        $ctrl = app(ComisionController::class);
        $r = new \ReflectionMethod($ctrl, $metodo);
        $r->setAccessible(true);

        return $r->invoke($ctrl, ...$args);
    }

    /** Lo que ya se le puede pagar a cada quien (estado "lista"), con la pantalla abierta. [nombre => monto] */
    private function loQueEstaListo(): array
    {
        [$metas, $totTienda, $totVendedor] = $this->llamar('cargarTotales');
        // Lo que corre al abrir la pantalla: abre el renglón a quien le haga falta.
        $this->llamar('asegurarPartesDePool', self::MES);
        [$metas, $totTienda, $totVendedor] = $this->llamar('cargarTotales');
        $pools   = $this->llamar('cargarPoolsTrimestrales', $metas, $totTienda, false);
        $nombres = DB::table('usuarios')->pluck('nombre', 'id')->all();
        $out = [];

        foreach (Comision::with('orden.pagos', 'tienda')->get() as $c) {
            $f = $this->llamar('enriquecer', $c, $metas, $totTienda, $totVendedor, $pools, \Carbon\Carbon::parse('2026-09-25'));
            if ($f['estado_calculado'] !== 'lista') continue;
            $quien = $nombres[$c->vendedor_id];
            $out[$quien] = ($out[$quien] ?? 0) + (float) $f['monto_comision'];
        }

        return $out;
    }

    /** El cliente de esa orden solo ha abonado esto. */
    private function clienteAbono(Orden $orden, float $monto): void
    {
        DB::table('pagos')->where('orden_id', $orden->id)->delete();
        DB::table('pagos')->insert(['orden_id' => $orden->id, 'monto' => $monto, 'metodo' => 'efectivo', 'created_at' => now()]);
    }

    /**
     * El caso de Norte en agosto: a Marta le falta que un cliente pague la
     * mitad, y eso frena a los TRES por igual, no solo a ella. NN no vendió
     * y cobra lo mismo que las otras dos.
     */
    public function test_una_venta_sin_la_mitad_pagada_frena_a_toda_la_tienda_por_igual(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 50_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->clienteAbono($deMarta, 1_000_000); // 10%: todavía no cuenta

        $listo = $this->loQueEstaListo();

        // Cuenta lo que tiene la mitad pagada: 50.000.000.
        // Pool = (50.000.000 − 40.000.000) ÷ 1,19 × 5% = $420.168, ÷ 3 = $140.056
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(140_056, $listo[$quien] ?? 0, 2, "{$quien} cobra igual que las demás");
        }
    }

    public function test_cuando_el_cliente_paga_la_mitad_los_tres_reciben_la_diferencia(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 50_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->clienteAbono($deMarta, 1_000_000);

        // Se les paga lo que había: $140.056 a cada una.
        $this->loQueEstaListo();
        foreach (Comision::with('orden.pagos', 'tienda')->get() as $c) {
            [$metas, $totTienda, $totVendedor] = $this->llamar('cargarTotales');
            $pools = $this->llamar('cargarPoolsTrimestrales', $metas, $totTienda, false);
            $f = $this->llamar('enriquecer', $c, $metas, $totTienda, $totVendedor, $pools, \Carbon\Carbon::parse('2026-09-25'));
            if ($f['estado_calculado'] === 'lista') {
                $c->update(['estado' => 'pagada', 'monto_comision' => $f['monto_comision']]);
            }
        }

        // Después el cliente de Marta paga la mitad: entra su venta.
        $this->clienteAbono($deMarta, 5_000_000);

        // Pool completo = (60.000.000 − 40.000.000) ÷ 1,19 × 5% = $840.336,
        // ÷ 3 = $280.112. Ya se pagaron $140.056: falta la otra mitad.
        $listo = $this->loQueEstaListo();
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(140_056, $listo[$quien] ?? 0, 3, "{$quien} recibe solo la diferencia");
        }
    }

    public function test_si_lo_que_tiene_la_mitad_pagada_no_llega_a_la_meta_no_hay_pool(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 30_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 20_000_000);
        $this->clienteAbono($deMarta, 1_000_000);

        // Se vendieron 50.000.000, pero con la mitad pagada solo 30.000.000:
        // no llega a los 40.000.000 de la meta.
        $this->assertSame([], $this->loQueEstaListo());
    }

    // ─────────── Tienda SIN meta ───────────

    public function test_venta_sin_meta_es_el_cinco_por_ciento_sin_iva_y_sin_dividir(): void
    {
        $this->orden(self::MANUELA, self::VIRTUAL, 10_000_000);

        // 10.000.000 ÷ 1,19 × 5% = $420.168. Entera para ella.
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(420_168, $cobra['Manuela'], 2);
    }

    public function test_venta_sin_meta_con_tarjeta(): void
    {
        $this->orden(self::MANUELA, self::VIRTUAL, 10_000_000, comoPago: 'tarjeta');

        // (10.000.000 − 5,5%) ÷ 1,19 × 5% = 9.450.000 ÷ 1,19 × 5% = $397.059
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(397_059, $cobra['Manuela'], 2);
    }

    public function test_una_restauracion_sin_meta_no_se_parte_con_nadie(): void
    {
        // En Tienda Virtual hay más gente —Nicolás y Juan David—, pero esa
        // tienda no tiene meta: ahí no hay pool ni se parten las
        // restauraciones. Cada uno cobra lo suyo.
        DB::table('usuarios')->insert([
            ['id' => 7, 'nombre' => 'Nicolás',    'rol' => 'supervisor',
             'tienda_default_id' => self::VIRTUAL, 'created_at' => now()],
            ['id' => 8, 'nombre' => 'Juan David', 'rol' => 'supervisor',
             'tienda_default_id' => self::VIRTUAL, 'created_at' => now()],
        ]);
        foreach ([self::MANUELA, 7, 8] as $quien) {
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => self::VIRTUAL, 'mes' => self::MES, 'vendedor_id' => $quien,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Models\TiendaAsesor::olvidarCache();

        $this->orden(self::MANUELA, self::VIRTUAL, 1_000_000, restauracion: true);

        $cobra = $this->loQueCobraCadaUno();

        // El 5% completo para ella, aunque estén los tres registrados.
        $this->assertEquals(50_000, $cobra['Manuela']);
        $this->assertArrayNotHasKey('Nicolás', $cobra);
        $this->assertArrayNotHasKey('Juan David', $cobra);
    }

    public function test_pago_mitad_efectivo_mitad_tarjeta_solo_descuenta_lo_de_la_tarjeta(): void
    {
        $this->orden(self::MANUELA, self::VIRTUAL, 10_000_000, comoPago: 'mitad');

        // Solo los 5.000.000 de tarjeta pagan el 5,5%: −275.000.
        // (10.000.000 − 275.000) ÷ 1,19 × 5% = $408.613
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(408_613, $cobra['Manuela'], 2);
    }

    // ─────────── Venta compartida entre dos vendedores ───────────

    public function test_dos_vendedores_de_tiendas_distintas_parten_la_venta_por_la_mitad(): void
    {
        // Gladys (El Edén) vende con Manuela (Tienda Virtual, sin meta).
        $this->orden(self::GLADYS, self::EDEN, 40_000_000, covendedor: self::MANUELA);

        $bases = Comision::pluck('valor_orden', 'vendedor_id')->map(fn ($v) => (float) $v);

        // A cada uno se le apunta la mitad: $20.000.000.
        $this->assertEquals(20_000_000.0, $bases[self::GLADYS]);
        $this->assertEquals(20_000_000.0, $bases[self::MANUELA]);

        $cobra = $this->loQueCobraCadaUno();

        // Manuela no tiene meta: 20.000.000 ÷ 1,19 × 5% = $840.336, suya entera.
        $this->assertEqualsWithDelta(840_336, $cobra['Manuela'], 2);

        // El Edén sí: esos 20.000.000 son justo la meta, así que pool = $0.
        $this->assertEquals(0, $cobra['Gladys'] ?? 0);
    }

    public function test_la_mitad_de_una_venta_compartida_es_lo_que_empuja_la_meta(): void
    {
        $this->orden(self::GLADYS, self::EDEN, 60_000_000, covendedor: self::MANUELA);

        // A la meta de El Edén le entran 30.000.000, no 60.000.000.
        // Pool = (30.000.000 − 20.000.000) ÷ 1,19 × 5% = $420.168, ÷ 2 = $210.084
        $cobra = $this->loQueCobraCadaUno();

        $this->assertEqualsWithDelta(210_084, $cobra['Gladys'], 2);
    }

    // ─────────── Independientes ───────────

    public function test_venta_de_un_independiente(): void
    {
        $this->orden(self::HENRY, null, 10_000_000);

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // 10.000.000 ÷ 1,19 × 5% = $420.168. La venta es de quien la hizo.
        $this->assertEqualsWithDelta(420_168, $suyo['comision'], 2);
        $this->assertEquals(0, $suyo['comision_restauraciones']);
    }

    public function test_restauracion_de_un_independiente_no_le_quita_el_iva(): void
    {
        $this->orden(self::HENRY, null, 10_000_000, restauracion: true);

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // 10.000.000 × 5% = $500.000, sin dividir por 1,19.
        $this->assertEquals(500_000, $suyo['comision']);
        $this->assertEquals(500_000, $suyo['comision_restauraciones']);
    }

    public function test_independiente_comparte_una_venta_con_una_tienda(): void
    {
        // Henry vende $10.000.000 con un contacto de El Edén.
        $this->orden(self::HENRY, null, 10_000_000, abonaA: self::EDEN);

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo    = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);
        $almacen = collect($r['almacenes'])->firstWhere('tienda_id', self::EDEN);

        // Henry cobra sobre el valor ENTERO: 10.000.000 ÷ 1,19 × 5% = $420.168.
        $this->assertEqualsWithDelta(420_168, $suyo['comision'], 2);

        // Y el almacén cobra su propio 5%, también sobre el valor entero.
        $this->assertEqualsWithDelta(420_168, $almacen['comision'], 2);

        // Pero a la META de El Edén solo le entra la mitad.
        $this->assertEquals(5_000_000, $almacen['suma_a_meta']);

        // Ese 5% del almacén se parte entre los dos que trabajan ahí. La base
        // de cada uno es la mitad del valor, y de ahí sale su 5%.
        $bases = Comision::where('origen', ComisionController::ORIGEN_ABONO)
            ->pluck('valor_orden', 'vendedor_id')->map(fn ($v) => (float) $v);

        $this->assertEquals(5_000_000.0, $bases[self::GLADYS]);
        $this->assertEquals(5_000_000.0, $bases[self::SEBASTIAN]);

        $cobra = $this->loQueCobraCadaUno();
        // 5.000.000 ÷ 1,19 × 5% = $210.084 para cada uno.
        $this->assertEqualsWithDelta(210_084, $cobra['Gladys'], 2);
        $this->assertEqualsWithDelta(210_084, $cobra['Sebastián'], 2);
    }

    public function test_independiente_comparte_una_restauracion_con_una_tienda(): void
    {
        $this->orden(self::HENRY, null, 10_000_000, restauracion: true, abonaA: self::EDEN);

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo    = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);
        $almacen = collect($r['almacenes'])->firstWhere('tienda_id', self::EDEN);

        // Henry: 10.000.000 × 5% = $500.000, sin IVA de por medio.
        $this->assertEquals(500_000, $suyo['comision']);

        // El almacén cobra igual el 5% del valor entero: $500.000.
        $this->assertEquals(500_000, $almacen['comision']);

        // Pero una restauración NO le suma nada a la meta.
        $this->assertEquals(0, $almacen['suma_a_meta']);

        // Y ese 5% se parte entre los dos de la tienda, sin quitar IVA:
        // base 5.000.000 × 5% = $250.000 cada uno.
        $cobra = $this->loQueCobraCadaUno();
        $this->assertEquals(250_000, $cobra['Gladys']);
        $this->assertEquals(250_000, $cobra['Sebastián']);
    }

    public function test_al_independiente_tambien_se_le_descuenta_el_datafono(): void
    {
        $this->orden(self::HENRY, null, 10_000_000, comoPago: 'tarjeta');

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // (10.000.000 − 5,5%) ÷ 1,19 × 5% = 9.450.000 ÷ 1,19 × 5% = $397.059,
        // exactamente lo mismo que cobraría alguien de una tienda sin meta.
        $this->assertEqualsWithDelta(397_059, $suyo['comision'], 2);
    }

    public function test_a_la_restauracion_de_un_independiente_tambien(): void
    {
        $this->orden(self::HENRY, null, 10_000_000, restauracion: true, comoPago: 'tarjeta');

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // 9.450.000 × 5% = $472.500. El datáfono sí se quita; el IVA no.
        $this->assertEquals(472_500, $suyo['comision']);
    }

    public function test_pago_mixto_de_un_independiente(): void
    {
        $this->orden(self::HENRY, null, 10_000_000, comoPago: 'mitad');

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // Solo los 5.000.000 de tarjeta pagan el 5,5%: −275.000.
        // (10.000.000 − 275.000) ÷ 1,19 × 5% = $408.613
        $this->assertEqualsWithDelta(408_613, $suyo['comision'], 2);
    }

    public function test_el_almacen_que_ayudo_tambien_comisiona_sobre_lo_que_entro(): void
    {
        $this->orden(self::HENRY, null, 10_000_000, comoPago: 'tarjeta', abonaA: self::EDEN);

        $r = ComisionIndependientes::delMes(self::MES);
        $almacen = collect($r['almacenes'])->firstWhere('tienda_id', self::EDEN);

        // El almacén cobra sobre la misma base neta: $397.059.
        $this->assertEqualsWithDelta(397_059, $almacen['comision'], 2);

        // Y a su meta le entra la mitad de lo neto: 9.450.000 ÷ 2.
        $this->assertEquals(4_725_000, $almacen['suma_a_meta']);
    }

    // ─────────── FV2 sin restar el IVA ───────────

    /** Marca la orden como FV2 especial, como la deja el switch al crearla. */
    private function fv2SinIva(Orden $orden, int $numero = 9): Orden
    {
        DB::table('ordenes')->where('id', $orden->id)->update([
            'serie' => 'FV2', 'serie_numero' => $numero, 'sin_descontar_iva' => true,
        ]);

        return $orden->fresh();
    }

    public function test_fv2_de_henry_sin_restar_el_iva(): void
    {
        $this->fv2SinIva($this->orden(self::HENRY, null, 10_000_000));

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // 10.000.000 × 5% = $500.000, en vez de los $420.168 de siempre.
        $this->assertEquals(500_000, $suyo['comision']);
        $this->assertTrue($r['ordenes'][0]['sin_descontar_iva']);
        $this->assertEquals(500_000, $r['ordenes'][0]['paga']);
    }

    public function test_solo_la_fv2_marcada_no_resta_el_iva(): void
    {
        $this->fv2SinIva($this->orden(self::HENRY, null, 10_000_000));
        $this->orden(self::HENRY, null, 10_000_000); // otra venta, normal

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // $500.000 de la especial + $420.168 de la normal.
        $this->assertEqualsWithDelta(920_168, $suyo['comision'], 2);
    }

    public function test_el_almacen_que_ayudo_en_la_fv2_cobra_con_la_regla_de_siempre(): void
    {
        $this->fv2SinIva($this->orden(self::HENRY, null, 10_000_000, abonaA: self::EDEN));

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo    = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);
        $almacen = collect($r['almacenes'])->firstWhere('tienda_id', self::EDEN);

        $this->assertEquals(500_000, $suyo['comision'], 'a Henry no se le resta');
        $this->assertEqualsWithDelta(420_168, $almacen['comision'], 2, 'al almacén sí');
    }

    public function test_fv2_sin_restar_iva_de_alguien_de_tienda_sin_meta(): void
    {
        $this->fv2SinIva($this->orden(self::MANUELA, self::VIRTUAL, 10_000_000));

        $cobra = $this->loQueCobraCadaUno();

        $this->assertEquals(500_000, $cobra['Manuela']);
    }

    // ─────────── Restauración que sube un almacén ───────────

    /** Flabio: el otro independiente, para ver que el bolsón es de los dos. */
    private function conFlabio(): int
    {
        DB::table('usuarios')->insert([
            'id' => 20, 'nombre' => 'Flabio', 'rol' => 'vendedor',
            'independiente' => true, 'tienda_default_id' => null, 'created_at' => now(),
        ]);

        return 20;
    }

    public function test_la_restauracion_de_un_almacen_le_suma_a_los_independientes(): void
    {
        $flabio = $this->conFlabio();

        // Gladys, de El Edén, sube una restauración de $1.000.000.
        $this->orden(self::GLADYS, self::EDEN, 1_000_000, restauracion: true);

        $r = ComisionIndependientes::delMes(self::MES);

        // Entra al bolsón entera, y cada independiente cobra su 5%: $50.000.
        $this->assertEquals(1_000_000, $r['bolson_restauraciones']);
        $this->assertEquals(1_000_000, $r['base_restauracion_almacenes']);
        foreach ([self::HENRY, $flabio] as $quien) {
            $suyo = collect($r['independientes'])->firstWhere('vendedor_id', $quien);
            $this->assertEquals(50_000, $suyo['comision']);
            $this->assertEquals(50_000, $suyo['comision_restauraciones']);
            $this->assertEquals(0, $suyo['vendio'], 'no es venta de ninguno de ellos');
        }

        $this->assertCount(1, $r['restauraciones_almacenes']);
        $this->assertSame('Decasa Vía El Edén', $r['restauraciones_almacenes'][0]['almacen']);

        // La tienda sigue cobrando lo suyo como siempre: 5% partido en dos.
        $cobra = $this->loQueCobraCadaUno();
        $this->assertEquals(25_000, $cobra['Gladys']);
        $this->assertEquals(25_000, $cobra['Sebastián']);
    }

    public function test_es_la_misma_cuenta_que_si_henry_la_compartiera_con_el_almacen(): void
    {
        // La sube El Edén...
        $this->orden(self::GLADYS, self::EDEN, 1_000_000, restauracion: true);
        $r = ComisionIndependientes::delMes(self::MES);
        $deAlmacen = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY)['comision'];
        $tienda    = $this->loQueCobraCadaUno();

        // ...o la sube Henry compartida con El Edén.
        Comision::query()->delete();
        DB::table('pagos')->delete(); DB::table('orden_items')->delete(); DB::table('ordenes')->delete();
        $this->orden(self::HENRY, null, 1_000_000, restauracion: true, abonaA: self::EDEN);
        $r = ComisionIndependientes::delMes(self::MES);
        $deHenry     = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY)['comision'];
        $tiendaHenry = $this->loQueCobraCadaUno();

        $this->assertEquals($deHenry, $deAlmacen);
        $this->assertEquals($tiendaHenry['Gladys'], $tienda['Gladys']);
        $this->assertEquals($tiendaHenry['Sebastián'], $tienda['Sebastián']);
    }

    public function test_al_bolson_le_entra_sin_lo_del_datafono(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 1_000_000, restauracion: true, comoPago: 'tarjeta');

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        // 945.000 × 5% = $47.250, igual que si la hubiera subido él.
        $this->assertEquals(47_250, $suyo['comision_restauraciones']);
    }

    public function test_una_venta_de_almacen_no_entra_al_bolson(): void
    {
        $this->orden(self::MANUELA, self::VIRTUAL, 10_000_000);

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        $this->assertEquals(0, $suyo['comision']);
        $this->assertEquals(0, $r['bolson_restauraciones']);
        $this->assertSame([], $r['restauraciones_almacenes']);
    }

    public function test_la_restauracion_de_almacen_se_cobra_cuando_el_cliente_pago_la_mitad(): void
    {
        $orden = $this->orden(self::GLADYS, self::EDEN, 1_000_000, restauracion: true);
        DB::table('pagos')->where('orden_id', $orden->id)->update(['monto' => 400_000]);

        $r = ComisionIndependientes::delMes(self::MES);
        $suyo = collect($r['independientes'])->firstWhere('vendedor_id', self::HENRY);

        $this->assertEquals(50_000, $suyo['comision']);
        $this->assertEquals(0, $suyo['comision_lista'], 'el cliente no ha pagado la mitad');
        $this->assertEquals(50_000, $suyo['comision_pendiente']);
    }

    // ─────────── Las cuentas de toda la tienda ───────────

    /**
     * Lo que se ve en "Toda la tienda": la suma del equipo sin repetir, con
     * el datáfono restado y solo lo que tiene la mitad pagada para la meta.
     */
    public function test_la_tienda_suma_lo_de_todos_con_el_valor_real(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 40_000_000);
        $this->orden(self::MARTA, self::NORTE, 20_000_000, comoPago: 'tarjeta');
        $sinMitad = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->clienteAbono($sinMitad, 2_000_000);
        ComisionController::sincronizarValorOrden($sinMitad->fresh());

        $this->llamar('cargarTotales');
        $this->llamar('asegurarPartesDePool', self::MES);
        [$metas, $totTienda, $totVendedor] = $this->llamar('cargarTotales');
        $pools = $this->llamar('cargarPoolsTrimestrales', $metas, $totTienda, false);

        $items = Comision::with('orden.pagos', 'tienda')->where('tienda_id', self::NORTE)->get()
            ->map(fn ($c) => $this->llamar('enriquecer', $c, $metas, $totTienda, $totVendedor, $pools, \Carbon\Carbon::parse('2026-09-25')));

        $t = $this->llamar('resumirTienda', $items);

        $this->assertEquals(70_000_000, $t['vendido']);
        $this->assertEquals(20_000_000, $t['tarjeta']);
        $this->assertEquals(1_100_000, $t['datafono']);          // 20.000.000 × 5,5%
        $this->assertEquals(68_900_000, $t['valor_real']);
        $this->assertEquals(3, $t['ordenes']);

        // A la meta solo va lo que tiene la mitad pagada, ya sin datáfono.
        $this->assertEquals(58_900_000, $t['pool']['ventas_cuentan']);
        $this->assertEquals(10_000_000, $t['pool']['sin_mitad']);
        $this->assertEquals(40_000_000, $t['pool']['meta']);
        // (58.900.000 − 40.000.000) ÷ 1,19 × 5%
        $this->assertEqualsWithDelta(794_118, $t['pool']['pool'], 1);
        $this->assertEquals(3, $t['pool']['integrantes']);
        // Entre los tres se llevan el pool entero, ni más ni menos.
        $this->assertEqualsWithDelta(794_118, $t['comision'], 3);

        // Orden por orden, para cuadrar contra el módulo de Órdenes.
        $this->assertCount(3, $t['detalle']);
        $noCuenta = collect($t['detalle'])->where('cuenta', false)->values();
        $this->assertCount(1, $noCuenta);
        $this->assertEquals($sinMitad->id, $noCuenta[0]['orden_id']);
        $this->assertEquals(20, $noCuenta[0]['pct_pagado']);
        $conTarjeta = collect($t['detalle'])->firstWhere('valor_orden', 20_000_000);
        $this->assertEquals(18_900_000, $conTarjeta['valor_real']);
        $this->assertEquals('venta_tienda', $conTarjeta['por_que']);
    }
}
