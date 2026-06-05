<?php

namespace App\Http\Controllers;

use App\Jobs\EjecutarTrasladoProgramado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Traslado;
use App\Models\TrasladoItem;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrasladoController extends Controller
{
    private function withRelaciones()
    {
        return [
            'supervisor:id,nombre',
            'vendedorValidador:id,nombre',
            'tiendaOrigen:id,nombre',
            'tiendaDestino:id,nombre',
            'items.producto:id,nombre,categoria',
        ];
    }

    /**
     * GET /api/inventario/traslados/stock-tienda/{tiendaId}
     * Vendedor solo puede consultar su propia tienda.
     */
    public function stockTienda(Request $request, int $tiendaId)
    {
        $user = $request->user();
        if ($user->rol === 'vendedor' && $user->tienda_default_id != $tiendaId) {
            abort(403, 'Solo puedes ver el stock de tu propia tienda.');
        }

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
     * Supervisor: ejecuta inmediatamente (o programa).
     * Vendedor: crea traslado pendiente de validación; solo puede usar su tienda como origen.
     */
    public function crear(Request $request)
    {
        $data = $request->validate([
            'tienda_origen_id'      => 'required|exists:tiendas,id',
            'tienda_destino_id'     => 'required|exists:tiendas,id|different:tienda_origen_id',
            'notas'                 => 'nullable|string|max:500',
            'programado_para'       => 'nullable|date|after:now',
            'vendedor_validador_id' => 'nullable|exists:usuarios,id',
            'items'                 => 'required|array|min:1',
            'items.*.producto_id'   => 'required|exists:productos,id',
            'items.*.cantidad'      => 'required|integer|min:1',
        ]);

        $user      = $request->user();
        $esVendedor = $user->rol === 'vendedor';

        if ($esVendedor) {
            if ($user->tienda_default_id != $data['tienda_origen_id']) {
                abort(403, 'Solo puedes trasladar desde tu propia tienda.');
            }
            if (empty($data['vendedor_validador_id'])) {
                return response()->json(['message' => 'Debes seleccionar un vendedor validador en la tienda destino.'], 422);
            }
        }

        $programadoPara = isset($data['programado_para']) ? \Carbon\Carbon::parse($data['programado_para']) : null;

        $tiendas = DB::table('tiendas')
            ->whereIn('id', [$data['tienda_origen_id'], $data['tienda_destino_id']])
            ->pluck('nombre', 'id');

        $nombreOrigen  = $tiendas[$data['tienda_origen_id']]  ?? "Tienda #{$data['tienda_origen_id']}";
        $nombreDestino = $tiendas[$data['tienda_destino_id']] ?? "Tienda #{$data['tienda_destino_id']}";

        // Vendedores crean traslado pendiente (inventario no se mueve hasta aceptar)
        $ejecutaInmediato = !$esVendedor && !$programadoPara;

        try {
            $traslado = DB::transaction(function () use ($data, $user, $esVendedor, $nombreOrigen, $nombreDestino, $programadoPara, $ejecutaInmediato) {

                if ($ejecutaInmediato) {
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
                }

                if ($esVendedor) {
                    $estado = 'pendiente';
                } elseif ($programadoPara) {
                    $estado = 'programado';
                } else {
                    $estado = 'completado';
                }

                $traslado = Traslado::create([
                    'supervisor_id'         => $user->id,
                    'vendedor_validador_id' => $data['vendedor_validador_id'] ?? null,
                    'tienda_origen_id'      => $data['tienda_origen_id'],
                    'tienda_destino_id'     => $data['tienda_destino_id'],
                    'notas'                 => $data['notas'] ?? null,
                    'programado_para'       => $programadoPara,
                    'estado'                => $estado,
                ]);

                foreach ($data['items'] as $item) {
                    TrasladoItem::create([
                        'traslado_id' => $traslado->id,
                        'producto_id' => $item['producto_id'],
                        'cantidad'    => $item['cantidad'],
                    ]);

                    if ($ejecutaInmediato) {
                        Inventario::where('producto_id', $item['producto_id'])
                            ->where('tienda_id', $data['tienda_origen_id'])
                            ->decrement('cantidad_disponible', $item['cantidad']);

                        $invDest = Inventario::firstOrCreate(
                            ['producto_id' => $item['producto_id'], 'tienda_id' => $data['tienda_destino_id']],
                            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
                        );
                        $invDest->increment('cantidad_disponible', $item['cantidad']);

                        InventarioMovimiento::create([
                            'producto_id' => $item['producto_id'],
                            'tienda_id'   => $data['tienda_origen_id'],
                            'tipo'        => 'traslado_salida',
                            'cantidad'    => $item['cantidad'],
                            'motivo'      => "Traslado #{$traslado->id} → $nombreDestino",
                            'usuario_id'  => $user->id,
                        ]);
                        InventarioMovimiento::create([
                            'producto_id' => $item['producto_id'],
                            'tienda_id'   => $data['tienda_destino_id'],
                            'tipo'        => 'traslado_entrada',
                            'cantidad'    => $item['cantidad'],
                            'motivo'      => "Traslado #{$traslado->id} ← $nombreOrigen",
                            'usuario_id'  => $user->id,
                        ]);
                    }
                }

                return $traslado;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($programadoPara && !$esVendedor) {
            EjecutarTrasladoProgramado::dispatch($traslado->id)->delay($programadoPara);
        }

        $traslado->load($this->withRelaciones());

        // Notificar al validador cuando el vendedor crea un traslado pendiente
        if ($esVendedor && $traslado->vendedor_validador_id) {
            $cantItems = count($data['items']);
            NotificacionService::crear(
                'traslado_pendiente',
                'Traslado pendiente de validación',
                "{$user->nombre} solicita trasladar {$cantItems} producto(s) desde {$nombreOrigen} a {$nombreDestino}. Acepta o rechaza desde tu inventario.",
                ['traslado_id' => $traslado->id],
                $traslado->vendedor_validador_id,
            );
        }

        return response()->json($traslado, 201);
    }

    /**
     * GET /api/inventario/traslados
     * Supervisor: todos. Vendedor: los de su tienda.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $q = Traslado::with($this->withRelaciones())->orderByDesc('created_at');

        if ($user->rol === 'vendedor') {
            $q->where(function ($q) use ($user) {
                $q->where('tienda_origen_id', $user->tienda_default_id)
                  ->orWhere('tienda_destino_id', $user->tienda_default_id);
            });
        }

        return response()->json($q->paginate(20));
    }

    /**
     * GET /api/inventario/traslados/pendientes
     * Traslados pendientes de validación para el vendedor autenticado.
     */
    public function pendientes(Request $request)
    {
        $user = $request->user();

        $traslados = Traslado::with($this->withRelaciones())
            ->where('estado', 'pendiente')
            ->where('vendedor_validador_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($traslados);
    }

    /**
     * PATCH /api/inventario/traslados/{id}/aceptar
     * El validador acepta: mueve el inventario según las cantidades recibidas y marca como completado.
     * Body opcional: items = [{id, cantidad_aceptada}] para aceptación parcial.
     */
    public function aceptar(Request $request, int $id)
    {
        $traslado = Traslado::with('items')->findOrFail($id);
        $user     = $request->user();

        if ($traslado->estado !== 'pendiente') {
            return response()->json(['message' => 'Este traslado ya fue procesado.'], 422);
        }
        if ($traslado->vendedor_validador_id !== $user->id && $user->rol !== 'supervisor') {
            abort(403, 'No tienes permiso para aceptar este traslado.');
        }

        // Mapa item_id → cantidad_aceptada (null = usar cantidad original completa)
        $cantidadesMap = collect($request->input('items', []))
            ->keyBy('id')
            ->map(fn($i) => (int) $i['cantidad_aceptada']);

        $tiendas = DB::table('tiendas')
            ->whereIn('id', [$traslado->tienda_origen_id, $traslado->tienda_destino_id])
            ->pluck('nombre', 'id');

        $nombreOrigen  = $tiendas[$traslado->tienda_origen_id]  ?? '';
        $nombreDestino = $tiendas[$traslado->tienda_destino_id] ?? '';

        try {
            DB::transaction(function () use ($traslado, $user, $cantidadesMap, $nombreOrigen, $nombreDestino) {
                foreach ($traslado->items as $item) {
                    $cantAceptada = $cantidadesMap->has($item->id)
                        ? min($cantidadesMap[$item->id], $item->cantidad)
                        : $item->cantidad;

                    if ($cantAceptada <= 0) {
                        $item->update(['cantidad_aceptada' => 0]);
                        continue;
                    }

                    $inv   = Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $traslado->tienda_origen_id)
                        ->first();
                    $libre = ($inv?->cantidad_disponible ?? 0) - ($inv?->cantidad_reservada ?? 0);
                    if ($libre < $cantAceptada) {
                        $nombre = DB::table('productos')->where('id', $item->producto_id)->value('nombre');
                        throw new \RuntimeException("Stock insuficiente para \"$nombre\" al momento de aceptar.");
                    }

                    $item->update(['cantidad_aceptada' => $cantAceptada]);

                    Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $traslado->tienda_origen_id)
                        ->decrement('cantidad_disponible', $cantAceptada);

                    $invDest = Inventario::firstOrCreate(
                        ['producto_id' => $item->producto_id, 'tienda_id' => $traslado->tienda_destino_id],
                        ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
                    );
                    $invDest->increment('cantidad_disponible', $cantAceptada);

                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $traslado->tienda_origen_id,
                        'tipo'        => 'traslado_salida',
                        'cantidad'    => $cantAceptada,
                        'motivo'      => "Traslado #{$traslado->id} → $nombreDestino (aceptado)",
                        'usuario_id'  => $user->id,
                    ]);
                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $traslado->tienda_destino_id,
                        'tipo'        => 'traslado_entrada',
                        'cantidad'    => $cantAceptada,
                        'motivo'      => "Traslado #{$traslado->id} ← $nombreOrigen (aceptado)",
                        'usuario_id'  => $user->id,
                    ]);
                }

                $traslado->update(['estado' => 'completado']);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $aceptadosParcial = $cantidadesMap->isNotEmpty() && $traslado->items->some(
            fn($i) => $cantidadesMap->has($i->id) && $cantidadesMap[$i->id] < $i->cantidad
        );

        NotificacionService::crear(
            'traslado_aceptado',
            $aceptadosParcial ? 'Traslado aceptado parcialmente' : 'Traslado aceptado',
            $aceptadosParcial
                ? "{$user->nombre} aceptó el traslado #{$traslado->id} parcialmente. Revisa el historial para ver qué items se recibieron."
                : "{$user->nombre} aceptó el traslado #{$traslado->id}. El inventario fue actualizado.",
            ['traslado_id' => $traslado->id],
            $traslado->supervisor_id,
        );

        return response()->json(['message' => 'Traslado aceptado. Inventario actualizado.']);
    }

    /**
     * PATCH /api/inventario/traslados/{id}/rechazar
     * El validador rechaza el traslado (inventario no se mueve).
     */
    public function rechazar(Request $request, int $id)
    {
        $traslado = Traslado::findOrFail($id);
        $user     = $request->user();

        if ($traslado->estado !== 'pendiente') {
            return response()->json(['message' => 'Este traslado ya fue procesado.'], 422);
        }
        if ($traslado->vendedor_validador_id !== $user->id && $user->rol !== 'supervisor') {
            abort(403, 'No tienes permiso para rechazar este traslado.');
        }

        $notas = $request->input('notas');
        $notasActuales = $traslado->notas;
        $nuevasNotas   = $notas
            ? ($notasActuales ? $notasActuales . "\nMotivo rechazo: $notas" : "Motivo rechazo: $notas")
            : $notasActuales;

        $traslado->update(['estado' => 'rechazado', 'notas' => $nuevasNotas]);

        // Notificar al iniciador
        $motivo = $notas ? " Motivo: $notas" : '';
        NotificacionService::crear(
            'traslado_rechazado',
            'Traslado rechazado',
            "{$user->nombre} rechazó el traslado #{$traslado->id}.{$motivo}",
            ['traslado_id' => $traslado->id],
            $traslado->supervisor_id,
        );

        return response()->json(['message' => 'Traslado rechazado.']);
    }
}
