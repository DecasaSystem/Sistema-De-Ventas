<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $q = Notificacion::orderByDesc('created_at')->take(50);

        $q->where('usuario_id', $u->id);

        return response()->json($q->get());
    }

    public function marcarLeida(Request $request, int $id)
    {
        $n = Notificacion::findOrFail($id);
        $u = $request->user();

        if ($u->rol !== 'supervisor' && $n->usuario_id !== $u->id) {
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
