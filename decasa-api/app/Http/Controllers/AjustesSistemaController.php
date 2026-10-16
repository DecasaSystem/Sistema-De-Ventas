<?php

namespace App\Http\Controllers;

use App\Support\AjustesSistema;
use Illuminate\Http\Request;

/**
 * Gestión → Ajustes: interruptores que cambian cómo trabaja el sistema.
 *
 * Leerlos lo necesita cualquiera con sesión (la pantalla del taller pregunta
 * si tiene que pedir estrellas); cambiarlos, solo un supervisor (la ruta va
 * con `role:supervisor`).
 */
class AjustesSistemaController extends Controller
{
    /** GET /api/ajustes-sistema */
    public function index()
    {
        return response()->json(AjustesSistema::todos());
    }

    /** PATCH /api/ajustes-sistema */
    public function update(Request $request)
    {
        $data = $request->validate([
            'calificar_trabajadores_taller' => 'sometimes|boolean',
        ]);

        if (array_key_exists('calificar_trabajadores_taller', $data)) {
            AjustesSistema::definir(AjustesSistema::CALIFICAR_TALLER, (bool) $data['calificar_trabajadores_taller']);
        }

        return response()->json(AjustesSistema::todos());
    }
}
