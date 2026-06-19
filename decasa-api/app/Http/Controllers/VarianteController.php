<?php

namespace App\Http\Controllers;

use App\Events\InventarioActualizado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\ProductoVariante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VarianteController extends Controller
{
    /**
     * GET /api/productos/{id}/variantes?tienda_id=X
     *
     * Lista variantes de un producto. Si se pasa tienda_id incluye el stock.
     */
    public function index(Request $request, int $productoId)
    {
        $variantes = ProductoVariante::where('producto_id', $productoId)
            ->where('activo', true)
            ->orderBy('marca')
            ->orderBy('marca_tela')
            ->orderBy('nombre_color')
            ->get();

        if ($tiendaId = $request->query('tienda_id')) {
            $stocks = InventarioVariante::where('tienda_id', $tiendaId)
                ->whereIn('variante_id', $variantes->pluck('id'))
                ->get()
                ->keyBy('variante_id');

            // Si no se pide saltar combos, expandir entradas combinadas (tapizado × config)
            if (!$request->query('skip_combos')) {
                $varianteIds = $variantes->pluck('id');
                $combinaciones = DB::table('inventario_variante_combinaciones as ivcom')
                    ->join('producto_variante_configs as pvc', 'ivcom.config_id', '=', 'pvc.id')
                    ->join('tipo_variante_opciones as tvo', 'pvc.opcion_id', '=', 'tvo.id')
                    ->join('tipos_variante as tv', 'pvc.tipo_variante_id', '=', 'tv.id')
                    ->where('ivcom.tienda_id', $tiendaId)
                    ->whereIn('ivcom.variante_id', $varianteIds)
                    ->where('ivcom.cantidad_disponible', '>', 0)
                    ->select([
                        'ivcom.id as combo_id',
                        'ivcom.variante_id',
                        'ivcom.config_id',
                        'ivcom.cantidad_disponible',
                        'ivcom.cantidad_reservada',
                        'tvo.nombre as opcion_nombre',
                        'tv.nombre as tipo_nombre',
                    ])
                    ->get()
                    ->groupBy('variante_id');

                if ($combinaciones->isNotEmpty()) {
                    $result = collect();
                    foreach ($variantes as $v) {
                        $varCombos = $combinaciones->get($v->id);
                        if ($varCombos && $varCombos->count() > 0) {
                            foreach ($varCombos as $combo) {
                                $entry = $v->toArray();
                                $entry['_combo_id']     = $combo->combo_id;
                                $entry['_config_id']    = $combo->config_id;
                                $entry['_config_label'] = $combo->opcion_nombre;
                                $entry['_tipo_nombre']  = $combo->tipo_nombre;
                                $entry['stock_disponible'] = $combo->cantidad_disponible;
                                $entry['stock_reservado']  = $combo->cantidad_reservada;
                                $entry['stock_libre']      = max(0, $combo->cantidad_disponible - $combo->cantidad_reservada);
                                $result->push($entry);
                            }
                        } else {
                            $inv = $stocks->get($v->id);
                            $v->stock_disponible = $inv?->cantidad_disponible ?? 0;
                            $v->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
                            $v->stock_libre      = ($inv?->cantidad_disponible ?? 0) - ($inv?->cantidad_reservada ?? 0);
                            $result->push($v->toArray());
                        }
                    }
                    return response()->json($result->values());
                }
            }

            $variantes = $variantes->map(function ($v) use ($stocks) {
                $inv = $stocks->get($v->id);
                $v->stock_disponible = $inv?->cantidad_disponible ?? 0;
                $v->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
                $v->stock_libre      = ($inv?->cantidad_disponible ?? 0) - ($inv?->cantidad_reservada ?? 0);
                return $v;
            });
        }

        return response()->json($variantes->values());
    }

    /**
     * POST /api/inventario/variantes/salida
     *
     * Quita stock de una variante tapizado. Valida que el resultado no sea
     * menor al total de combos asignados para esa variante en esa tienda.
     */
    public function salida(Request $request)
    {
        $data = $request->validate([
            'variante_id' => 'required|exists:producto_variantes,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $variante = ProductoVariante::findOrFail($data['variante_id']);

        $inv = InventarioVariante::where('variante_id', $data['variante_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        if (!$inv || $inv->cantidad_disponible === 0) {
            abort(422, 'Esta variante no tiene stock en la tienda seleccionada.');
        }

        // Cuánto ya está asignado a combos para esta variante en esta tienda
        $enCombos = DB::table('inventario_variante_combinaciones')
            ->where('variante_id', $data['variante_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->sum('cantidad_disponible');

        $nuevaCantidad = $inv->cantidad_disponible - $data['cantidad'];
        if ($nuevaCantidad < $enCombos) {
            $puedeQuitar = $inv->cantidad_disponible - (int) $enCombos;
            abort(422, "Hay {$enCombos} unidad(es) asignadas a combinaciones (tela×variante). Solo puedes quitar hasta {$puedeQuitar}.");
        }

        $inv->decrement('cantidad_disponible', $data['cantidad']);

        event(new InventarioActualizado((int) $data['tienda_id'], (int) $variante->producto_id, 'salida'));

        InventarioMovimiento::create([
            'producto_id' => $variante->producto_id,
            'tienda_id'   => $data['tienda_id'],
            'variante_id' => $data['variante_id'],
            'tipo'        => 'salida',
            'cantidad'    => $data['cantidad'],
            'motivo'      => $data['motivo'] ?? 'Ajuste variante',
            'usuario_id'  => $request->user()->id,
        ]);

        return response()->json($inv->fresh(), 201);
    }

    /**
     * GET /api/variantes/telas
     *
     * Lista todas las combinaciones de tela/material guardadas en el catálogo.
     * Se usa en el formulario de producto personalizado para elegir una tela existente.
     */
    public function telas()
    {
        $telas = DB::table('producto_variantes')
            ->where('activo', true)
            ->select('marca', 'marca_tela', 'nombre_color')
            ->distinct()
            ->orderBy('marca_tela')
            ->orderBy('nombre_color')
            ->get();

        return response()->json($telas);
    }

    /**
     * POST /api/productos/{id}/variantes
     *
     * Crea una variante y genera su registro de inventario en cada tienda
     * donde el producto ya existe.
     */
    public function store(Request $request, int $productoId)
    {
        $data = $request->validate([
            'marca'           => 'nullable|string|max:100',
            'marca_tela'      => 'required_without:medida|nullable|string|max:100',
            'nombre_color'    => 'required_without:medida|nullable|string|max:100',
            'medida'          => 'nullable|string|max:50',
            'precio_variante' => 'nullable|numeric|min:0',
            'foto_url'        => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        if ($user->rol === 'vendedor') {
            $existeEnTienda = Inventario::where('producto_id', $productoId)
                ->where('tienda_id', $user->tienda_default_id)
                ->exists();
            if (!$existeEnTienda) {
                return response()->json(['message' => 'El producto no existe en tu tienda.'], 403);
            }
        }

        $variante = DB::transaction(function () use ($productoId, $data) {
            $v = ProductoVariante::create([
                'producto_id'     => $productoId,
                'marca'           => $data['marca'] ?? null,
                'marca_tela'      => $data['marca_tela'] ?? null,
                'nombre_color'    => $data['nombre_color'] ?? null,
                'medida'          => $data['medida'] ?? null,
                'precio_variante' => $data['precio_variante'] ?? null,
                'foto_url'        => $data['foto_url'] ?? null,
                'activo'          => true,
            ]);

            // Auto-crear inventario_variantes en cada tienda donde existe el producto
            $tiendaIds = Inventario::where('producto_id', $productoId)->pluck('tienda_id');
            foreach ($tiendaIds as $tiendaId) {
                InventarioVariante::firstOrCreate(
                    ['variante_id' => $v->id, 'tienda_id' => $tiendaId],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
                );
            }

            return $v;
        });

        return response()->json($variante->load('inventarios'), 201);
    }

    /**
     * POST /api/inventario/variantes/entrada
     *
     * Agrega stock a una variante en una tienda específica.
     */
    public function entrada(Request $request)
    {
        $data = $request->validate([
            'variante_id' => 'required|exists:producto_variantes,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $variante = ProductoVariante::findOrFail($data['variante_id']);

        // Verificar que solo supervisor puede agregar stock en tienda ajena
        $user = $request->user();
        if ($user->rol === 'vendedor' && $user->tienda_default_id != $data['tienda_id']) {
            abort(403, 'Solo puedes agregar stock en tu propia tienda.');
        }

        // Validar que las variantes no superen el stock base del producto en esta tienda
        $baseInv = Inventario::where('producto_id', $variante->producto_id)
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        $baseDisponible = $baseInv?->cantidad_disponible ?? 0;
        if ($baseDisponible === 0) {
            abort(422, 'Agrega primero stock base a este producto en esta tienda antes de asignar variantes.');
        }

        $totalAsignado = InventarioVariante::where('tienda_id', $data['tienda_id'])
            ->whereHas('variante', fn ($q) => $q->where('producto_id', $variante->producto_id)->where('activo', true))
            ->sum('cantidad_disponible');

        $sinAsignar = $baseDisponible - $totalAsignado;
        if ($data['cantidad'] > $sinAsignar) {
            abort(422, "Solo hay {$sinAsignar} unidad(es) sin asignar en esta tienda (stock base: {$baseDisponible}, ya asignadas a variantes: {$totalAsignado}).");
        }

        $inv = InventarioVariante::firstOrCreate(
            ['variante_id' => $data['variante_id'], 'tienda_id' => $data['tienda_id']],
            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
        );

        $inv->increment('cantidad_disponible', $data['cantidad']);

        event(new InventarioActualizado((int) $data['tienda_id'], (int) $variante->producto_id, 'entrada'));

        InventarioMovimiento::create([
            'producto_id'  => $variante->producto_id,
            'tienda_id'    => $data['tienda_id'],
            'variante_id'  => $data['variante_id'],
            'tipo'         => 'entrada',
            'cantidad'     => $data['cantidad'],
            'motivo'       => $data['motivo'] ?? "Entrada variante: " . implode(' · ', array_filter([$variante->medida, $variante->marca, $variante->marca_tela, $variante->nombre_color])),
            'usuario_id'   => $user->id,
        ]);

        return response()->json($inv->fresh(), 201);
    }
}
