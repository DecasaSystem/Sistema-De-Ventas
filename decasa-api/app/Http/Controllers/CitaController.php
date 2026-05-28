<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use Illuminate\Http\Request;

class CitaController extends Controller
{
    // GET /api/citas  — vendedor ve las suyas; supervisor ve las de su tienda
    public function index(Request $request)
    {
        $usuario = $request->user();
        $estado  = $request->query('estado'); // pendiente|confirmada|completada|cancelada|null

        $q = Cita::with(['asesor:id,nombre', 'tienda:id,nombre'])
                 ->orderBy('created_at', 'desc');

        if ($usuario->rol === 'supervisor') {
            if ($usuario->tienda_default_id) {
                $q->where('tienda_id', $usuario->tienda_default_id);
            }
        } else {
            $q->where('asesor_id', $usuario->id);
        }

        if ($estado) {
            $q->where('estado', $estado);
        }

        return response()->json($q->limit(100)->get());
    }

    // PATCH /api/citas/{id}  — actualizar estado y/o notas
    public function update(Request $request, $id)
    {
        $usuario = $request->user();
        $cita    = Cita::findOrFail($id);

        // Solo el asesor dueño o un supervisor puede actualizar
        if ($cita->asesor_id !== $usuario->id && $usuario->rol !== 'supervisor') {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'estado' => 'sometimes|in:pendiente,confirmada,completada,cancelada',
            'notas'  => 'sometimes|nullable|string|max:1000',
        ]);

        $cita->update($data);

        return response()->json($cita->load(['asesor:id,nombre', 'tienda:id,nombre']));
    }
}
