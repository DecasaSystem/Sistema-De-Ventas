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
 * 'permiso:acceso_clientes|acceso_nueva_orden' deja pasar con cualquiera de
 * las banderas (Nueva orden busca y crea clientes sin tener el módulo Clientes).
 */
class CheckPermiso
{
    public function handle(Request $request, Closure $next, string $flag, string ...$rolesExtra): Response
    {
        $usuario = $request->user();
        $tiene = $usuario && collect(explode('|', $flag))->contains(fn ($f) => $usuario->tienePermiso($f));
        $ok = $usuario && ($tiene || in_array($usuario->rol, $rolesExtra, true));

        if (! $ok) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return $next($request);
    }
}
