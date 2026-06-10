<?php

namespace App\Http\Controllers;

use App\Models\Camion;
use App\Models\Usuario;
use Illuminate\Http\Request;

class CamionController extends Controller
{
    public function index()
    {
        $camiones = Camion::with('conductor:id,nombre,email')
            ->orderBy('id')
            ->get();

        return response()->json($camiones);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'       => 'nullable|string|max:100',
            'placa'        => 'nullable|string|max:20',
            'conductor_id' => 'nullable|exists:usuarios,id',
        ]);

        if (! empty($data['conductor_id'])) {
            $conductor = Usuario::findOrFail($data['conductor_id']);
            if ($conductor->rol !== 'conductor') {
                return response()->json(['message' => 'El usuario seleccionado no es un conductor.'], 422);
            }
        }

        $camion = Camion::create(array_merge($data, ['activo' => true]));
        $camion->load('conductor:id,nombre,email');

        return response()->json($camion, 201);
    }

    public function update(Request $request, Camion $camion)
    {
        $data = $request->validate([
            'nombre'       => 'sometimes|nullable|string|max:100',
            'placa'        => 'sometimes|nullable|string|max:20',
            'conductor_id' => 'sometimes|nullable|exists:usuarios,id',
        ]);

        if (isset($data['conductor_id']) && $data['conductor_id']) {
            $conductor = Usuario::findOrFail($data['conductor_id']);
            if ($conductor->rol !== 'conductor') {
                return response()->json(['message' => 'El usuario seleccionado no es un conductor.'], 422);
            }
            if (! $conductor->activo) {
                return response()->json(['message' => 'El conductor seleccionado no está activo.'], 422);
            }
        }

        $camion->update($data);
        $camion->load('conductor:id,nombre,email');

        return response()->json($camion);
    }
}
