<?php

namespace App\Support;

/**
 * Los cachés que viven lo que dura una petición, en un solo sitio.
 *
 * Varias clases guardan en variables estáticas lo que ya le preguntaron a la
 * base —equipos de comisión, reemplazos, tiendas cerradas, si existe tal
 * columna— para no repetir la consulta en cada mes de un cálculo. En una
 * petición web eso está bien: cada una empieza con PHP limpio.
 *
 * Donde NO empiezan limpias es en el trabajador de la cola (`queue:work`, un
 * proceso que vive horas y atiende un trabajo tras otro) y en las pruebas
 * (todas en el mismo proceso). Ahí lo guardado por un trabajo seguiría vivo
 * en el siguiente. Por eso se olvida todo antes de cada trabajo de la cola
 * (AppServiceProvider) y antes de cada prueba (TestCase).
 *
 * Quien agregue un caché estático nuevo lo suma aquí.
 */
class CachesDePeticion
{
    public static function olvidarTodo(): void
    {
        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        \App\Http\Controllers\ComisionController::olvidarQuienComparte();
        \App\Http\Controllers\ComisionController::olvidarPeriodicidades();
        \App\Http\Controllers\ComisionController::olvidarEsquema();
        \App\Models\Orden::olvidarEsquema();
        \App\Services\AnticiposComision::olvidarEsquema();
        \App\Models\Orden::olvidarSedes();
        \App\Services\ConsumoTelas::olvidarCache();
        \App\Models\Tienda::olvidarCerradas();
        \App\Services\ComisionIndependientes::olvidarCache();
        \App\Services\GarantiaService::olvidarCache();
        \App\Http\Controllers\OrdenMensajeController::olvidarCache();
    }
}
