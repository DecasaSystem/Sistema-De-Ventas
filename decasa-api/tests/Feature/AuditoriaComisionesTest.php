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
 * Lo que salió de la auditoría de comisiones del 2 de octubre de 2026.
 *
 * Cada test es un hueco por donde salía plata de más o se mostraba una cifra
 * que no era: se fija aquí para que no vuelva. El escenario es el mismo de
 * ComoSeCalculaCadaComisionTest (Norte con meta $40M y tres en el equipo).
 */
class AuditoriaComisionesTest extends TestCase
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
            $t->string('canal')->nullable(); $t->unsignedBigInteger('tienda_vendedor_id')->nullable();
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


    /** Le paga a cada quien lo que tenga listo, como el botón de Listas. */
    private function pagarTodoLoListo(): void
    {
        $this->llamar('asegurarPartesDePool', self::MES);
        [$metas, $totTienda, $totVendedor] = $this->llamar('cargarTotales');
        $pools = $this->llamar('cargarPoolsTrimestrales', $metas, $totTienda, false);
        $quien = \App\Models\Usuario::find(self::PAOLA);
        foreach (Comision::with('orden.pagos', 'tienda')->where('estado', '!=', 'pagada')->get() as $c) {
            $f = $this->llamar('enriquecer', $c, $metas, $totTienda, $totVendedor, $pools, \Carbon\Carbon::parse('2026-09-25'));
            if ($f['estado_calculado'] === 'lista') {
                $this->llamar('registrarPago', $c, $f, $quien);
            }
        }
    }

    /** [nombre => total pagado] */
    private function pagadoPorPersona(): array
    {
        $n = DB::table('usuarios')->pluck('nombre', 'id')->all();
        $out = [];
        foreach (Comision::where('estado', 'pagada')->get() as $c) {
            $out[$n[$c->vendedor_id]] = ($out[$n[$c->vendedor_id]] ?? 0) + (float) $c->monto_comision;
        }
        return $out;
    }

    // ─────────── C1: cancelar después de pagar ───────────

    public function test_cancelar_una_orden_ya_pagada_no_le_vuelve_a_pagar_su_parte(): void
    {
        // Norte vende 70M (meta 40M): pool 1.260.504, 420.168 a cada una.
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->pagarTodoLoListo();
        $this->assertEqualsWithDelta(420_168, $this->pagadoPorPersona()['Marta'], 2);

        // Se cancela la de Marta: OrdenController borra solo lo no pagado.
        DB::table('ordenes')->where('id', $deMarta->id)->update(['estado' => 'cancelado']);
        Comision::where('orden_id', $deMarta->id)->where('estado', '!=', 'pagada')->delete();

        // El pool baja a 840.336 (280.112 c/u) y a las tres ya se les pagó
        // más que eso: no hay nada nuevo que pagarle a nadie.
        $listo = $this->loQueEstaListo();
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(0, $listo[$quien] ?? 0, 1, "{$quien} no cobra otra vez");
        }

        $this->pagarTodoLoListo();
        $this->assertEqualsWithDelta(420_168, $this->pagadoPorPersona()['Marta'], 2, 'Marta queda con lo que ya cobró');
    }

    // ─────────── C2: renglón de "no vendió" repetido ───────────

    public function test_la_base_no_deja_abrir_dos_renglones_de_no_vendio(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $this->llamar('asegurarPartesDePool', self::MES);
        $this->llamar('asegurarPartesDePool', self::MES);

        $this->assertSame(1, Comision::where('vendedor_id', self::NN)->where('origen', 'parte_pool')->count());

        $nn = Comision::where('vendedor_id', self::NN)->where('origen', 'parte_pool')->first();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Comision::create(array_merge(
            $nn->only(['vendedor_id', 'tienda_id', 'origen', 'clave_unica', 'mes_venta', 'valor_orden', 'fecha_venta', 'fecha_disponible', 'estado']),
            ['orden_id' => null]
        ));
    }

    public function test_un_renglon_repetido_de_antes_se_paga_una_sola_vez(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $this->llamar('asegurarPartesDePool', self::MES);

        // Uno repetido de antes de la llave única (sin clave).
        $nn = Comision::where('vendedor_id', self::NN)->where('origen', 'parte_pool')->first();
        Comision::create(array_merge(
            $nn->only(['vendedor_id', 'tienda_id', 'origen', 'mes_venta', 'valor_orden', 'fecha_venta', 'fecha_disponible', 'estado']),
            ['orden_id' => null, 'clave_unica' => null]
        ));

        // Pool 840.336 ÷ 3 = 280.112, una vez.
        $this->assertEqualsWithDelta(280_112, $this->loQueEstaListo()['NN'], 2);
        $this->assertSame(1, Comision::where('vendedor_id', self::NN)->where('origen', 'parte_pool')->count(), 'el repetido se limpia');
    }

    public function test_si_el_pool_crece_despues_de_pagar_se_abre_otro_renglon_por_la_diferencia(): void
    {
        $this->orden(self::PAOLA, self::NORTE, 50_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->clienteAbono($deMarta, 1_000_000);
        $this->pagarTodoLoListo();

        $this->clienteAbono($deMarta, 5_000_000);

        // NN ya cobró 140.056; le falta otro tanto. El renglón pagado soltó la
        // clave, así que se le puede abrir el nuevo.
        $this->assertEqualsWithDelta(140_056, $this->loQueEstaListo()['NN'] ?? 0, 3);
    }

    // ─────────── A3: lo pagado se muestra como se pagó ───────────

    public function test_una_comision_pagada_se_muestra_con_lo_que_se_pago(): void
    {
        $o = $this->orden(self::MANUELA, self::VIRTUAL, 1_190_000);
        Comision::where('orden_id', $o->id)->update(['estado' => 'pagada', 'monto_comision' => 12_345]);

        $this->assertEqualsWithDelta(12_345, $this->loQueCobraCadaUno()['Manuela'], 0.01);
    }

    // ─────────── M4: el porcentaje pagado es de la orden entera ───────────

    public function test_en_una_venta_compartida_el_porcentaje_pagado_no_pasa_de_100(): void
    {
        $o = $this->orden(self::PAOLA, self::NORTE, 10_000_000, covendedor: self::GLADYS);

        [$metas, $totTienda, $totVendedor] = $this->llamar('cargarTotales');
        $pools = $this->llamar('cargarPoolsTrimestrales', $metas, $totTienda, false);
        $c = Comision::with('orden.pagos', 'tienda')->where('orden_id', $o->id)->first();
        $f = $this->llamar('enriquecer', $c, $metas, $totTienda, $totVendedor, $pools, \Carbon\Carbon::parse('2026-09-25'));

        $this->assertEquals(100, $f['pct_pagado']);
    }

    // ─────────── M9: el reparto no pierde pesos ───────────

    public function test_el_reparto_suma_exacto_lo_que_se_reparte(): void
    {
        $partes = ComisionController::repartirSinPerder(100, [1 => 1, 2 => 1, 3 => 1]);
        $this->assertSame(100.0, array_sum($partes));
        $this->assertEqualsCanonicalizing([34.0, 33.0, 33.0], array_values($partes));
    }

    // ─────────── Periodicidad guardada en la tienda ───────────

    public function test_la_tienda_trimestral_sigue_siendolo_aunque_se_renombre(): void
    {
        Schema::table('tiendas', fn ($t) => $t->string('comision_periodicidad')->default('mensual'));
        DB::table('tiendas')->where('id', self::EDEN)->update(['comision_periodicidad' => 'trimestral', 'nombre' => 'Otro nombre']);
        ComisionController::olvidarPeriodicidades();

        $this->assertTrue(ComisionController::esTiendaTrimestral(self::EDEN));
        $this->assertFalse(ComisionController::esTiendaTrimestral(self::NORTE));

        // Y la fecha de cobro sigue la del trimestre: venta de agosto, 20 de octubre.
        $o = $this->orden(self::GLADYS, self::EDEN, 1_000_000);
        $this->assertSame('2026-10-20', (string) Comision::where('orden_id', $o->id)->value('fecha_disponible'));

        ComisionController::olvidarPeriodicidades();
    }

    // ─────────── A4: la venta nace cuando se acepta ───────────

    public function test_cotizacion_de_julio_aceptada_en_agosto_es_venta_de_agosto_y_se_cobra_el_20_de_septiembre(): void
    {
        Schema::table('ordenes', function ($t) {
            $t->timestamp('confirmada_en')->nullable();
            $t->timestamp('cotizada_en')->nullable();
        });

        $orden = Orden::create([
            'tienda_id' => self::NORTE, 'vendedor_id' => self::PAOLA, 'estado' => 'cotizacion', 'valor_total' => 5_000_000,
        ]);
        DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => '2026-07-20 15:00:00']);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'es_restauracion' => false, 'precio_unitario' => 5_000_000]);

        // El cliente la acepta el 10 de agosto.
        $this->travelTo(\Carbon\Carbon::parse('2026-08-10 16:00:00', 'America/Bogota'));
        $orden = $orden->fresh();
        $orden->nacerComoVentaHoy();
        DB::table('ordenes')->where('id', $orden->id)->update(['estado' => 'pendiente_anticipo']);
        ComisionController::crearParaOrden($orden->fresh());

        $c = Comision::where('orden_id', $orden->id)->first();
        $this->assertSame('2026-08', $c->mes_venta);
        $this->assertSame('2026-09-20', (string) $c->fecha_disponible);
        $this->assertStringStartsWith('2026-07-20', (string) $orden->fresh()->cotizada_en, 'la fecha de la cotización no se pierde');
    }

    // ─────────── C3: independientes ───────────

    private function sedeDeIndependientes(): void
    {
        Schema::table('tiendas', fn ($t) => $t->boolean('es_independientes')->default(false));
        DB::table('tiendas')->insert(['id' => 9, 'nombre' => 'Independientes', 'activa' => true, 'es_independientes' => true]);
    }

    public function test_al_independiente_se_le_registra_lo_que_se_veia_al_pagar(): void
    {
        $this->sedeDeIndependientes();

        // Henry: una venta de 11.900.000 compartida con Norte y una
        // restauración de 1.000.000.
        $this->orden(self::HENRY, self::NORTE, 11_900_000, abonaA: self::NORTE);
        $this->orden(self::HENRY, self::NORTE, 1_000_000, restauracion: true);

        $antes = collect(\App\Services\ComisionIndependientes::delMes(self::MES)['independientes'])->firstWhere('vendedor_id', self::HENRY);
        // 11.900.000 ÷ 1,19 × 5% = 500.000 (sobre la venta entera) + bolsón 50.000.
        $this->assertEqualsWithDelta(550_000, $antes['comision_por_pagar'], 1);

        $this->llamar('pagarIndependiente', self::HENRY, self::MES, \App\Models\Usuario::find(self::PAOLA));

        $this->assertEqualsWithDelta(550_000, (float) Comision::where('vendedor_id', self::HENRY)->where('estado', 'pagada')->sum('monto_comision'), 1,
            'lo registrado es lo que se veía');

        $despues = collect(\App\Services\ComisionIndependientes::delMes(self::MES)['independientes'])->firstWhere('vendedor_id', self::HENRY);
        $this->assertEqualsWithDelta(0, $despues['comision_por_pagar'], 0.01, 'no se le vuelve a ofrecer');
        $this->assertEqualsWithDelta(550_000, $despues['comision_pagada'], 1);

        // Pagar otra vez no hace nada.
        $this->llamar('pagarIndependiente', self::HENRY, self::MES, \App\Models\Usuario::find(self::PAOLA));
        $this->assertEqualsWithDelta(550_000, (float) Comision::where('vendedor_id', self::HENRY)->where('estado', 'pagada')->sum('monto_comision'), 1);
    }
}
