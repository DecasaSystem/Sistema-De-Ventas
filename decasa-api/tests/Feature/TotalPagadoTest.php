<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\Pago;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que lleva pagado una orden, con los pagos ya cargados.
 *
 * `totalPagado()` y `pagadoConTarjeta()` suman en memoria cuando la relación
 * viene cargada, en vez de preguntarle a la base una vez por llamada. Eso
 * quitó noventa y tres consultas del recálculo de comisiones, pero deja un
 * filo: quien cargó los pagos antes de que entrara uno nuevo se queda con la
 * foto vieja.
 *
 * Acá se fija justamente eso: que sumar en memoria dé lo mismo que preguntar,
 * y que después de registrar un pago haya que refrescar —que es lo que hacen
 * los dos caminos por los que se cobra una orden—.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class TotalPagadoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ordenes', function (Blueprint $t) {
            $t->id();
            $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->string('estado')->default('pendiente_anticipo');
            $t->timestamps();
        });

        Schema::create('pagos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('orden_id');
            $t->unsignedBigInteger('tienda_id')->nullable();
            $t->decimal('monto', 15, 2);
            $t->string('metodo')->nullable();
            $t->string('tipo')->nullable();
            $t->timestamps();
        });
    }

    /** Una orden con tres pagos: dos en efectivo y uno con datáfono. */
    private function ordenConPagos(): Orden
    {
        $orden = Orden::create(['valor_total' => 1_000_000]);

        foreach ([
            ['monto' => 300_000, 'metodo' => 'efectivo'],
            ['monto' => 200_000, 'metodo' => 'tarjeta'],
            ['monto' => 100_000, 'metodo' => 'transferencia'],
        ] as $p) {
            Pago::create($p + ['orden_id' => $orden->id, 'tipo' => 'abono']);
        }

        return $orden;
    }

    public function test_suma_lo_mismo_con_los_pagos_cargados_que_sin_ellos(): void
    {
        $orden = $this->ordenConPagos();

        $sinCargar = Orden::find($orden->id);
        $cargada   = Orden::with('pagos')->find($orden->id);

        $this->assertFalse($sinCargar->relationLoaded('pagos'));
        $this->assertTrue($cargada->relationLoaded('pagos'));

        $this->assertEquals(600_000, $sinCargar->totalPagado());
        $this->assertEquals(600_000, $cargada->totalPagado());

        // Y el datáfono cuenta solo lo que entró por tarjeta.
        $this->assertEquals(200_000, $sinCargar->pagadoConTarjeta());
        $this->assertEquals(200_000, $cargada->pagadoConTarjeta());
    }

    public function test_una_orden_sin_pagos_da_cero_por_los_dos_caminos(): void
    {
        $orden = Orden::create(['valor_total' => 500_000]);

        $this->assertEquals(0, $orden->totalPagado());
        $this->assertEquals(0, Orden::with('pagos')->find($orden->id)->totalPagado());
        $this->assertEquals(0, Orden::with('pagos')->find($orden->id)->pagadoConTarjeta());
    }

    public function test_despues_de_cobrar_hay_que_refrescar_para_ver_el_pago_nuevo(): void
    {
        $orden = Orden::with('pagos')->find($this->ordenConPagos()->id);
        $this->assertEquals(600_000, $orden->totalPagado());

        // Entra el saldo, como cuando el conductor cobra en la entrega.
        Pago::create([
            'orden_id' => $orden->id, 'monto' => 400_000,
            'metodo' => 'tarjeta', 'tipo' => 'saldo_final',
        ]);

        // Este es el filo: el objeto viejo sigue con su copia de los pagos.
        $this->assertEquals(600_000, $orden->totalPagado());

        // Y esto es lo que tienen que hacer los controladores —y hacen— antes
        // de responder cuánto lleva pagado el cliente.
        $this->assertEquals(1_000_000, $orden->fresh()->totalPagado());
        $this->assertEquals(600_000, $orden->fresh()->pagadoConTarjeta());

        // Recargar la relación sirve igual.
        $orden->load('pagos');
        $this->assertEquals(1_000_000, $orden->totalPagado());
    }
}
