<?php

namespace App\Http\Controllers;

use App\Models\NominaItem;
use Illuminate\Http\Request;

class NominaItemController extends Controller
{
    /**
     * PATCH /api/nomina/items/{id}
     *
     * Ajustar días trabajados, valor u observaciones de un empleado dentro
     * de un período — bloqueado si el período ya está pagado.
     */
    public function update(Request $request, int $id)
    {
        $item = NominaItem::with('periodo')->findOrFail($id);

        if ($item->periodo->estaPagado()) {
            return response()->json(['message' => 'Este período ya está pagado y quedó congelado.'], 422);
        }

        $data = $request->validate([
            'valor_label'     => 'sometimes|required|string|max:60',
            'valor_dia'       => 'sometimes|numeric|min:0',
            'dias_trabajados' => 'sometimes|numeric|min:0|max:31',
            'observaciones'   => 'sometimes|nullable|string|max:2000',
        ]);

        $item->update($data);
        $item->load('ajustes', 'empleado');

        return response()->json([
            'id'              => $item->id,
            'empleado_id'     => $item->empleado_id,
            'empleado_nombre' => $item->empleado?->nombre,
            'empleado_cargo'  => $item->empleado?->cargo,
            'valor_label'     => $item->valor_label,
            'valor_dia'       => (float) $item->valor_dia,
            'dias_trabajados' => (float) $item->dias_trabajados,
            'observaciones'   => $item->observaciones,
            'subtotal'        => $item->subtotal(),
            'total'           => $item->total(),
            'ajustes'         => $item->ajustes->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre, 'monto' => (float) $a->monto]),
        ]);
    }

    /** DELETE /api/nomina/items/{id} — quitar a alguien de un período (se agregó por error). */
    public function destroy(int $id)
    {
        $item = NominaItem::with('periodo')->findOrFail($id);

        if ($item->periodo->estaPagado()) {
            return response()->json(['message' => 'Este período ya está pagado y quedó congelado.'], 422);
        }

        $item->delete();

        return response()->json(['ok' => true]);
    }
}
