<?php

namespace App\Http\Controllers;

use App\Models\NominaAjuste;
use App\Models\NominaItem;
use Illuminate\Http\Request;

/** Bonos y descuentos con nombre libre, encima del cálculo por días. */
class NominaAjusteController extends Controller
{
    /** POST /api/nomina/items/{itemId}/ajustes */
    public function store(Request $request, int $itemId)
    {
        $item = NominaItem::with('periodo')->findOrFail($itemId);

        if ($item->periodo->estaPagado()) {
            return response()->json(['message' => 'Este período ya está pagado y quedó congelado.'], 422);
        }

        $data = $request->validate([
            'nombre' => 'required|string|max:120',
            'monto'  => 'required|numeric',
        ], [
            'nombre.required' => 'Ponle un nombre al ajuste.',
            'monto.required'  => 'El valor es obligatorio (negativo si es un descuento).',
        ]);

        $ajuste = NominaAjuste::create([
            'nomina_item_id' => $item->id,
            'nombre'         => $data['nombre'],
            'monto'          => $data['monto'],
        ]);

        return response()->json($ajuste, 201);
    }

    /** DELETE /api/nomina/ajustes/{id} */
    public function destroy(int $id)
    {
        $ajuste = NominaAjuste::with('item.periodo')->findOrFail($id);

        if ($ajuste->item->periodo->estaPagado()) {
            return response()->json(['message' => 'Este período ya está pagado y quedó congelado.'], 422);
        }

        $ajuste->delete();

        return response()->json(['ok' => true]);
    }
}
