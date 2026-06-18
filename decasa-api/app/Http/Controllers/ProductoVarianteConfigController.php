<?php

namespace App\Http\Controllers;

use App\Models\ProductoVarianteConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoVarianteConfigController extends Controller
{
    /**
     * GET /api/productos/{id}/variante-configs
     * Devuelve los tipos de variante asignados a un producto, con sus opciones y precios.
     */
    public function index(int $productoId)
    {
        $configs = ProductoVarianteConfig::where('producto_id', $productoId)
            ->with(['tipo', 'opcion'])
            ->get();

        $grouped = $configs
            ->groupBy('tipo_variante_id')
            ->map(function ($items, $tipoId) {
                $tipo = $items->first()->tipo;
                return [
                    'tipo_variante_id' => (int) $tipoId,
                    'tipo' => [
                        'id'            => $tipo->id,
                        'nombre'        => $tipo->nombre,
                        'afecta_precio' => $tipo->afecta_precio,
                    ],
                    'items' => $items->map(fn ($c) => [
                        'id'               => $c->id,
                        'opcion_id'        => $c->opcion_id,
                        'opcion_nombre'    => $c->opcion->nombre,
                        'precio_adicional' => (float) $c->precio_adicional,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json($grouped);
    }

    /**
     * POST /api/productos/{id}/variante-configs
     * Body: { tipo_variante_id, items:[{opcion_id, precio_adicional}] }
     * Upserta todas las configs para este producto + tipo.
     */
    public function upsert(Request $request, int $productoId)
    {
        $data = $request->validate([
            'tipo_variante_id'         => 'required|exists:tipos_variante,id',
            'items'                    => 'required|array|min:1',
            'items.*.opcion_id'        => 'required|exists:tipo_variante_opciones,id',
            'items.*.precio_adicional' => 'nullable|numeric|min:0',
        ]);

        $tipoId = (int) $data['tipo_variante_id'];

        DB::transaction(function () use ($productoId, $tipoId, $data) {
            foreach ($data['items'] as $item) {
                ProductoVarianteConfig::updateOrCreate(
                    [
                        'producto_id'      => $productoId,
                        'tipo_variante_id' => $tipoId,
                        'opcion_id'        => (int) $item['opcion_id'],
                    ],
                    [
                        'precio_adicional' => $item['precio_adicional'] ?? 0,
                    ]
                );
            }
        });

        return $this->index($productoId);
    }

    /**
     * DELETE /api/productos/{id}/variante-configs/tipo/{tipoId}
     * Elimina todas las configs de un tipo para este producto.
     */
    public function destroyTipo(int $productoId, int $tipoId)
    {
        ProductoVarianteConfig::where('producto_id', $productoId)
            ->where('tipo_variante_id', $tipoId)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
