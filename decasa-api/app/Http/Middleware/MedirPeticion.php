<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cuánto tardó cada petición y cuánto de eso fue la base.
 *
 * "El programa está lento" no dice dónde. Esto lo dice en cada respuesta, con
 * la cabecera estándar `Server-Timing` (la pintan las herramientas del
 * navegador y se lee con curl):
 *
 *     Server-Timing: app;dur=840, db;dur=610;desc="7 consultas"
 *
 * Y lo que pase de LENTA_MS queda en el log de Render con la ruta, para saber
 * qué pantalla atacar con datos de verdad y no con suposiciones. Solo tiempos
 * y conteos: nunca el SQL ni los datos.
 */
class MedirPeticion
{
    public const LENTA_MS = 1500;

    private static int $consultas = 0;
    private static float $msBase = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        // Un solo oyente por aplicación: en las pruebas se atienden varias
        // peticiones con la misma, y registrar uno por petición las contaría
        // dos, tres veces.
        if (! app()->bound('medir-peticion.escucha')) {
            app()->instance('medir-peticion.escucha', true);
            DB::listen(function ($consulta) {
                self::$consultas++;
                self::$msBase += $consulta->time;
            });
        }
        self::$consultas = 0;
        self::$msBase    = 0.0;

        $response = $next($request);

        // Desde que arrancó PHP: incluye levantar Laravel, que también cuenta.
        $inicio = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $total  = (microtime(true) - $inicio) * 1000;

        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.0f, db;dur=%.0f;desc="%d consultas"',
            $total, self::$msBase, self::$consultas
        ));

        if ($total >= self::LENTA_MS) {
            Log::warning(sprintf(
                '[lenta] %s /%s %.0f ms · %d consultas (%.0f ms en base) · %d bytes',
                $request->method(), $request->path(), $total, self::$consultas, self::$msBase,
                strlen((string) $response->getContent())
            ), ['usuario_id' => $request->user()?->id, 'status' => $response->getStatusCode()]);
        }

        return $response;
    }
}
