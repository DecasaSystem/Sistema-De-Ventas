<?php

namespace App\Http\Controllers;

use App\Events\NuevaConversacionWa;
use App\Models\ConversacionWa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedesController extends Controller
{
    // GET /api/redes/conversaciones
    public function index(Request $request)
    {
        $estado = $request->query('estado'); // pendiente | tomada | terminada | null (todas)

        $q = ConversacionWa::with('tomadaPor:id,nombre')
            ->orderByRaw("FIELD(estado, 'pendiente', 'tomada', 'terminada')")
            ->orderBy('created_at', 'desc');

        if ($estado) {
            $q->where('estado', $estado);
        }

        return response()->json($q->limit(100)->get());
    }

    // POST /api/redes/webhook  — recibe notificaciones del agente WA (sin auth, con token secreto)
    public function webhook(Request $request)
    {
        $secret = config('app.agent_token');
        if ($secret && $request->header('X-Agent-Token') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'tipo'           => 'required|string',
            'telefono'       => 'required|string',
            'nombre_cliente' => 'nullable|string',
            'resumen'        => 'required|string',
            'historial'      => 'nullable|array',
            'whatsapp_url'   => 'nullable|string',
        ]);

        $tipos_validos = ['pedido', 'cita', 'asesor', 'personalizacion', 'otro'];
        $data['tipo']  = in_array($data['tipo'], $tipos_validos) ? $data['tipo'] : 'otro';

        $conv = ConversacionWa::create($data);

        broadcast(new NuevaConversacionWa($conv));

        return response()->json($conv, 201);
    }

    // POST /api/redes/conversaciones/{id}/tomar — atómico: primero que llega toma
    public function tomar(Request $request, $id)
    {
        $usuario = $request->user();

        $conv = DB::transaction(function () use ($id, $usuario) {
            $c = ConversacionWa::lockForUpdate()->findOrFail($id);

            if ($c->estado !== 'pendiente') {
                return null;
            }

            $c->update([
                'estado'    => 'tomada',
                'tomada_por' => $usuario->id,
                'tomada_at' => now(),
            ]);

            return $c->fresh('tomadaPor:id,nombre');
        });

        if (!$conv) {
            return response()->json(['error' => 'Esta conversación ya fue tomada por otro vendedor.'], 409);
        }

        broadcast(new NuevaConversacionWa($conv));

        return response()->json($conv);
    }

    // POST /api/redes/conversaciones/{id}/terminar
    public function terminar(Request $request, $id)
    {
        $usuario = $request->user();
        $conv    = ConversacionWa::with('tomadaPor:id,nombre')->findOrFail($id);

        // Solo quien la tomó o un supervisor puede terminarla
        if ($conv->tomada_por !== $usuario->id) {
            return response()->json(['error' => 'Solo quien tomó la conversación puede terminarla.'], 403);
        }

        if ($conv->estado === 'terminada') {
            return response()->json(['error' => 'Ya está terminada.'], 409);
        }

        $conv->update([
            'estado'       => 'terminada',
            'terminada_at' => now(),
        ]);

        broadcast(new NuevaConversacionWa($conv->fresh('tomadaPor:id,nombre')));

        return response()->json($conv->fresh('tomadaPor:id,nombre'));
    }
}
