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
            // Vacío vale: no avisar con anticipación (el "Entrega hoy" sigue).
            'avisar_entrega_dias_antes'      => 'sometimes|array',
            'avisar_entrega_dias_antes.*'    => 'integer|in:' . implode(',', AjustesSistema::DIAS_AVISO_POSIBLES),
        ]);

        if (array_key_exists('avisar_entrega_dias_antes', $data)) {
            AjustesSistema::definirDiasAvisoEntrega($data['avisar_entrega_dias_antes'] ?? []);
        }

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
