<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    private const POR_PAGINA = 50;

    /**
     * Las notificaciones de uno, de a 50 y las más nuevas primero.
     *
     * Antes se mandaban solo las últimas 50 y ya: la número 51 no se borraba,
     * pero desaparecía de la campana y era como si se hubiera borrado. Ahora
     * se piden las anteriores con `antes_de` (el id de la última que se ve).
     *
     * En la primera página van además las urgentes sin leer que hayan quedado
     * más atrás —son cambios de plata y no pueden quedar enterradas—, y el
     * total de no leídas, que el globo de la campana no puede contar solo con
     * lo que tiene cargado.
     */
    public function index(Request $request)
    {
        $u = $request->user();
        $antesDe = $request->integer('antes_de');

        $q = Notificacion::where('usuario_id', $u->id)->orderByDesc('id');
        if ($antesDe) $q->where('id', '<', $antesDe);

        $items  = $q->take(self::POR_PAGINA + 1)->get();
        $hayMas = $items->count() > self::POR_PAGINA;
        $items  = $items->take(self::POR_PAGINA);
        // Desde dónde sigue la página siguiente. Se da armado porque las
        // urgentes de atrás que se agregan abajo no cuentan para eso.
        $siguiente = $hayMas ? $items->last()->id : null;

        if (! $antesDe && $hayMas) {
            $urgentesAtras = Notificacion::where('usuario_id', $u->id)
                ->where('urgente', true)->where('leida', false)
                ->where('id', '<', $siguiente)
                ->orderByDesc('id')->get();
            $items = $items->concat($urgentesAtras);
        }

        return response()->json([
            'items'     => $items->values(),
            'hay_mas'   => $hayMas,
            'siguiente' => $siguiente,
            'no_leidas' => Notificacion::where('usuario_id', $u->id)->where('leida', false)->count(),
        ]);
    }

    public function marcarLeida(Request $request, int $id)
    {
        $n = Notificacion::findOrFail($id);
        $u = $request->user();

        // Solo el dueño. El supervisor podía marcar como leída la de cualquiera,
        // y eso deja al otro sin ver un aviso pendiente sin haberlo abierto
        // nunca —justo lo que no puede pasar con un aviso de plata—.
        if ($n->usuario_id !== $u->id) {
            abort(403);
        }

        $n->update(['leida' => true]);
        return response()->json(['ok' => true]);
    }

    public function marcarTodas(Request $request)
    {
        $u = $request->user();
        $q = Notificacion::where('leida', false)->where('usuario_id', $u->id);

        $q->update(['leida' => true]);
        return response()->json(['ok' => true]);
    }

    public function eliminar(Request $request, int $id)
    {
        $n = Notificacion::findOrFail($id);
        $u = $request->user();

        if ($n->usuario_id !== $u->id) {
            abort(403);
        }

        $n->delete();
        return response()->json(['ok' => true]);
    }

    public function eliminarTodas(Request $request)
    {
        $u = $request->user();
        Notificacion::where('usuario_id', $u->id)->delete();
        return response()->json(['ok' => true]);
    }
}
