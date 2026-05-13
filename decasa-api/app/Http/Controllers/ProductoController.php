<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\InventarioVariante;
use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    /**
     * GET /api/productos?search=silla&tienda_id=1
     *
     * Devuelve productos activos. Si se pasa tienda_id, incluye
     * el stock de esa tienda en cada producto (sin N+1).
     */
    public function index(Request $request)
    {
        $query = Producto::where('activo', true);

        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('nombre',    'like', $term)
                  ->orWhere('categoria', 'like', $term);
            });
        }

        $productos = $query->orderBy('categoria')->orderBy('nombre')->get();

        if ($tiendaId = $request->query('tienda_id')) {
            $productoIds = $productos->pluck('id');

            $inventario = Inventario::where('tienda_id', $tiendaId)
                ->whereIn('producto_id', $productoIds)
                ->get()
                ->keyBy('producto_id');

            // Variantes con su stock en esta tienda
            $variantesIds = ProductoVariante::whereIn('producto_id', $productoIds)
                ->where('activo', true)
                ->pluck('id');

            $stockVariantes = InventarioVariante::where('tienda_id', $tiendaId)
                ->whereIn('variante_id', $variantesIds)
                ->get()
                ->keyBy('variante_id');

            $todasVariantes = ProductoVariante::whereIn('producto_id', $productoIds)
                ->where('activo', true)
                ->orderBy('marca_tela')->orderBy('nombre_color')
                ->get()
                ->groupBy('producto_id');

            $productos = $productos->map(function ($p) use ($inventario, $todasVariantes, $stockVariantes) {
                $inv = $inventario->get($p->id);
                $p->stock_disponible = $inv?->cantidad_disponible ?? 0;
                $p->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
                $p->stock_minimo     = $inv?->stock_minimo        ?? 1;

                $variantes = $todasVariantes->get($p->id, collect())->map(function ($v) use ($stockVariantes) {
                    $s = $stockVariantes->get($v->id);
                    $v->stock_disponible = $s?->cantidad_disponible ?? 0;
                    $v->stock_reservado  = $s?->cantidad_reservada  ?? 0;
                    $v->stock_libre      = ($s?->cantidad_disponible ?? 0) - ($s?->cantidad_reservada ?? 0);
                    return $v;
                });

                $p->variantes = $variantes->values();
                return $p;
            });
        }

        return response()->json($productos->values());
    }

    /**
     * GET /api/productos/{id}?tienda_id=1
     */
    public function show(Request $request, int $id)
    {
        $producto = Producto::where('activo', true)->findOrFail($id);

        if ($tiendaId = $request->query('tienda_id')) {
            $inv = Inventario::where('producto_id', $id)
                ->where('tienda_id', $tiendaId)
                ->first();

            $producto->stock_disponible = $inv?->cantidad_disponible ?? 0;
            $producto->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
            $producto->stock_minimo     = $inv?->stock_minimo        ?? 1;
        }

        return response()->json($producto);
    }

    /**
     * POST /api/productos
     *
     * Crea un nuevo producto y sus registros de inventario (en 0) para las
     * tiendas indicadas. Supervisor elige una, varias o todas las tiendas;
     * vendedor siempre queda restringido a su tienda predeterminada.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $rules = [
            'nombre'         => 'required|string|max:150',
            'categoria'      => 'nullable|string|max:80',
            'precio_base'    => 'required|numeric|min:0',
            'personalizable' => 'nullable|boolean',
            'descripcion'    => 'nullable|string',
            'foto_url'       => 'nullable|string|max:255',
            'medidas'        => 'nullable|string|max:200',
            'material'       => 'nullable|string|max:200',
        ];

        if ($user->rol === 'supervisor') {
            $rules['tiendas']   = 'required|array|min:1';
            $rules['tiendas.*'] = 'integer|exists:tiendas,id';
        }

        $data = $request->validate($rules);

        $producto = Producto::create([
            'nombre'         => $data['nombre'],
            'categoria'      => $data['categoria'] ?? null,
            'precio_base'    => $data['precio_base'],
            'personalizable' => $data['personalizable'] ?? false,
            'descripcion'    => $data['descripcion'] ?? null,
            'foto_url'       => $data['foto_url'] ?? null,
            'medidas'        => $data['medidas'] ?? null,
            'material'       => $data['material'] ?? null,
            'activo'         => true,
        ]);

        $tiendaIds = $user->rol === 'vendedor'
            ? [$user->tienda_default_id]
            : $data['tiendas'];

        foreach ($tiendaIds as $tiendaId) {
            Inventario::firstOrCreate(
                ['producto_id' => $producto->id, 'tienda_id' => $tiendaId],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
            );
        }

        return response()->json($producto, 201);
    }

    /**
     * PATCH /api/productos/{id}
     *
     * Permite cambiar solo el precio_base del producto.
     * Accesible por vendedores y supervisores.
     */
    public function update(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $data = $request->validate([
            'precio_base' => 'required|numeric|min:0',
        ]);

        $producto->update(['precio_base' => $data['precio_base']]);

        return response()->json($producto);
    }
}
