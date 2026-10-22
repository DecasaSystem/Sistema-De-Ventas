<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Esquema;
use App\Support\TiposNotificacion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Trabajadores → Notificaciones: qué avisos le llegan a cada persona.
 *
 * Lo maneja un supervisor (como el resto de la ficha). Apagar un grupo hace
 * que esos avisos no se creen para esa persona ni le lleguen al celular
 * (NotificacionService::crear). No borra los que ya tiene.
 */
class NotificacionesUsuarioController extends Controller
{
    /** GET /usuarios/{id}/notificaciones */
    public function show(int $id)
    {
        $u = Usuario::findOrFail($id);
        return response()->json(['grupos' => $this->grupos($u)]);
    }

    /**
     * PUT /usuarios/{id}/notificaciones  { grupos: { ventas: true, atrasados: false, … } }
     *
     * Se manda solo lo que cambia: un grupo que no viene queda como estaba.
     */
    public function update(Request $request, int $id)
    {
        $u = Usuario::findOrFail($id);
        $data = $request->validate([
            'grupos'   => 'required|array',
            'grupos.*' => 'boolean',
        ]);
        $claves = array_keys($data['grupos']);
        $request->validate([
            'grupos' => [function ($attr, $valor, $fail) use ($claves) {
                $raras = array_diff($claves, array_keys(TiposNotificacion::GRUPOS));
                if ($raras) $fail('No existe el grupo de avisos: ' . implode(', ', $raras) . '.');
            }],
        ]);

        $apagadas = $u->notificaciones_apagadas ?? [];
        foreach ($data['grupos'] as $clave => $prendido) {
            $grupo = TiposNotificacion::GRUPOS[$clave];
            if (! empty($grupo['columna'])) {
                // Los que ya tenían su interruptor siguen en su columna: se
                // revisan donde se manda el aviso.
                $u->{$grupo['columna']} = (bool) $prendido;
                continue;
            }
            $apagadas = $prendido
                ? array_values(array_diff($apagadas, [$clave]))
                : array_values(array_unique([...$apagadas, $clave]));
        }

        if (Esquema::columna('usuarios', 'notificaciones_apagadas')) {
            // En el orden del catálogo, y null si no hay ninguna apagada.
            $apagadas = array_values(array_intersect(TiposNotificacion::clavesApagables(), $apagadas));
            $u->notificaciones_apagadas = $apagadas ?: null;
        }
        $u->save();
        TiposNotificacion::olvidarCache();

        return response()->json(['grupos' => $this->grupos($u->fresh())]);
    }

    private function grupos(Usuario $u): array
    {
        $apagadas = $u->notificaciones_apagadas ?? [];
        $out = [];
        foreach (TiposNotificacion::GRUPOS as $clave => $g) {
            $out[] = [
                'clave'       => $clave,
                'nombre'      => $g['nombre'],
                'descripcion' => $g['descripcion'],
                'aviso'       => $g['aviso'] ?? null,
                'prendido'    => ! empty($g['columna'])
                    ? (bool) $u->{$g['columna']}
                    : ! in_array($clave, $apagadas, true),
            ];
        }
        return $out;
    }
}
