<?php

namespace App\Http\Controllers;

use App\Events\InventarioActualizado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    /**
     * GET /api/inventario?tienda_id=1&search=sofa
     * GET /api/inventario?tienda_id=todas&search=sofa
     *
     * Devuelve inventario de una tienda o de todas las tiendas (agrupado).
     */
    public function index(Request $request)
    {
        $request->validate([
            'tienda_id' => 'required',
        ]);

        $tiendaId = $request->query('tienda_id');
        $search   = $request->query('search');
        $categoria = $request->query('categoria');

        if ($tiendaId === 'todas') {
            return $this->inventarioTodas($search, $categoria);
        }

        $request->validate([
            'tienda_id' => 'exists:tiendas,id',
        ]);

        $tid = (int) $tiendaId;

        $query = DB::table('productos')
            ->leftJoin('inventario', function ($join) use ($tid) {
                $join->on('inventario.producto_id', '=', 'productos.id')
                     ->where('inventario.tienda_id', '=', $tid);
            })
            ->where('productos.activo', true)
            ->select(
                DB::raw('COALESCE(inventario.id, 0) as id'),
                'productos.id as producto_id',
                DB::raw("{$tid} as tienda_id"),
                DB::raw('COALESCE(inventario.cantidad_disponible, 0) as cantidad_disponible'),
                DB::raw('COALESCE(inventario.cantidad_reservada, 0) as cantidad_reservada'),
                DB::raw('COALESCE(inventario.stock_minimo, 0) as stock_minimo'),
                'productos.nombre as prod_nombre',
                'productos.categoria as prod_categoria',
                'productos.precio_base as prod_precio_base',
                'productos.foto_url as prod_foto_url',
                'productos.personalizable as prod_personalizable',
                'productos.es_tapizado as prod_es_tapizado',
                'productos.tiene_tallas as prod_tiene_tallas',
                'productos.descripcion as prod_descripcion',
                'productos.medidas as prod_medidas',
                'productos.material as prod_material',
            )
            ->orderBy('productos.nombre');

        if ($search) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('productos.nombre', 'like', $term)
                  ->orWhere('productos.categoria', 'like', $term);
            });
        }

        if ($categoria) {
            $query->where('productos.categoria', $categoria);
        }

        return response()->json($query->paginate(20)->through(function ($inv) {
            $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
            $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;
            $inv->producto = (object) [
                'id'             => $inv->producto_id,
                'nombre'         => $inv->prod_nombre,
                'categoria'      => $inv->prod_categoria,
                'precio_base'    => (float) $inv->prod_precio_base,
                'foto_url'       => $inv->prod_foto_url,
                'personalizable' => (bool) $inv->prod_personalizable,
                'es_tapizado'    => (bool) $inv->prod_es_tapizado,
                'tiene_tallas'   => (bool) $inv->prod_tiene_tallas,
                'descripcion'    => $inv->prod_descripcion,
                'medidas'        => $inv->prod_medidas,
                'material'       => $inv->prod_material,
                'activo'         => true,
            ];
            return $inv;
        }));
    }

    /**
     * Devuelve inventario agrupado por producto de TODAS las tiendas.
     */
    private function inventarioTodas($search = null, $categoria = null)
    {
        $query = DB::table('productos')
            ->leftJoin('inventario', 'inventario.producto_id', '=', 'productos.id')
            ->where('productos.activo', true)
            ->select(
                'productos.id as producto_id',
                'productos.nombre',
                'productos.categoria',
                'productos.precio_base',
                'productos.foto_url',
                'productos.personalizable',
                'productos.es_tapizado',
                'productos.tiene_tallas',
                'productos.descripcion',
                'productos.medidas',
                'productos.material',
                DB::raw('COALESCE(SUM(inventario.cantidad_disponible), 0) as cantidad_disponible'),
                DB::raw('COALESCE(SUM(inventario.cantidad_reservada), 0) as cantidad_reservada'),
                DB::raw('COUNT(DISTINCT inventario.tienda_id) as tiendas_count'),
            )
            ->groupBy(
                'productos.id',
                'productos.nombre',
                'productos.categoria',
                'productos.precio_base',
                'productos.foto_url',
                'productos.personalizable',
                'productos.es_tapizado',
                'productos.tiene_tallas',
                'productos.descripcion',
                'productos.medidas',
                'productos.material',
            );

        if ($search) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('productos.nombre', 'like', $term)
                  ->orWhere('productos.categoria', 'like', $term);
            });
        }

        if ($categoria) {
            $query->where('productos.categoria', $categoria);
        }

        return response()->json($query
            ->orderBy('productos.nombre')
            ->paginate(20)
            ->through(function ($inv) {
                $disp = (int) $inv->cantidad_disponible;
                $res  = (int) $inv->cantidad_reservada;
                return (object) [
                    'id'                  => $inv->producto_id,
                    'producto_id'         => $inv->producto_id,
                    'cantidad_disponible' => $disp,
                    'cantidad_reservada'  => $res,
                    'stock_libre'         => $disp - $res,
                    'stock_minimo'        => 0,
                    'bajo_stock'          => false,
                    'tiendas_count'       => (int) $inv->tiendas_count,
                    'producto'            => (object) [
                        'id'             => $inv->producto_id,
                        'nombre'         => $inv->nombre,
                        'categoria'      => $inv->categoria,
                        'precio_base'    => (float) $inv->precio_base,
                        'foto_url'       => $inv->foto_url,
                        'personalizable' => (bool) $inv->personalizable,
                        'es_tapizado'    => (bool) $inv->es_tapizado,
                        'tiene_tallas'   => (bool) $inv->tiene_tallas,
                        'descripcion'    => $inv->descripcion,
                        'medidas'        => $inv->medidas,
                        'material'       => $inv->material,
                        'activo'         => true,
                    ],
                ];
            }));
    }

    /**
     * POST /api/inventario/entrada
     * Agrega stock a una tienda o a todas las tiendas donde existe el producto.
     */
    public function entrada(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tienda_id'   => 'required',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        if ($data['tienda_id'] === 'todas') {
            if ($request->user()->rol !== 'supervisor') {
                abort(403, 'Solo los supervisores pueden agregar stock a todas las tiendas.');
            }
            return $this->entradaTodas($data, $request);
        }

        $request->validate([
            'tienda_id' => 'exists:tiendas,id',
        ]);

        $inv = DB::transaction(function () use ($data, $request) {
            $inv = Inventario::firstOrCreate(
                ['producto_id' => $data['producto_id'], 'tienda_id' => $data['tienda_id']],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
            );

            $inv->increment('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $data['tienda_id'],
                'tipo'        => 'entrada',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Entrada manual',
                'usuario_id'  => $request->user()->id,
            ]);

            return $inv;
        });

        event(new InventarioActualizado((int) $data['tienda_id'], (int) $data['producto_id'], 'entrada'));

        $inv->load('producto:id,nombre,categoria');
        $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
        $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;

        return response()->json($inv, 201);
    }

    /**
     * Agrega stock a TODAS las tiendas donde existe el producto.
     */
    private function entradaTodas($data, $request)
    {
        $inventario = Inventario::with('tienda:id,nombre')->where('producto_id', $data['producto_id'])->get();

        if ($inventario->isEmpty()) {
            abort(422, 'El producto no existe en ninguna tienda.');
        }

        $motivo = $data['motivo'] ?? 'Entrada manual (todas las tiendas)';
        $usuarioId = $request->user()->id;

        DB::transaction(function () use ($inventario, $data, $motivo, $usuarioId) {
            foreach ($inventario as $inv) {
                Inventario::where('id', $inv->id)
                    ->increment('cantidad_disponible', $data['cantidad']);

                $tiendaNombre = $inv->tienda ? $inv->tienda->nombre : "Tienda #{$inv->tienda_id}";

                InventarioMovimiento::create([
                    'producto_id' => $data['producto_id'],
                    'tienda_id'   => $inv->tienda_id,
                    'tipo'        => 'entrada',
                    'cantidad'    => $data['cantidad'],
                    'motivo'      => "{$motivo} — {$tiendaNombre}",
                    'usuario_id'  => $usuarioId,
                ]);
            }
        });

        $producto = \App\Models\Producto::find($data['producto_id']);

        return response()->json([
            'producto_id'       => $data['producto_id'],
            'producto'          => (object) [
                'id'          => $producto->id,
                'nombre'      => $producto->nombre,
                'categoria'   => $producto->categoria,
                'precio_base' => $producto->precio_base,
            ],
            'cantidad_disponible' => $inventario->sum('cantidad_disponible') + ($data['cantidad'] * $inventario->count()),
            'cantidad_reservada'  => $inventario->sum('cantidad_reservada'),
            'stock_libre'         => $inventario->sum(function ($i) {
                return $i->cantidad_disponible - $i->cantidad_reservada;
            }) + ($data['cantidad'] * $inventario->count()),
            'stock_minimo'        => 0,
            'bajo_stock'          => false,
            'tiendas_count'       => $inventario->count(),
            'tiendas_afectadas'   => $inventario->count(),
        ], 201);
    }

    /**
     * POST /api/inventario/salida
     * Quita stock disponible de una tienda (corrección manual).
     */
    public function salida(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $inv = DB::transaction(function () use ($data, $request) {
            $inv = Inventario::where('producto_id', $data['producto_id'])
                ->where('tienda_id', $data['tienda_id'])
                ->lockForUpdate()
                ->first();

            if (!$inv) {
                throw new \RuntimeException('Este producto no tiene inventario en la tienda seleccionada.');
            }

            $libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
            if ($data['cantidad'] > $libre) {
                throw new \RuntimeException(
                    "No se puede quitar {$data['cantidad']} unidades. Stock libre: {$libre}."
                );
            }

            $inv->decrement('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $data['tienda_id'],
                'tipo'        => 'salida',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Ajuste manual',
                'usuario_id'  => $request->user()->id,
            ]);

            return $inv->fresh();
        });

        event(new InventarioActualizado((int) $data['tienda_id'], (int) $data['producto_id'], 'salida'));

        $inv->load('producto:id,nombre,categoria');
        $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
        $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;

        return response()->json($inv, 201);
    }

    public function movimientos(Request $request, int $productoId)
    {
        $tiendaId = $request->user()->tienda_default_id;

        if ($request->user()->rol === 'supervisor' && $request->query('tienda_id')) {
            $tiendaId = (int) $request->query('tienda_id');
        }

        $movimientos = InventarioMovimiento::with('usuario:id,nombre', 'variante:id,marca,marca_tela,nombre_color')
            ->where('producto_id', $productoId)
            ->where('tienda_id', $tiendaId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($movimientos);
    }
}
