<?php

namespace App\Http\Controllers;

use App\Models\InventarioMovimiento;
use App\Models\InventarioVarianteConfig;
use App\Models\Inventario;
use App\Models\ProductoVarianteConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoVarianteConfigController extends Controller
{
    /**
     * GET /api/productos/{id}/variante-configs?tienda_id=X
     * Devuelve los tipos de variante asignados a un producto con opciones, precios y stock.
     */
    public function index(Request $request, int $productoId)
    {
        $tiendaId = $request->query('tienda_id');

        $configs = ProductoVarianteConfig::where('producto_id', $productoId)
            ->with(['tipo', 'opcion'])
            ->get();

        $stocks = collect();
        if ($tiendaId) {
            $stocks = InventarioVarianteConfig::where('tienda_id', $tiendaId)
                ->whereIn('config_id', $configs->pluck('id'))
                ->get()
                ->keyBy('config_id');
        }

        $grouped = $configs
            ->groupBy('tipo_variante_id')
            ->map(function ($items, $tipoId) use ($tiendaId, $stocks) {
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
                        'stock_disponible' => $tiendaId
                            ? (int) ($stocks[$c->id]?->cantidad_disponible ?? 0)
                            : null,
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

        return $this->index($request, $productoId);
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

    /**
     * POST /api/inventario/variante-configs/entrada
     * Agrega stock a una opción de variante personalizada en una tienda.
     */
    public function entrada(Request $request)
    {
        $data = $request->validate([
            'config_id' => 'required|exists:producto_variante_configs,id',
            'tienda_id' => 'required|exists:tiendas,id',
            'cantidad'  => 'required|integer|min:1',
            'motivo'    => 'nullable|string|max:200',
        ]);

        $config = ProductoVarianteConfig::with(['tipo', 'opcion'])->findOrFail($data['config_id']);

        $baseInv = Inventario::where('producto_id', $config->producto_id)
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        $baseDisponible = $baseInv?->cantidad_disponible ?? 0;
        if ($baseDisponible === 0) {
            abort(422, 'Agrega primero stock base al producto en esta tienda.');
        }

        // Total ya asignado a TODAS las opciones de este tipo en esta tienda
        $totalAsignado = InventarioVarianteConfig::where('tienda_id', $data['tienda_id'])
            ->whereHas('config', fn ($q) => $q
                ->where('producto_id', $config->producto_id)
                ->where('tipo_variante_id', $config->tipo_variante_id))
            ->sum('cantidad_disponible');

        $sinAsignar = $baseDisponible - $totalAsignado;

        if ($data['cantidad'] > $sinAsignar) {
            abort(422, "Solo hay {$sinAsignar} unidad(es) sin asignar (base: {$baseDisponible}, asignadas: {$totalAsignado}).");
        }

        $inv = InventarioVarianteConfig::firstOrCreate(
            ['config_id' => $data['config_id'], 'tienda_id' => $data['tienda_id']],
            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
        );
        $inv->increment('cantidad_disponible', $data['cantidad']);

        InventarioMovimiento::create([
            'producto_id' => $config->producto_id,
            'tienda_id'   => $data['tienda_id'],
            'tipo'        => 'entrada',
            'cantidad'    => $data['cantidad'],
            'motivo'      => $data['motivo'] ?? "Entrada variante {$config->tipo->nombre}: {$config->opcion->nombre}",
            'usuario_id'  => $request->user()->id,
        ]);

        return response()->json(['ok' => true]);
    }
}
