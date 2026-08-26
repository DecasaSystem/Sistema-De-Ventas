<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Las defensas que se pusieron tras la auditoría.
 *
 * Se prueban porque son cosas que no se ven: si el webhook vuelve a quedar
 * abierto o el límite deja de aplicarse, todo sigue funcionando igual y nadie
 * se entera hasta que alguien lo aprovecha.
 */
class SeguridadTest extends TestCase
{
    public function test_el_webhook_rechaza_cuando_no_hay_secreto_configurado(): void
    {
        // El caso peligroso: alguien borra la variable en el panel del
        // servidor. Antes eso dejaba el endpoint abierto a internet.
        config(['app.agent_token' => null]);

        $this->postJson('/api/redes/webhook', [
            'tipo' => 'lead', 'telefono' => '3001234567', 'resumen' => 'hola',
        ])->assertStatus(401);
    }

    public function test_el_webhook_rechaza_un_token_equivocado(): void
    {
        config(['app.agent_token' => 'el-secreto-de-verdad']);

        $this->withHeader('X-Agent-Token', 'otro-token')
            ->postJson('/api/redes/webhook', [
                'tipo' => 'lead', 'telefono' => '3001234567', 'resumen' => 'hola',
            ])->assertStatus(401);

        // Y sin cabecera tampoco.
        $this->postJson('/api/redes/webhook', [
            'tipo' => 'lead', 'telefono' => '3001234567', 'resumen' => 'hola',
        ])->assertStatus(401);
    }

    public function test_la_api_tiene_techo_de_peticiones(): void
    {
        // El limitador tiene que existir: sin él, `throttleApi()` no limita
        // nada y las casi trescientas rutas quedan sin techo.
        $limiter = RateLimiter::limiter('api');
        $this->assertNotNull($limiter, 'No hay limitador "api" definido.');

        $limite = $limiter(request());
        $this->assertSame(300, $limite->maxAttempts);
    }

    public function test_las_rutas_de_la_api_pasan_por_el_limitador(): void
    {
        // Se mira el GRUPO y no el middleware de la ruta: las rutas declaran
        // "api" y es el grupo el que trae el throttle dentro. Mirar la ruta
        // suelta hace creer que no hay límite cuando sí lo hay.
        $grupo = app('router')->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains('throttle:api', $grupo,
            'El grupo api dejó de aplicar el límite de peticiones.');

        // Y que las rutas de verdad usen ese grupo.
        $ruta = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === 'api/auth/me');
        $this->assertNotNull($ruta, 'No se encontró la ruta de prueba.');
        $this->assertContains('api', $ruta->gatherMiddleware());
    }

    public function test_los_tokens_caducan(): void
    {
        // Con `null` un token servía para siempre, también uno robado.
        $this->assertNotNull(config('sanctum.expiration'));
        $this->assertSame(60 * 24 * 60, config('sanctum.expiration'));
    }
}
