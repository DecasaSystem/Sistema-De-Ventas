<?php

use App\Http\Middleware\CheckPermiso;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\MedirPeticion;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TokenDelAgente;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        // Techo de peticiones para toda la API. Desde Laravel 11 el grupo `api`
        // ya no lo trae puesto, así que había que pedirlo: sin esto solo siete
        // rutas de casi trescientas tenían límite. El cupo se define en
        // AppServiceProvider (limitador 'api').
        $middleware->throttleApi();

        // Por fuera de todo lo de la API (límite, sesión, controlador), para
        // que lo que mide sea la petición entera. Ver MedirPeticion.
        $middleware->prependToGroup('api', MedirPeticion::class);
        $middleware->alias([
            'role'    => CheckRole::class,
            'permiso' => CheckPermiso::class,
            'agente'  => TokenDelAgente::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // "No encontrado" en palabras, no "No query results for model
        // [App\Models\Orden] 939". Ver App\Support\NoEncontrado.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $modelo = $e->getPrevious();
            if ($modelo instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(\App\Support\NoEncontrado::respuesta($modelo), 404);
            }

            return response()->json([
                'message'   => 'Eso no existe o ya no está disponible.',
                'no_existe' => true,
            ], 404);
        });
    })->create();
