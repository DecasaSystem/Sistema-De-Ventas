<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de los agentes de WhatsApp e Instagram (repo aparte, ver
 * docs/contrato-agentes.md): no tienen usuario, se identifican con la cabecera
 * `X-Agent-Token` = AGENT_TOKEN.
 *
 * Estaba escrita dentro de RedesController::webhook; ahora que los agentes
 * también consultan pedidos, vive aquí para que las dos rutas tengan la misma
 * cerradura y no se separen.
 */
class TokenDelAgente
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sin secreto configurado se RECHAZA, no se deja pasar.
        //
        // Antes la comprobación era `if ($secret && ...)`: si la variable de
        // entorno faltaba, la condición no se cumplía y el webhook quedaba
        // abierto a internet sin que nadie se enterara. Borrar una variable en
        // el panel del servidor no puede ser lo mismo que quitarle la puerta
        // a un endpoint público que crea clientes y conversaciones.
        $secret = config('app.agent_token');

        if (! $secret) {
            \Log::error('[DECASA] Ruta de los agentes llamada sin AGENT_TOKEN configurado: se rechaza.', ['ruta' => $request->path()]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // hash_equals: la comparación normal se corta en el primer carácter
        // distinto y el tiempo de respuesta va filtrando el secreto.
        if (! hash_equals($secret, (string) $request->header('X-Agent-Token'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
