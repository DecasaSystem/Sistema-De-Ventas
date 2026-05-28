<?php

namespace App\Http\Controllers;

use App\Events\NuevaConversacionWa;
use App\Models\Cita;
use App\Models\ConversacionWa;
use App\Models\Usuario;
use App\Services\NotificacionService;
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
            'carrito'        => 'nullable|array',
            'datos_cita'     => 'nullable|array',
            'tienda_id'      => 'nullable|integer|exists:tiendas,id',
            'whatsapp_url'   => 'nullable|string',
            'contacto_url'   => 'nullable|string',
            'fuente'         => 'nullable|string|in:whatsapp,instagram',
        ]);

        $tipos_validos  = ['pedido', 'cita', 'asesor', 'personalizacion', 'otro'];
        $data['tipo']   = in_array($data['tipo'], $tipos_validos) ? $data['tipo'] : 'otro';
        $data['fuente'] = $data['fuente'] ?? 'whatsapp';

        $conv = ConversacionWa::create($data);

        try {
            broadcast(new NuevaConversacionWa($conv));
        } catch (\Throwable $e) {
            // Reverb offline — conversación guardada en BD, broadcast ignorado
        }

        $esInstagram = ($conv->fuente === 'instagram');
        $canal       = $esInstagram ? 'Instagram' : 'WhatsApp';

        $titulos = [
            'pedido'          => "Nuevo pedido ({$canal})",
            'cita'            => "Cita agendada ({$canal})",
            'asesor'          => "Cliente solicita asesor ({$canal})",
            'personalizacion' => "Solicitud de personalización ({$canal})",
            'otro'            => "Mensaje de {$canal}",
        ];
        $titulo  = $titulos[$conv->tipo] ?? "Mensaje de {$canal}";
        $mensaje = ($conv->nombre_cliente ? $conv->nombre_cliente . ': ' : '') . $conv->resumen;

        // Para citas con tienda definida: notificar solo a vendedores/supervisores de esa tienda
        $queryUsuarios = Usuario::whereIn('rol', ['vendedor', 'supervisor'])->where('activo', true);
        if ($conv->tipo === 'cita' && $conv->tienda_id) {
            $queryUsuarios->where('tienda_default_id', $conv->tienda_id);
        }
        $usuarios = $queryUsuarios->get();

        foreach ($usuarios as $u) {
            NotificacionService::crear('redes', $titulo, $mensaje, ['conversacion_id' => $conv->id], $u->id);
        }

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

        // Si es una cita con datos estructurados, crear registro en módulo Citas
        if ($conv->tipo === 'cita' && !empty($conv->datos_cita)) {
            $dc = $conv->datos_cita;
            Cita::firstOrCreate(
                ['conversacion_wa_id' => $conv->id],
                [
                    'asesor_id'      => $usuario->id,
                    'tienda_id'      => $conv->tienda_id ?? null,
                    'nombre_cliente' => $conv->nombre_cliente,
                    'telefono'       => $conv->telefono,
                    'contacto_url'   => $conv->contacto_url,
                    'fuente'         => $conv->fuente ?? 'whatsapp',
                    'dia'            => $dc['dia']    ?? '',
                    'hora'           => $dc['hora']   ?? '',
                    'motivo'         => $dc['motivo'] ?? null,
                    'estado'         => 'pendiente',
                ]
            );
        }

        try {
            broadcast(new NuevaConversacionWa($conv));
        } catch (\Throwable $e) {}

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

        try {
            broadcast(new NuevaConversacionWa($conv->fresh('tomadaPor:id,nombre')));
        } catch (\Throwable $e) {}

        return response()->json($conv->fresh('tomadaPor:id,nombre'));
    }
}
