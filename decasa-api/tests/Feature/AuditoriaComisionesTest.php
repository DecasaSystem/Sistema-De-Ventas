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

    /**
     * Una orden que quedó cancelada sin pasar por el botón Cancelar (antes:
     * devolverle la plata de todo en Devoluciones) dejaba su comisión viva.
     * En una tienda sin meta (5 % directo) esa comisión salía lista para
     * pagar (revisión del 2026-11-17).
     */
    public function test_una_comision_sin_pagar_de_una_orden_cancelada_no_se_paga(): void
    {
        $orden = $this->orden(self::MANUELA, self::VIRTUAL, 11_900_000);
        $this->assertEqualsWithDelta(500_000, $this->loQueEstaListo()['Manuela'] ?? 0, 1, 'antes de cancelar: su 5 %');

        DB::table('ordenes')->where('id', $orden->id)->update(['estado' => 'cancelado']);

        $this->assertArrayNotHasKey('Manuela', $this->loQueEstaListo(), 'cancelada: no sale lista');
        $this->assertEqualsWithDelta(0, $this->loQueCobraCadaUno()['Manuela'] ?? 0, 1, 'y vale $0');
    }

    // ─────────── Lo cobrado de más por una venta cancelada (2026-11-19) ───────────

    /** Las tablas del libro de anticipos y lo que avisa la cancelación. */
    private function conLibroDeAnticipos(): void
    {
        Schema::create('comision_anticipos_config', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vendedor_id'); $t->char('desde_mes', 7);
            $t->decimal('monto', 15, 2)->default(0); $t->boolean('activo')->default(true);
            $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamps();
        });
        Schema::create('comision_anticipos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vendedor_id'); $t->string('tipo', 12); $t->char('mes', 7);
            $t->decimal('monto', 15, 2); $t->string('clave', 40)->nullable()->unique();
            $t->unsignedBigInteger('comision_id')->nullable(); $t->boolean('editado_a_mano')->default(false);
            $t->string('nota', 200)->nullable(); $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamps();
        });
        Schema::create('orden_mensajes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->text('mensaje'); $t->string('imagen_url')->nullable(); $t->json('mencionados')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo', 50);
            $t->string('titulo', 200); $t->string('mensaje', 500); $t->boolean('leida')->default(false);
            $t->boolean('urgente')->default(false); $t->json('datos')->nullable(); $t->timestamps();
        });
        \App\Services\AnticiposComision::olvidarEsquema();
    }

    /** Cancela como CancelacionDeOrden: foto antes, estado, se borra lo no pagado. */
    private function cancelar(Orden $orden): void
    {
        $quien = \App\Models\Usuario::find(self::PAOLA);
        DB::transaction(function () use ($orden, $quien) {
            \App\Services\BonificacionCobradaDeMas::alCancelar($orden, $quien);
            DB::table('ordenes')->where('id', $orden->id)->update(['estado' => 'cancelado']);
            Comision::where('orden_id', $orden->id)->where('estado', '!=', 'pagada')->delete();
        });
    }

    /** [nombre => lo anotado como cobrado de más] */
    private function cobradoDeMas(): array
    {
        $n = DB::table('usuarios')->pluck('nombre', 'id')->all();
        $out = [];
        foreach (DB::table('comision_anticipos')->where('clave', 'like', 'demas:%')->get() as $f) {
            $out[$n[$f->vendedor_id]] = ($out[$n[$f->vendedor_id]] ?? 0) + (float) $f->monto;
        }
        return $out;
    }

    public function test_venta_cancelada_despues_de_pagar_el_pool_se_le_descuenta_al_equipo(): void
    {
        $this->conLibroDeAnticipos();
        // Norte vende 70M (meta 40M): pool 1.260.504, 420.168 a cada una.
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->pagarTodoLoListo();

        $this->cancelar($deMarta);

        // Sin la de Marta el pool es 840.336 (280.112 c/u): cada una cobró 140.056 de más.
        $deMas = $this->cobradoDeMas();
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(140_056, $deMas[$quien] ?? 0, 2, "a {$quien} se le descuenta su parte");
        }
        $this->assertArrayNotHasKey('Gladys', $deMas, 'El Edén no tiene nada que ver');
        $this->assertSame(3, DB::table('notificaciones')->where('titulo', 'Se te descuenta una bonificación')->count());
        $this->assertSame(1, DB::table('orden_mensajes')->where('orden_id', $deMarta->id)->count());
    }

    public function test_si_sin_la_venta_no_llegaba_a_la_meta_se_descuenta_el_pool_entero(): void
    {
        $this->conLibroDeAnticipos();
        // 45M contra meta de 40M: pool 210.084 (70.028 c/u). Sin los 10M de Marta, 35M: no hay pool.
        $this->orden(self::PAOLA, self::NORTE, 35_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->pagarTodoLoListo();

        $this->cancelar($deMarta);

        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(70_028, $this->cobradoDeMas()[$quien] ?? 0, 2, $quien);
        }
    }

    public function test_en_el_5_directo_se_descuenta_lo_que_se_pago_por_esa_orden_en_el_proximo_pago(): void
    {
        $this->conLibroDeAnticipos();
        $cancelada = $this->orden(self::MANUELA, self::VIRTUAL, 11_900_000);
        $this->pagarTodoLoListo();

        $this->cancelar($cancelada);
        $this->assertEqualsWithDelta(500_000, $this->cobradoDeMas()['Manuela'] ?? 0, 1);

        // La próxima venta: se le paga y el libro descuenta lo que debe.
        $this->orden(self::MANUELA, self::VIRTUAL, 23_800_000);
        $this->pagarTodoLoListo();
        $descontado = (float) DB::table('comision_anticipos')->where('vendedor_id', self::MANUELA)
            ->where('tipo', 'descuento')->sum('monto');
        $this->assertEqualsWithDelta(500_000, $descontado, 1, 'se le descuenta del pago de su próxima venta');
        $this->assertEqualsWithDelta(0, \App\Services\AnticiposComision::debeHasta(self::MANUELA, self::MES), 1);
    }

    public function test_la_venta_cancelada_de_un_independiente_se_le_descuenta_a_el(): void
    {
        $this->conLibroDeAnticipos();
        $this->sedeDeIndependientes();
        // Como arriba: 500.000 de su venta + 50.000 del bolsón, ya pagados.
        $venta = $this->orden(self::HENRY, self::NORTE, 11_900_000, abonaA: self::NORTE);
        $restauracion = $this->orden(self::HENRY, self::NORTE, 1_000_000, restauracion: true);
        $this->llamar('pagarIndependiente', self::HENRY, self::MES, \App\Models\Usuario::find(self::PAOLA));

        // Se cancela la restauración: el bolsón queda vacío, cobró 50.000 de más.
        $this->cancelar($restauracion);
        $this->assertEqualsWithDelta(50_000, $this->cobradoDeMas()['Henry'] ?? 0, 1, 'su parte del bolsón');

        // Y la venta: los 500.000 de esa orden.
        $this->cancelar($venta);
        $this->assertEqualsWithDelta(550_000, $this->cobradoDeMas()['Henry'] ?? 0, 1, 'más su 5 % de la venta');
    }

    /** La orden baja de valor (se quitó un producto) como lo hacen las pantallas. */
    private function bajarValor(Orden $orden, float $nuevo): void
    {
        DB::transaction(function () use ($orden, $nuevo) {
            DB::table('ordenes')->where('id', $orden->id)->update(['valor_total' => $nuevo]);
            DB::table('orden_items')->where('orden_id', $orden->id)->update(['precio_unitario' => $nuevo]);
            ComisionController::sincronizarValorOrden($orden->fresh());
        });
    }

    public function test_si_la_venta_baja_despues_de_pagar_la_meta_cuenta_lo_que_vale_y_se_descuenta_la_diferencia(): void
    {
        $this->conLibroDeAnticipos();
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->pagarTodoLoListo();

        // Se le quitan $6 M de productos: Norte queda en 64M, pool 1.008.403 (336.134 c/u).
        $this->bajarValor($deMarta, 4_000_000);

        $fila = Comision::where('orden_id', $deMarta->id)->first();
        $this->assertEqualsWithDelta(4_000_000, (float) $fila->valor_orden, 1, 'la meta cuenta lo que vale hoy');
        $this->assertEqualsWithDelta(420_168, (float) $fila->monto_comision, 2, 'lo pagado no se cambia');
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(84_034, $this->cobradoDeMas()[$quien] ?? 0, 2, $quien);
        }
        // Y no se le vuelve a ofrecer nada a nadie.
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(0, $this->loQueEstaListo()[$quien] ?? 0, 1);
        }
    }

    public function test_en_el_5_directo_si_baja_se_descuenta_la_misma_proporcion(): void
    {
        $this->conLibroDeAnticipos();
        $orden = $this->orden(self::MANUELA, self::VIRTUAL, 11_900_000);
        $this->pagarTodoLoListo();

        $this->bajarValor($orden, 7_140_000); // queda en el 60 %

        $this->assertEqualsWithDelta(200_000, $this->cobradoDeMas()['Manuela'] ?? 0, 1);
    }

    /** Si sube, se paga la diferencia (usuario, 2026-11-19). */
    public function test_en_el_5_directo_si_sube_despues_de_pagar_se_paga_la_diferencia(): void
    {
        $this->conLibroDeAnticipos();
        $orden = $this->orden(self::MANUELA, self::VIRTUAL, 11_900_000);
        $this->pagarTodoLoListo();

        $this->bajarValor($orden, 15_470_000); // +3.570.000

        $pagada = Comision::where('orden_id', $orden->id)->where('estado', 'pagada')->first();
        $this->assertEqualsWithDelta(11_900_000, (float) $pagada->valor_orden, 1, 'lo pagado se queda');
        // 3.570.000 ÷ 1,19 × 5 % = 150.000
        $this->assertEqualsWithDelta(150_000, $this->loQueEstaListo()['Manuela'] ?? 0, 1);
        $this->assertSame([], $this->cobradoDeMas());

        // Pagada la diferencia, otra sincronización no abre nada más.
        $this->pagarTodoLoListo();
        ComisionController::sincronizarValorOrden($orden->fresh());
        $this->assertEqualsWithDelta(0, $this->loQueEstaListo()['Manuela'] ?? 0, 1);
        $this->assertEqualsWithDelta(650_000, $this->pagadoPorPersona()['Manuela'], 1);
    }

    public function test_en_el_pool_si_sube_despues_de_pagar_el_equipo_cobra_la_diferencia(): void
    {
        $this->conLibroDeAnticipos();
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $this->pagarTodoLoListo();

        // Marta le agrega $6 M: Norte queda en 76M, pool 1.512.605 (504.202 c/u): 84.034 más a cada una.
        $this->bajarValor($deMarta, 16_000_000);

        $listo = $this->loQueEstaListo();
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(84_034, $listo[$quien] ?? 0, 3, $quien);
        }
    }

    private function henry(): array
    {
        return collect(ComisionIndependientes::delMes(self::MES)['independientes'])->firstWhere('vendedor_id', self::HENRY);
    }

    public function test_al_independiente_si_la_venta_sube_despues_de_pagar_se_le_paga_la_diferencia(): void
    {
        $this->conLibroDeAnticipos();
        $this->sedeDeIndependientes();
        $venta = $this->orden(self::HENRY, self::NORTE, 11_900_000, abonaA: self::NORTE);
        $this->llamar('pagarIndependiente', self::HENRY, self::MES, \App\Models\Usuario::find(self::PAOLA));
        $this->assertEqualsWithDelta(500_000, $this->pagadoPorPersona()['Henry'], 1);

        // Sube $2.380.000: 2.380.000 ÷ 1,19 × 5 % = 100.000 más.
        $this->bajarValor($venta, 14_280_000);
        $this->assertEqualsWithDelta(100_000, $this->henry()['por_pagar_ventas'], 1);

        $this->llamar('pagarIndependiente', self::HENRY, self::MES, \App\Models\Usuario::find(self::PAOLA));
        $this->assertEqualsWithDelta(600_000, $this->pagadoPorPersona()['Henry'], 1, 'se le pagó la diferencia');
        $this->assertEqualsWithDelta(0, $this->henry()['por_pagar_ventas'], 1, 'y no se le vuelve a ofrecer');
        $this->assertSame([], $this->cobradoDeMas());
    }

    public function test_al_independiente_si_baja_y_vuelve_a_subir_queda_con_lo_justo(): void
    {
        $this->conLibroDeAnticipos();
        $this->sedeDeIndependientes();
        $venta = $this->orden(self::HENRY, self::NORTE, 11_900_000, abonaA: self::NORTE);
        $this->llamar('pagarIndependiente', self::HENRY, self::MES, \App\Models\Usuario::find(self::PAOLA));

        // Baja a 9.520.000 (80 %): se le descuentan 100.000 y no se le ofrece nada.
        $this->bajarValor($venta, 9_520_000);
        $this->assertEqualsWithDelta(100_000, $this->cobradoDeMas()['Henry'] ?? 0, 1);
        $this->assertEqualsWithDelta(0, $this->henry()['por_pagar_ventas'], 1);

        // Vuelve a 11.900.000: se le pagan otra vez los 100.000 que se le descontaron.
        $this->bajarValor($venta, 11_900_000);
        $this->assertEqualsWithDelta(100_000, $this->henry()['por_pagar_ventas'], 1);
    }

    public function test_listas_dice_por_que_se_le_descuenta(): void
    {
        $this->conLibroDeAnticipos();
        $cancelada = $this->orden(self::MANUELA, self::VIRTUAL, 11_900_000);
        $this->pagarTodoLoListo();
        $this->cancelar($cancelada);
        $this->orden(self::MANUELA, self::VIRTUAL, 23_800_000);

        // Con el mes fijo: sin él, qué meses entran a Listas depende del día de hoy.
        $listas = $this->llamar('loQueHayPorPagar', self::MES, false);
        $manuela = collect($listas['personas'])->firstWhere('vendedor_id', self::MANUELA);
        $this->assertEqualsWithDelta(500_000, $manuela['se_descuenta'], 1);
        $this->assertCount(1, $manuela['cobros_de_mas']);
        $this->assertStringContainsString('se canceló después de pagar la bonificación', $manuela['cobros_de_mas'][0]['nota']);
    }

    /**
     * Octubre todavía sin pagar y en noviembre se le devuelve plata al cliente
     * (usuario, 2026-11-19, caso de la #1275): si con eso la orden deja de
     * tener la mitad pagada, sale de la meta de octubre y el pool se rehace
     * sin ella. No hay nada que descontar: no se había pagado.
     */
    public function test_mes_sin_pagar_si_se_devuelve_plata_y_baja_del_50_sale_de_la_meta(): void
    {
        $this->conLibroDeAnticipos();
        $this->orden(self::PAOLA, self::NORTE, 35_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);
        // 45M contra 40M: hay pool para las tres.
        $this->assertGreaterThan(0, $this->loQueEstaListo()['NN'] ?? 0);

        // Un mes después se le devuelven $6 M: le quedan $4 M pagados de $10 M (40 %).
        DB::table('pagos')->insert(['orden_id' => $deMarta->id, 'monto' => -6_000_000, 'metodo' => 'efectivo',
            'tipo' => 'reembolso', 'created_at' => now()]);

        // Sin ella Norte queda en 35M: no llega a la meta y nadie cobra pool.
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(0, $this->loQueEstaListo()[$quien] ?? 0, 1, $quien);
        }
        $this->assertSame([], $this->cobradoDeMas());
    }

    public function test_mes_sin_pagar_si_se_cancela_no_cuenta_en_la_meta_ni_en_el_pool(): void
    {
        $this->conLibroDeAnticipos();
        $this->orden(self::PAOLA, self::NORTE, 35_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);

        $this->cancelar($deMarta);

        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertEqualsWithDelta(0, $this->loQueEstaListo()[$quien] ?? 0, 1, $quien);
        }
        $this->assertSame([], $this->cobradoDeMas());
    }

    public function test_si_no_se_habia_pagado_no_se_descuenta_nada(): void
    {
        $this->conLibroDeAnticipos();
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $deMarta = $this->orden(self::MARTA, self::NORTE, 10_000_000);

        $this->cancelar($deMarta);

        $this->assertSame([], $this->cobradoDeMas());
    }

    public function test_cancelar_una_venta_de_otra_tienda_no_le_descuenta_a_este_equipo(): void
    {
        $this->conLibroDeAnticipos();
        $this->orden(self::PAOLA, self::NORTE, 60_000_000);
        $this->orden(self::MARTA, self::NORTE, 10_000_000);
        $deGladys = $this->orden(self::GLADYS, self::EDEN, 30_000_000);
        $this->pagarTodoLoListo();

        $this->cancelar($deGladys);

        $deMas = $this->cobradoDeMas();
        foreach (['Paola', 'Marta', 'NN'] as $quien) {
            $this->assertArrayNotHasKey($quien, $deMas, "{$quien} no pierde nada");
        }
        // El Edén: sin la de Gladys no llega a la meta de 20M: pierden su pool entero.
        $this->assertGreaterThan(0, $deMas['Gladys'] ?? 0);
        $this->assertEqualsWithDelta($deMas['Gladys'], $deMas['Sebastián'] ?? 0, 2);
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
