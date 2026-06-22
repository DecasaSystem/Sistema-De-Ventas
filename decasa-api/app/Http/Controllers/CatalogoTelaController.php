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
            ->get(['id', 'marca', 'tipo', 'color', 'referencia', 'textura']);

        $grouped = $rows->groupBy('marca')->map(fn($marcaRows, $marca) => [
            'marca' => $marca,
            'tipos' => $marcaRows->groupBy('tipo')->map(fn($tipoRows, $tipo) => [
                'tipo'    => $tipo,
                'colores' => $tipoRows->map(fn($r) => [
                    'id'         => $r->id,
                    'color'      => $r->color,
                    'referencia' => $r->referencia,
                    'textura'    => $r->textura,
                ])->values(),
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
            'marca'           => 'required|string|max:100',
            'tipo'            => 'required|string|max:100',
            'color'           => 'required|string|max:100',
            'referencia'      => 'nullable|string|max:200',
            'textura'         => 'nullable|string|max:100',
            'metros_iniciales'=> 'nullable|numeric|min:0',
        ]);

        $tela = CatalogoTela::firstOrCreate(
            ['marca' => trim($data['marca']), 'tipo' => trim($data['tipo']), 'color' => trim($data['color'])],
            [
                'activo'     => true,
                'referencia' => isset($data['referencia']) ? trim($data['referencia']) : null,
                'textura'    => isset($data['textura'])    ? trim($data['textura'])    : null,
            ]
        );

        if (!$tela->activo) {
            $tela->update(['activo' => true]);
        }

        $metros = (float) ($data['metros_iniciales'] ?? 0);
        if ($metros > 0) {
            \Illuminate\Support\Facades\DB::table('catalogo_telas')
                ->where('id', $tela->id)
                ->increment('metros_disponibles', $metros);
            $tela = $tela->fresh();
        }

        return response()->json([
            'id'                 => $tela->id,
            'marca'              => $tela->marca,
            'tipo'               => $tela->tipo,
            'color'              => $tela->color,
            'referencia'         => $tela->referencia,
            'textura'            => $tela->textura,
            'metros_disponibles' => (float) $tela->metros_disponibles,
            'metros_reservados'  => (float) $tela->metros_reservados,
            'metros_libres'      => round((float) $tela->metros_disponibles - (float) $tela->metros_reservados, 2),
        ], 201);
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
