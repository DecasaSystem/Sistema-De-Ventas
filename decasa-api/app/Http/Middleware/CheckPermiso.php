<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Análogo a `role`, pero para permisos por módulo que ya no dependen del rol
 * (acceso_costos, acceso_despacho, etc). El middleware `role` solo sabe leer
 * la columna `rol`, así que estas banderas necesitan su propio chequeo.
 *
 * Uso: 'permiso:acceso_costos' exige la bandera prendida sin excepción.
 * 'permiso:acceso_costos,ebanista' además deja pasar a quien tenga ese rol
 * (para los accesos automáticos que no se tocaron, como el del ebanista).
 */
class CheckPermiso
{
    public function handle(Request $request, Closure $next, string $flag, string ...$rolesExtra): Response
    {
        $usuario = $request->user();
        $ok = $usuario && ($usuario->$flag || in_array($usuario->rol, $rolesExtra, true));

        if (! $ok) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return $next($request);
    }
}
