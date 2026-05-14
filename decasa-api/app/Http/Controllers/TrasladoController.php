<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Traslado;
use App\Models\TrasladoItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrasladoController extends Controller
{
    /**
     * GET /api/inventario/traslados/stock-tienda/{tiendaId}
     * Retorna los productos con stock libre > 0 en la tienda origen.
     */
    public function stockTienda(int $tiendaId)
    {
        $stock = DB::table('inventario as inv')
            ->join('productos as p', 'p.id', '=', 'inv.producto_id')
            ->where('inv.tienda_id', $tiendaId)
            ->where('p.activo', true)
            ->where('inv.cantidad_disponible', '>', 0)
            ->selectRaw('
                p.id            AS producto_id,
                p.nombre,
                p.categoria,
                p.foto_url,
                inv.cantidad_disponible,
                inv.cantidad_reservada,
                (inv.cantidad_disponible - inv.cantidad_reservada) AS stock_libre
            ')
            ->having('stock_libre', '>', 0)
            ->orderByDesc('inv.cantidad_disponible')
            ->get();

        return response()->json($stock);
    }

    /**
     * POST /api/inventario/traslados
     * Supervisor crea un traslado: descuenta de origen y suma en destino de inmediato.
     */
    public function crear(Request $request)
    {
        $data = $request->validate([
            'tienda_origen_id'    => 'required|exists:tiendas,id',
            'tienda_destino_id'   => 'required|exists:tiendas,id|different:tienda_origen_id',
            'notas'               => 'nullable|string|max:500',
            'items'               => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad'    => 'required|integer|min:1',
        ]);

        $supervisor = $request->user();

        // Pre-cargar nombres de tienda para los movimientos
        $tiendas = DB::table('tiendas')
            ->whereIn('id', [$data['tienda_origen_id'], $data['tienda_destino_id']])
            ->pluck('nombre', 'id');

        $nombreOrigen  = $tiendas[$data['tienda_origen_id']]  ?? "Tienda #{$data['tienda_origen_id']}";
        $nombreDestino = $tiendas[$data['tienda_destino_id']] ?? "Tienda #{$data['tienda_destino_id']}";

        try {
            $traslado = DB::transaction(function () use ($data, $supervisor, $nombreOrigen, $nombreDestino) {

                // Validar stock libre antes de tocar nada
                foreach ($data['items'] as $item) {
                    $inv = Inventario::where('producto_id', $item['producto_id'])
                        ->where('tienda_id', $data['tienda_origen_id'])
                        ->first();

                    if (! $inv) {
                        $nombre = DB::table('productos')->where('id', $item['producto_id'])->value('nombre');
                        throw new \RuntimeException("\"$nombre\" no tiene inventario en $nombreOrigen.");
                    }

                    $libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
                    if ($libre < $item['cantidad']) {
                        $nombre = DB::table('productos')->where('id', $item['producto_id'])->value('nombre');
                        throw new \RuntimeException(
                            "Stock insuficiente para \"$nombre\" en $nombreOrigen: "
                            . "libre={$libre}, solicitado={$item['cantidad']}."
                        );
                    }
                }

                $traslado = Traslado::create([
                    'supervisor_id'     => $supervisor->id,
                    'tienda_origen_id'  => $data['tienda_origen_id'],
                    'tienda_destino_id' => $data['tienda_destino_id'],
                    'notas'             => $data['notas'] ?? null,
                ]);

                foreach ($data['items'] as $item) {
                    TrasladoItem::create([
                        'traslado_id' => $traslado->id,
                        'producto_id' => $item['producto_id'],
                        'cantidad'    => $item['cantidad'],
                    ]);

                    // Descontar de la tienda origen
                    Inventario::where('producto_id', $item['producto_id'])
                        ->where('tienda_id', $data['tienda_origen_id'])
                        ->decrement('cantidad_disponible', $item['cantidad']);

                    // Sumar en la tienda destino (crear si no existe)
                    $invDest = Inventario::firstOrCreate(
                        ['producto_id' => $item['producto_id'], 'tienda_id' => $data['tienda_destino_id']],
                        ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
                    );
                    $invDest->increment('cantidad_disponible', $item['cantidad']);

                    // Movimientos de inventario
                    InventarioMovimiento::create([
                        'producto_id' => $item['producto_id'],
                        'tienda_id'   => $data['tienda_origen_id'],
                        'tipo'        => 'traslado_salida',
                        'cantidad'    => $item['cantidad'],
                        'motivo'      => "Traslado #{$traslado->id} → $nombreDestino",
                        'usuario_id'  => $supervisor->id,
                    ]);
                    InventarioMovimiento::create([
                        'producto_id' => $item['producto_id'],
                        'tienda_id'   => $data['tienda_destino_id'],
                        'tipo'        => 'traslado_entrada',
                        'cantidad'    => $item['cantidad'],
                        'motivo'      => "Traslado #{$traslado->id} ← $nombreOrigen",
                        'usuario_id'  => $supervisor->id,
                    ]);
                }

                return $traslado;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $traslado->load([
            'supervisor:id,nombre',
            'tiendaOrigen:id,nombre',
            'tiendaDestino:id,nombre',
            'items.producto:id,nombre,categoria',
        ]);

        return response()->json($traslado, 201);
    }

    /**
     * GET /api/inventario/traslados
     * Historial de traslados — solo supervisor.
     */
    public function index()
    {
        $traslados = Traslado::with([
            'supervisor:id,nombre',
            'tiendaOrigen:id,nombre',
            'tiendaDestino:id,nombre',
            'items.producto:id,nombre,categoria',
        ])->orderByDesc('created_at')->paginate(20);

        return response()->json($traslados);
    }
}
