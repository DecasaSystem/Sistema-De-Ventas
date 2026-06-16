<?php

namespace App\Http\Controllers;

use App\Models\CatalogoTela;
use Illuminate\Http\Request;

class CatalogoTelaController extends Controller
{
    /**
     * GET /catalogo-telas
     * Retorna las entradas del catálogo agrupadas: [{ marca, tipos: [{ tipo, colores: [{id,color}] }] }]
     */
    public function index()
    {
        $rows = CatalogoTela::where('activo', true)
            ->orderBy('marca')->orderBy('tipo')->orderBy('color')
            ->get(['id', 'marca', 'tipo', 'color']);

        $grouped = $rows->groupBy('marca')->map(fn($marcaRows, $marca) => [
            'marca' => $marca,
            'tipos' => $marcaRows->groupBy('tipo')->map(fn($tipoRows, $tipo) => [
                'tipo'   => $tipo,
                'colores' => $tipoRows->map(fn($r) => ['id' => $r->id, 'color' => $r->color])->values(),
            ])->values(),
        ])->values();

        return response()->json($grouped);
    }

    /**
     * POST /catalogo-telas
     * Agrega una nueva combinación marca+tipo+color al catálogo.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'marca' => 'required|string|max:100',
            'tipo'  => 'required|string|max:100',
            'color' => 'required|string|max:100',
        ]);

        $tela = CatalogoTela::firstOrCreate(
            ['marca' => trim($data['marca']), 'tipo' => trim($data['tipo']), 'color' => trim($data['color'])],
            ['activo' => true]
        );

        if (!$tela->activo) {
            $tela->update(['activo' => true]);
        }

        return response()->json($tela, 201);
    }

    /**
     * POST /catalogo-telas/batch
     * Agrega varios colores de una vez para una misma marca+tipo.
     */
    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'marca'     => 'required|string|max:100',
            'tipo'      => 'required|string|max:100',
            'colores'   => 'required|array|min:1',
            'colores.*' => 'required|string|max:100',
        ]);

        $creados = [];
        foreach ($data['colores'] as $color) {
            $tela = CatalogoTela::firstOrCreate(
                ['marca' => trim($data['marca']), 'tipo' => trim($data['tipo']), 'color' => trim($color)],
                ['activo' => true]
            );
            if (!$tela->activo) {
                $tela->update(['activo' => true]);
            }
            $creados[] = $tela;
        }

        return response()->json($creados, 201);
    }

    /**
     * DELETE /catalogo-telas/{id}
     * Desactiva una entrada del catálogo.
     */
    public function destroy(int $id)
    {
        CatalogoTela::findOrFail($id)->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }
}
