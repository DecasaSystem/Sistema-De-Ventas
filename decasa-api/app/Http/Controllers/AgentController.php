<?php

namespace App\Http\Controllers;

use App\Services\AgentService;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(private AgentService $agent) {}

    public function chat(Request $request)
    {
        $data = $request->validate([
            'messages'           => 'required|array|min:1|max:20',
            'messages.*.role'    => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
            // base64 data URL de imagen (solo en mensajes de usuario con foto/boceto)
            'messages.*.image'   => 'nullable|string|max:400000',
        ]);

        $usuario  = $request->user();

        // La app solo le muestra el chat a supervisores y vendedores; la API
        // decía lo mismo solo en la pantalla, y un conductor con su token
        // podía preguntarle lo que quisiera.
        if (! \App\Services\Asistente\PermisosAsistente::puedeUsarChat($usuario)) {
            return response()->json(['message' => 'El asistente no está disponible para tu perfil.'], 403);
        }

        $respuesta = $this->agent->chat($data['messages'], $usuario);

        return response()->json(['respuesta' => $respuesta]);
    }
}
