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
            'calificar_trabajadores_taller'  => 'sometimes|boolean',
            'declaracion_disenos_especiales' => 'sometimes|boolean',
            'declaracion_personalizados'     => 'sometimes|boolean',
        ]);

        $claves = [
            'calificar_trabajadores_taller'  => AjustesSistema::CALIFICAR_TALLER,
            'declaracion_disenos_especiales' => AjustesSistema::DECLARACION_ESPECIALES,
            'declaracion_personalizados'     => AjustesSistema::DECLARACION_PERSONALIZADOS,
        ];
        foreach ($claves as $campo => $clave) {
            if (array_key_exists($campo, $data)) AjustesSistema::definir($clave, (bool) $data[$campo]);
        }

        return response()->json(AjustesSistema::todos());
    }
}
