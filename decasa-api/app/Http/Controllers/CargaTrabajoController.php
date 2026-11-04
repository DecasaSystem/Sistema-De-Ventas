<?php

namespace App\Http\Controllers;

use App\Services\CargaDeTrabajo;
use Illuminate\Http\Request;

/**
 * GET /api/carga-trabajo — cuánto trabajo hay y cuánto viene, en órdenes y en
 * el taller. La cuenta vive en `App\Services\CargaDeTrabajo`.
 *
 * Lo ve el supervisor y quien ve producción (la supervisora del taller): es la
 * pregunta de los dos. Un vendedor sin acceso a producción no: el tablero
 * muestra el taller entero.
 */
class CargaTrabajoController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();

        if ($usuario->rol !== 'supervisor' && ! $usuario->veProduccion()) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $tiendaId = $request->integer('tienda_id') ?: null;

        return response()->json((new CargaDeTrabajo($usuario, $tiendaId))->calcular());
    }
}
