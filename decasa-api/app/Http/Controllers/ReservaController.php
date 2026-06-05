<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Models\Tienda;
use App\Events\InventarioActualizado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservaController extends Controller
{
    private function fabrica(): Tienda
    {
        return Tienda::where('es_fabrica', true)->firstOrFail();
    }

    /** GET /reserva/info — ID y nombre de la fábrica */
    public function info()
    {
        return response()->json($this->fabrica()->only(['id', 'nombre', 'es_fabrica']));
    }

    /** GET /reserva/inventario — inventario paginado de fábrica (supervisor) */
    public function inventario(Request $request)
    {
        $fabrica = $this->fabrica();
        $search  = $request->query('search', '');
        $page    = (int) $request->query('page', 1);

        $query = DB::table('productos')
            ->leftJoin('inventario', function ($join) use ($fabrica) {
                $join->on('inventario.producto_id', '=', 'productos.id')
                     ->where('inventario.tienda_id', '=', $fabrica->id);
            })
            ->where('productos.activo', true)
            ->select(
                'productos.id as producto_id',
                'productos.nombre as prod_nombre',
                'productos.categoria as prod_categoria',
                'productos.foto_url as prod_foto_url',
                'productos.precio_base as prod_precio_base',
                'productos.es_tapizado as prod_es_tapizado',
                DB::raw('COALESCE(inventario.id, 0) as inv_id'),
                DB::raw('COALESCE(inventario.cantidad_disponible, 0) as cantidad_disponible'),
                DB::raw('COALESCE(inventario.cantidad_reservada, 0) as cantidad_reservada'),
            )
            ->orderBy('productos.nombre');

        if ($search) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('productos.nombre', 'like', $term)
                  ->orWhere('productos.categoria', 'like', $term);
            });
        }

        $paginated = $query->paginate(20, ['*'], 'page', $page);

        $paginated->getCollection()->transform(function ($row) {
            $row->stock_libre = $row->cantidad_disponible - $row->cantidad_reservada;
            $row->bajo_stock  = $row->cantidad_disponible <= 0;
            $row->producto = (object) [
                'id'         => $row->producto_id,
                'nombre'     => $row->prod_nombre,
                'categoria'  => $row->prod_categoria,
                'foto_url'    => $row->prod_foto_url,
                'precio_base' => (float) $row->prod_precio_base,
                'es_tapizado' => (bool) $row->prod_es_tapizado,
            ];
            return $row;
        });

        return response()->json($paginated);
    }

    /** GET /reserva/stock-lote?ids[]=1&ids[]=2 — stock libre por producto (todos los roles) */
    public function stockLote(Request $request)
    {
        $fabrica = $this->fabrica();
        $ids     = array_filter(array_map('intval', (array) $request->query('ids', [])));

        if (empty($ids)) {
            return response()->json([]);
        }

        // Stock a nivel de producto (no-tapizado)
        $rows = Inventario::where('tienda_id', $fabrica->id)
            ->whereIn('producto_id', $ids)
            ->get(['producto_id', 'cantidad_disponible', 'cantidad_reservada']);

        $result = [];
        foreach ($rows as $r) {
            $result[$r->producto_id] = max(0, $r->cantidad_disponible - $r->cantidad_reservada);
        }

        // Stock a nivel de variante (tapizado) — suma libre por producto
        $varianteRows = DB::table('inventario_variantes')
            ->join('producto_variantes', 'producto_variantes.id', '=', 'inventario_variantes.variante_id')
            ->where('inventario_variantes.tienda_id', $fabrica->id)
            ->whereIn('producto_variantes.producto_id', $ids)
            ->where('producto_variantes.activo', true)
            ->groupBy('producto_variantes.producto_id')
            ->selectRaw('producto_variantes.producto_id, SUM(inventario_variantes.cantidad_disponible - inventario_variantes.cantidad_reservada) as stock_libre')
            ->get();

        foreach ($varianteRows as $v) {
            $libre = max(0, (int) $v->stock_libre);
            if ($libre > 0) {
                $result[$v->producto_id] = $libre;
            }
        }

        return response()->json($result);
    }

    /** POST /reserva/entrada — agregar stock a fábrica (supervisor) */
    public function entrada(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $fabrica = $this->fabrica();

        $inv = DB::transaction(function () use ($data, $fabrica, $request) {
            $inv = Inventario::firstOrCreate(
                ['producto_id' => $data['producto_id'], 'tienda_id' => $fabrica->id],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
            );
            $inv->increment('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $fabrica->id,
                'usuario_id'  => $request->user()->id,
                'tipo'        => 'entrada',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Entrada a fábrica',
            ]);

            return $inv->fresh();
        });

        event(new InventarioActualizado($fabrica->id, (int) $data['producto_id'], 'entrada'));

        return response()->json([
            'producto_id'         => $inv->producto_id,
            'cantidad_disponible' => $inv->cantidad_disponible,
            'cantidad_reservada'  => $inv->cantidad_reservada,
            'stock_libre'         => $inv->cantidad_disponible - $inv->cantidad_reservada,
        ]);
    }

    /** POST /reserva/salida — quitar stock de fábrica (supervisor) */
    public function salida(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $fabrica = $this->fabrica();

        $inv = DB::transaction(function () use ($data, $fabrica, $request) {
            $inv = Inventario::where('producto_id', $data['producto_id'])
                ->where('tienda_id', $fabrica->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inv->cantidad_disponible < $data['cantidad']) {
                abort(422, 'No hay suficiente stock disponible en fábrica.');
            }

            $inv->decrement('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $fabrica->id,
                'usuario_id'  => $request->user()->id,
                'tipo'        => 'salida',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Salida de fábrica',
            ]);

            return $inv->fresh();
        });

        event(new InventarioActualizado($fabrica->id, (int) $data['producto_id'], 'salida'));

        return response()->json([
            'producto_id'         => $inv->producto_id,
            'cantidad_disponible' => $inv->cantidad_disponible,
            'cantidad_reservada'  => $inv->cantidad_reservada,
            'stock_libre'         => $inv->cantidad_disponible - $inv->cantidad_reservada,
        ]);
    }

    /** GET /reserva/movimientos/{productoId} — historial de fábrica para un producto */
    public function movimientos(Request $request, int $productoId)
    {
        $fabrica = $this->fabrica();

        $movimientos = InventarioMovimiento::with('usuario:id,nombre')
            ->where('producto_id', $productoId)
            ->where('tienda_id', $fabrica->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($movimientos);
    }
}
