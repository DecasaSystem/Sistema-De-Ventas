<?php

namespace App\Http\Controllers;

use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteConfig;
use App\Models\InventarioVarianteCombinacion;
use App\Models\Inventario;
use App\Models\ProductoVariante;
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
            // Tiendas donde existe el producto (para auto-crear stock en 0)
            $tiendaIds = Inventario::where('producto_id', $productoId)->pluck('tienda_id');

            foreach ($data['items'] as $item) {
                $config = ProductoVarianteConfig::updateOrCreate(
                    [
                        'producto_id'      => $productoId,
                        'tipo_variante_id' => $tipoId,
                        'opcion_id'        => (int) $item['opcion_id'],
                    ],
                    [
                        'precio_adicional' => $item['precio_adicional'] ?? 0,
                    ]
                );

                // Auto-crear InventarioVarianteConfig en cada tienda (stock 0)
                // para que la opción aparezca en las cards aunque no tenga stock aún
                foreach ($tiendaIds as $tiendaId) {
                    InventarioVarianteConfig::firstOrCreate(
                        ['config_id' => $config->id, 'tienda_id' => $tiendaId],
                        ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
                    );
                }
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
     * GET /api/productos/{id}/variante-combinaciones?tienda_id=X
     * Devuelve todas las combinaciones tapizado×config con stock para un producto en una tienda.
     */
    public function indexCombinaciones(Request $request, int $productoId)
    {
        $tiendaId = $request->query('tienda_id');
        if (!$tiendaId) return response()->json([]);

        $combos = InventarioVarianteCombinacion::where('tienda_id', $tiendaId)
            ->whereHas('config', fn ($q) => $q->where('producto_id', $productoId))
            ->with(['variante', 'config.tipo', 'config.opcion'])
            ->get()
            ->map(fn ($c) => [
                'id'                  => $c->id,
                'variante_id'         => $c->variante_id,
                'config_id'           => $c->config_id,
                'cantidad_disponible' => $c->cantidad_disponible,
                'cantidad_reservada'  => $c->cantidad_reservada,
                'stock_libre'         => max(0, $c->cantidad_disponible - $c->cantidad_reservada),
                'variante' => [
                    'id'           => $c->variante->id,
                    'marca'        => $c->variante->marca,
                    'marca_tela'   => $c->variante->marca_tela,
                    'nombre_color' => $c->variante->nombre_color,
                ],
                'config' => [
                    'id'           => $c->config->id,
                    'opcion_nombre' => $c->config->opcion->nombre,
                    'tipo_nombre'   => $c->config->tipo->nombre,
                    'tipo_variante_id' => $c->config->tipo->id,
                ],
            ]);

        return response()->json($combos->values());
    }

    /**
     * POST /api/inventario/variante-combinaciones/entrada
     * Asigna stock a la combinación tapizado×config. Actualiza también inventario_variantes.
     */
    public function entradaCombinacion(Request $request)
    {
        $data = $request->validate([
            'variante_id' => 'required|exists:producto_variantes,id',
            'config_id'   => 'required|exists:producto_variante_configs,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $config   = ProductoVarianteConfig::with(['tipo', 'opcion'])->findOrFail($data['config_id']);
        $variante = ProductoVariante::findOrFail($data['variante_id']);

        if ((int) $variante->producto_id !== (int) $config->producto_id) {
            abort(422, 'La variante y la configuración deben pertenecer al mismo producto.');
        }

        // Verificar que hay stock asignado a esta opción de variante
        $customInv = InventarioVarianteConfig::where('config_id', $data['config_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        $customDisponible = $customInv?->cantidad_disponible ?? 0;
        if ($customDisponible === 0) {
            abort(422, "No hay stock asignado para '{$config->opcion->nombre}'. Agrega stock primero.");
        }

        // Cuánto ya está asignado a combos para esta opción en esta tienda
        $yaAsignado = InventarioVarianteCombinacion::where('config_id', $data['config_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->sum('cantidad_disponible');

        $sinAsignar = $customDisponible - $yaAsignado;
        if ($data['cantidad'] > $sinAsignar) {
            abort(422, "Solo hay {$sinAsignar} unidad(es) sin asignar a telas para '{$config->opcion->nombre}' (total: {$customDisponible}, asignadas: {$yaAsignado}).");
        }

        DB::transaction(function () use ($data, $variante, $config) {
            // Crear o actualizar la combinación
            $combo = InventarioVarianteCombinacion::firstOrCreate(
                ['variante_id' => $data['variante_id'], 'config_id' => $data['config_id'], 'tienda_id' => $data['tienda_id']],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
            );
            $combo->increment('cantidad_disponible', $data['cantidad']);

            // Mantener el total en inventario_variantes para compatibilidad con surtido/órdenes
            $inv = InventarioVariante::firstOrCreate(
                ['variante_id' => $data['variante_id'], 'tienda_id' => $data['tienda_id']],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
            );
            $inv->increment('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $variante->producto_id,
                'tienda_id'   => $data['tienda_id'],
                'variante_id' => $data['variante_id'],
                'tipo'        => 'entrada',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? "Combo: {$variante->marca_tela} {$variante->nombre_color} · {$config->tipo->nombre}: {$config->opcion->nombre}",
                'usuario_id'  => request()->user()->id,
            ]);
        });

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

    /**
     * POST /api/inventario/variante-configs/salida
     * Quita stock de una opción de variante personalizada.
     * Valida que no quede por debajo del total de combos asignados para esa opción.
     */
    public function salidaConfig(Request $request)
    {
        $data = $request->validate([
            'config_id' => 'required|exists:producto_variante_configs,id',
            'tienda_id' => 'required|exists:tiendas,id',
            'cantidad'  => 'required|integer|min:1',
            'motivo'    => 'nullable|string|max:200',
        ]);

        $config = ProductoVarianteConfig::with(['tipo', 'opcion'])->findOrFail($data['config_id']);

        $user = $request->user();
        if ($user->rol === 'vendedor' && $user->tienda_default_id != $data['tienda_id']) {
            abort(403, 'Solo puedes quitar stock de tu propia tienda.');
        }

        $inv = InventarioVarianteConfig::where('config_id', $data['config_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        if (!$inv || $inv->cantidad_disponible === 0) {
            abort(422, 'Esta opción no tiene stock en la tienda seleccionada.');
        }

        // Cuánto ya está asignado a combos para esta opción en esta tienda
        $enCombos = DB::table('inventario_variante_combinaciones')
            ->where('config_id', $data['config_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->sum('cantidad_disponible');

        $nuevaCantidad = $inv->cantidad_disponible - $data['cantidad'];
        if ($nuevaCantidad < $enCombos) {
            $puedeQuitar = $inv->cantidad_disponible - (int) $enCombos;
            abort(422, "Hay {$enCombos} unidad(es) asignadas a combinaciones (tela×variante). Solo puedes quitar hasta {$puedeQuitar}.");
        }

        $inv->decrement('cantidad_disponible', $data['cantidad']);

        InventarioMovimiento::create([
            'producto_id' => $config->producto_id,
            'tienda_id'   => $data['tienda_id'],
            'tipo'        => 'salida',
            'cantidad'    => $data['cantidad'],
            'motivo'      => $data['motivo'] ?? "Ajuste variante {$config->tipo->nombre}: {$config->opcion->nombre}",
            'usuario_id'  => $request->user()->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/inventario/variante-combinaciones/salida
     * Quita stock de una combinación (tela × variante personalizada).
     * También reduce el total en inventario_variantes para mantener consistencia.
     */
    public function salidaCombinacion(Request $request)
    {
        $data = $request->validate([
            'variante_id' => 'required|exists:producto_variantes,id',
            'config_id'   => 'required|exists:producto_variante_configs,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $config   = ProductoVarianteConfig::with(['tipo', 'opcion'])->findOrFail($data['config_id']);
        $variante = ProductoVariante::findOrFail($data['variante_id']);

        $user = $request->user();
        if ($user->rol === 'vendedor' && $user->tienda_default_id != $data['tienda_id']) {
            abort(403, 'Solo puedes quitar stock de tu propia tienda.');
        }

        $combo = InventarioVarianteCombinacion::where('variante_id', $data['variante_id'])
            ->where('config_id', $data['config_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        if (!$combo || $combo->cantidad_disponible === 0) {
            abort(422, 'No hay stock en esta combinación.');
        }

        $libre = $combo->cantidad_disponible - $combo->cantidad_reservada;
        if ($data['cantidad'] > $libre) {
            abort(422, "Solo hay {$libre} unidad(es) disponibles en esta combinación.");
        }

        DB::transaction(function () use ($data, $combo, $variante, $config) {
            $combo->decrement('cantidad_disponible', $data['cantidad']);

            // Reducir también el total en inventario_variantes
            InventarioVariante::where('variante_id', $data['variante_id'])
                ->where('tienda_id', $data['tienda_id'])
                ->decrement('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $variante->producto_id,
                'tienda_id'   => $data['tienda_id'],
                'variante_id' => $data['variante_id'],
                'tipo'        => 'salida',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? "Ajuste combo: {$variante->marca_tela} {$variante->nombre_color} · {$config->tipo->nombre}: {$config->opcion->nombre}",
                'usuario_id'  => request()->user()->id,
            ]);
        });

        return response()->json(['ok' => true]);
    }
}
