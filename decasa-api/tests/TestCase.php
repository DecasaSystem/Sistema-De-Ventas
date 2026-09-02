<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Lo que el módulo de comisiones guarda en memoria para no preguntarlo
     * varias veces por petición: los equipos, los reemplazos y qué tiendas
     * reparten.
     *
     * Son estáticos y el proceso de las pruebas es uno solo, así que sin esto
     * una prueba se queda con la foto de la anterior. Va aquí y no en cada
     * `setUp()` porque es la clase de fallo que aparece cuando a alguien se le
     * olvida, y entonces falla una prueba que no tiene nada que ver.
     *
     * Quien cambie el escenario a mitad de prueba sí tiene que volver a
     * limpiarlo a mano.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        \App\Http\Controllers\ComisionController::olvidarQuienComparte();
    }

    /**
     * Le presta a SQLite las funciones de fecha de MySQL.
     *
     * Varias consultas del módulo de comisiones agrupan por mes con
     * CONVERT_TZ y DATE_FORMAT —lo que un independiente le abona a un almacén,
     * por ejemplo—, y SQLite no las tiene. Sin esto no se puede probar nada
     * que toque la meta de una tienda.
     *
     * Se registran en la conexión en vez de esquivar la consulta: lo que se
     * quiere comprobar es la cuenta de verdad, no una parecida.
     *
     * Se llama desde el `setUp()` de la prueba que lo necesite, después de
     * montar el esquema.
     */
    protected function prestarleASqliteLoQueEsDeMysql(): void
    {
        $pdo = DB::connection()->getPdo();

        $pdo->sqliteCreateFunction('CONVERT_TZ', function ($fecha, $desde, $hasta) {
            if ($fecha === null) return null;
            $horas = (int) substr($hasta, 0, 3) - (int) substr($desde, 0, 3);

            return \Carbon\Carbon::parse($fecha)->addHours($horas)->format('Y-m-d H:i:s');
        }, 3);

        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($fecha, $formato) {
            if ($fecha === null) return null;

            return \Carbon\Carbon::parse($fecha)->format(
                str_replace(['%Y', '%m', '%d'], ['Y', 'm', 'd'], $formato)
            );
        }, 2);
    }
}
