<?php

namespace App\Http\Controllers;

use App\Models\TipoVariante;
use App\Models\TipoVarianteOpcion;
use Illuminate\Http\Request;

class TipoVarianteController extends Controller
{
    /**
     * GET /api/tipos-variante
     * Devuelve todos los tipos activos con sus opciones.
     */
    public function index()
    {
        $tipos = TipoVariante::where('activo', true)
            ->orderBy('nombre')
            ->with('opciones')
            ->get();

        return response()->json($tipos);
    }

    /**
     * POST /api/tipos-variante
     * Crea un tipo nuevo.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'        => 'required|string|max:100|unique:tipos_variante,nombre',
            'afecta_precio' => 'required|boolean',
        ]);

        $tipo = TipoVariante::create($data);
        $tipo->load('opciones');

        return response()->json($tipo, 201);
    }

    /**
     * DELETE /api/tipos-variante/{id}
     * Desactiva un tipo.
     */
    public function destroy(int $id)
    {
        $tipo = TipoVariante::findOrFail($id);
        $tipo->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/tipos-variante/{id}/opciones
     * Agrega una o varias opciones a un tipo existente.
     */
    public function storeOpciones(Request $request, int $id)
    {
        $tipo = TipoVariante::where('activo', true)->findOrFail($id);

        $data = $request->validate([
            'opciones'   => 'required|array|min:1',
            'opciones.*' => 'required|string|max:100',
        ]);

        $creadas = [];
        foreach ($data['opciones'] as $nombre) {
            $opcion = TipoVarianteOpcion::firstOrCreate(
                ['tipo_variante_id' => $tipo->id, 'nombre' => trim($nombre)],
                ['activo' => true]
            );
            if (!$opcion->activo) {
                $opcion->update(['activo' => true]);
            }
            $creadas[] = $opcion;
        }

        $tipo->load('opciones');
        return response()->json($tipo, 201);
    }

    /**
     * DELETE /api/tipos-variante/opciones/{id}
     * Desactiva una opción.
     */
    public function destroyOpcion(int $id)
    {
        $opcion = TipoVarianteOpcion::findOrFail($id);
        $opcion->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }
}
