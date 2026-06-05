<?php

namespace App\Http\Controllers;

use App\Events\SurtidoAceptado;
use App\Events\SurtidoEnviado;
use App\Events\SurtidoRechazado;
use App\Jobs\EnviarSurtidoProgramado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\ProductoVariante;
use App\Models\Surtido;
use App\Models\SurtidoItem;
use App\Models\SurtidoTienda;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SurtidoController extends Controller
{
    /**
     * POST /api/inventario/surtir
     * Supervisor crea un surtido y notifica a los vendedores validadores.
     */
    public function crear(Request $request)
    {
        $data = $request->validate([
            'notas'                                     => 'nullable|string|max:1000',
            'fuente_fabrica'                            => 'boolean',
            'programado_para'                           => 'nullable|date|after:now',
            'tiendas'                                   => 'required|array|min:1',
            'tiendas.*.tienda_id'                       => 'required|exists:tiendas,id',
            'tiendas.*.vendedor_validador_id'            => 'required|exists:usuarios,id',
            'tiendas.*.items'                           => 'required|array|min:1',
            'tiendas.*.items.*.producto_id'             => 'required|exists:productos,id',
            'tiendas.*.items.*.cantidad'                => 'required|integer|min:1',
            'tiendas.*.items.*.especificaciones'        => 'nullable|array',
        ]);

        $supervisor     = $request->user();
        $programadoPara = isset($data['programado_para']) ? \Carbon\Carbon::parse($data['programado_para']) : null;
        $desdeFabrica   = $request->boolean('fuente_fabrica');
        $fabricaId      = $desdeFabrica ? Tienda::where('es_fabrica', true)->value('id') : null;

        $surtido = DB::transaction(function () use ($data, $supervisor, $programadoPara, $desdeFabrica, $fabricaId) {
            $surtido = Surtido::create([
                'supervisor_id'   => $supervisor->id,
                'notas'           => $data['notas'] ?? null,
                'fuente_fabrica'  => $desdeFabrica,
                'estado'          => $programadoPara ? 'programado' : 'enviado',
                'programado_para' => $programadoPara,
            ]);

            // Si viene de fábrica, reservar stock en fábrica para cada producto
            if ($desdeFabrica && $fabricaId) {
                $productosUnicos = collect($data['tiendas'])
                    ->flatMap(fn($t) => $t['items'])
                    ->groupBy('producto_id')
                    ->map(fn($items) => $items->sum('cantidad'));

                foreach ($productosUnicos as $productoId => $cantTotal) {
                    $inv = Inventario::where('producto_id', $productoId)
                        ->where('tienda_id', $fabricaId)
                        ->lockForUpdate()->first();

                    if (!$inv || ($inv->cantidad_disponible - $inv->cantidad_reservada) < $cantTotal) {
                        abort(422, "Stock insuficiente en fábrica para el producto #{$productoId}.");
                    }
                    $inv->increment('cantidad_reservada', $cantTotal);
                }
            }

            foreach ($data['tiendas'] as $tiendaData) {
                $st = SurtidoTienda::create([
                    'surtido_id'           => $surtido->id,
                    'tienda_id'            => $tiendaData['tienda_id'],
                    'vendedor_validador_id' => $tiendaData['vendedor_validador_id'],
                    'estado'               => 'pendiente',
                ]);

                foreach ($tiendaData['items'] as $item) {
                    SurtidoItem::create([
                        'surtido_tienda_id' => $st->id,
                        'producto_id'       => $item['producto_id'],
                        'cantidad'          => $item['cantidad'],
                        'especificaciones'  => $item['especificaciones'] ?? null,
                    ]);
                }
            }

            return $surtido;
        });

        $surtido->load('tiendas.vendedorValidador:id,nombre', 'tiendas.tienda:id,nombre', 'tiendas.items.producto:id,nombre');

        if ($programadoPara) {
            // Despachar el job con delay para que se ejecute en el momento programado
            EnviarSurtidoProgramado::dispatch($surtido->id)->delay($programadoPara);
        } else {
            // Notificar de inmediato a cada vendedor validador
            foreach ($surtido->tiendas as $st) {
                $cantidadProductos = $st->items->count();

                try {
                    event(new SurtidoEnviado(
                        $surtido->id,
                        $st->vendedor_validador_id,
                        $supervisor->nombre,
                        $cantidadProductos,
                    ));
                } catch (\Throwable) {}

                NotificacionService::crear(
                    'surtido_enviado',
                    'Surtido pendiente de validación',
                    "{$supervisor->nombre} envió {$cantidadProductos} producto(s) a tu tienda. Valida la recepción.",
                    ['surtido_id' => $surtido->id],
                    $st->vendedor_validador_id,
                );
            }
        }

        return response()->json($surtido, 201);
    }

    /**
     * GET /api/inventario/surtidos
     * Historial de surtidos — solo supervisor.
     */
    public function index(Request $request)
    {
        $query = Surtido::with([
            'supervisor:id,nombre',
            'tiendas.tienda:id,nombre',
            'tiendas.vendedorValidador:id,nombre',
            'tiendas.items.producto:id,nombre',
        ]);

        if ($v = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $v);
        }
        if ($v = $request->query('estado')) {
            $query->where('estado', $v);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * GET /api/inventario/surtidos/pendientes
     * Surtidos pendientes de validación para el vendedor autenticado.
     */
    public function pendientes(Request $request)
    {
        $usuario = $request->user();

        $pendientes = SurtidoTienda::with([
            'surtido.supervisor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
        ])->where('vendedor_validador_id', $usuario->id)
            ->where('estado', 'pendiente')
            ->orderByDesc('id')
            ->get();

        return response()->json($pendientes);
    }

    /**
     * GET /api/inventario/surtidos/{id}
     * Detalle de un surtido.
     */
    public function show(int $id)
    {
        $surtido = Surtido::with([
            'supervisor:id,nombre',
            'tiendas.tienda:id,nombre',
            'tiendas.vendedorValidador:id,nombre',
            'tiendas.items.producto:id,nombre,categoria,foto_url',
        ])->findOrFail($id);

        return response()->json($surtido);
    }

    /**
     * PATCH /api/inventario/surtido-tiendas/{id}/aceptar
     * Vendedor acepta el surtido. Body opcional: items=[{id, cantidad_aceptada}] para aceptación parcial.
     */
    public function aceptar(Request $request, int $id)
    {
        $usuario = $request->user();

        $st = SurtidoTienda::with(['surtido.supervisor:id,nombre', 'tienda:id,nombre', 'items'])->findOrFail($id);

        if ($st->vendedor_validador_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($st->estado !== 'pendiente') {
            return response()->json(['message' => 'Este surtido ya fue respondido.'], 422);
        }

        // Mapa item_id → cantidad_aceptada (vacío = aceptar todo completo)
        $cantidadesMap = collect($request->input('items', []))
            ->keyBy('id')
            ->map(fn($i) => (int) $i['cantidad_aceptada']);

        $fabricaId = $st->surtido->fuente_fabrica
            ? Tienda::where('es_fabrica', true)->value('id')
            : null;

        DB::transaction(function () use ($st, $usuario, $fabricaId, $cantidadesMap) {
            foreach ($st->items as $item) {
                $cantAceptada = $cantidadesMap->has($item->id)
                    ? min($cantidadesMap[$item->id], $item->cantidad)
                    : $item->cantidad;

                $item->update(['cantidad_aceptada' => $cantAceptada]);

                // Fábrica: siempre liberar la reserva completa; solo descontar disponible por lo aceptado
                if ($fabricaId) {
                    $invFab = Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $fabricaId)
                        ->first();
                    if ($invFab) {
                        if ($cantAceptada > 0) {
                            $invFab->decrement('cantidad_disponible', $cantAceptada);
                        }
                        // Liberar reserva completa independientemente de cuánto se aceptó
                        if ($invFab->cantidad_reservada >= $item->cantidad) {
                            $invFab->decrement('cantidad_reservada', $item->cantidad);
                        }
                    }
                    // Variante tapizado — descontar inventario_variantes de fábrica solo por lo aceptado
                    if ($cantAceptada > 0) {
                        $esp = $item->especificaciones;
                        if ($esp && !empty($esp['marca']) && !empty($esp['tela']) && !empty($esp['color'])) {
                            $variante = ProductoVariante::where('producto_id', $item->producto_id)->get()
                                ->first(fn($v) =>
                                    mb_strtolower(trim($v->marca ?? '')) === mb_strtolower(trim($esp['marca'])) &&
                                    mb_strtolower(trim($v->marca_tela))  === mb_strtolower(trim($esp['tela']))  &&
                                    mb_strtolower(trim($v->nombre_color)) === mb_strtolower(trim($esp['color']))
                                );
                            if ($variante) {
                                InventarioVariante::where('variante_id', $variante->id)
                                    ->where('tienda_id', $fabricaId)
                                    ->decrement('cantidad_disponible', $cantAceptada);
                            }
                        }
                    }
                }

                if ($cantAceptada <= 0) continue;

                $inv = Inventario::firstOrCreate(
                    ['producto_id' => $item->producto_id, 'tienda_id' => $st->tienda_id],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
                );
                $inv->increment('cantidad_disponible', $cantAceptada);

                $varianteId = null;
                $esp = $item->especificaciones;

                if ($esp && !empty($esp['marca']) && !empty($esp['tela']) && !empty($esp['color'])) {
                    $marca = trim($esp['marca']);
                    $tela  = trim($esp['tela']);
                    $color = trim($esp['color']);

                    $variante = ProductoVariante::where('producto_id', $item->producto_id)
                        ->get()
                        ->first(function ($v) use ($marca, $tela, $color) {
                            return mb_strtolower(trim($v->marca ?? '')) === mb_strtolower($marca)
                                && mb_strtolower(trim($v->marca_tela)) === mb_strtolower($tela)
                                && mb_strtolower(trim($v->nombre_color)) === mb_strtolower($color);
                        });

                    if (!$variante) {
                        $variante = ProductoVariante::create([
                            'producto_id'  => $item->producto_id,
                            'marca'        => $marca,
                            'marca_tela'   => $tela,
                            'nombre_color' => $color,
                            'activo'       => true,
                        ]);
                        $tiendaIds = Inventario::where('producto_id', $item->producto_id)->pluck('tienda_id');
                        foreach ($tiendaIds as $tid) {
                            InventarioVariante::firstOrCreate(
                                ['variante_id' => $variante->id, 'tienda_id' => $tid],
                                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
                            );
                        }
                    }

                    $varianteId = $variante->id;
                    $invVar = InventarioVariante::firstOrCreate(
                        ['variante_id' => $varianteId, 'tienda_id' => $st->tienda_id],
                        ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
                    );
                    $invVar->increment('cantidad_disponible', $cantAceptada);
                }

                InventarioMovimiento::create([
                    'producto_id'  => $item->producto_id,
                    'tienda_id'    => $st->tienda_id,
                    'variante_id'  => $varianteId,
                    'tipo'         => 'entrada',
                    'cantidad'     => $cantAceptada,
                    'motivo'       => 'Surtido #' . $st->surtido_id,
                    'usuario_id'   => $usuario->id,
                ]);
            }

            $st->update([
                'estado'        => 'aceptado',
                'respondido_at' => now(),
            ]);

            $this->recalcularEstadoSurtido($st->surtido_id);
        });

        $supervisor = $st->surtido->supervisor;

        $aceptadoParcial = $cantidadesMap->isNotEmpty() && $st->items->some(
            fn($i) => $cantidadesMap->has($i->id) && $cantidadesMap[$i->id] < $i->cantidad
        );

        try {
            event(new SurtidoAceptado(
                $st->surtido_id,
                $supervisor->id,
                $st->tienda->nombre,
                $usuario->nombre,
            ));
        } catch (\Throwable) {}

        NotificacionService::crear(
            'surtido_aceptado',
            $aceptadoParcial ? 'Surtido aceptado parcialmente' : 'Surtido aceptado',
            $aceptadoParcial
                ? "{$st->tienda->nombre} aceptó parcialmente el surtido #{$st->surtido_id}. Algunos items llegaron con menos cantidad."
                : "{$st->tienda->nombre} confirmó la recepción del surtido #{$st->surtido_id} (validado por {$usuario->nombre})",
            ['surtido_id' => $st->surtido_id],
            $supervisor->id,
        );

        return response()->json($st->fresh('tienda:id,nombre', 'vendedorValidador:id,nombre', 'items.producto:id,nombre'));
    }

    /**
     * PATCH /api/inventario/surtido-tiendas/{id}/rechazar
     * Vendedor rechaza el surtido.
     */
    public function rechazar(Request $request, int $id)
    {
        $data    = $request->validate(['notas_vendedor' => 'nullable|string|max:500']);
        $usuario = $request->user();

        $st = SurtidoTienda::with(['surtido.supervisor:id,nombre', 'surtido.tiendas', 'tienda:id,nombre', 'items'])->findOrFail($id);

        if ($st->vendedor_validador_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($st->estado !== 'pendiente') {
            return response()->json(['message' => 'Este surtido ya fue respondido.'], 422);
        }

        // Si viene de fábrica, liberar reserva solo si TODAS las tiendas rechazan
        if ($st->surtido->fuente_fabrica) {
            $fabricaId = Tienda::where('es_fabrica', true)->value('id');
            if ($fabricaId) {
                DB::transaction(function () use ($st, $fabricaId) {
                    foreach ($st->items as $item) {
                        $invFab = Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $fabricaId)->first();
                        if ($invFab && $invFab->cantidad_reservada >= $item->cantidad) {
                            $invFab->decrement('cantidad_reservada', $item->cantidad);
                        }
                    }
                });
            }
        }

        $st->update([
            'estado'          => 'rechazado',
            'notas_vendedor'  => $data['notas_vendedor'] ?? null,
            'respondido_at'   => now(),
        ]);

        $this->recalcularEstadoSurtido($st->surtido_id);

        $supervisor = $st->surtido->supervisor;

        try {
            event(new SurtidoRechazado(
                $st->surtido_id,
                $supervisor->id,
                $st->tienda->nombre,
                $usuario->nombre,
                $data['notas_vendedor'] ?? null,
            ));
        } catch (\Throwable) {}

        NotificacionService::crear(
            'surtido_rechazado',
            'Surtido rechazado',
            "{$st->tienda->nombre} rechazó el surtido #{$st->surtido_id}" . ($data['notas_vendedor'] ? ": {$data['notas_vendedor']}" : ''),
            ['surtido_id' => $st->surtido_id],
            $supervisor->id,
        );

        return response()->json($st);
    }

    /**
     * GET /api/inventario/vendedores-tienda/{tiendaId}
     * Todos los vendedores activos. Los de esa tienda aparecen primero.
     * Se devuelven todos para que el supervisor pueda asignar cualquiera,
     * independientemente de si tienen tienda_default_id configurada.
     */
    public function vendedoresTienda(int $tiendaId)
    {
        $vendedores = Usuario::with('tiendaDefault:id,nombre')
            ->where('rol', 'vendedor')
            ->where('activo', true)
            ->orderByRaw('CASE WHEN tienda_default_id = ? THEN 0 ELSE 1 END', [$tiendaId])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'email', 'tienda_default_id']);

        return response()->json($vendedores);
    }

    /**
     * GET /api/inventario/recomendaciones
     *
     * Tres fuentes de alerta:
     *  1. Sin stock   : cantidad_disponible <= 0
     *  2. Bajo stock  : cantidad_disponible <= GREATEST(stock_minimo, 2)   (1-2 uds o bajo mínimo)
     *  3. Top ventas  : top-sellers de la tienda con < 14 días de cobertura
     */
    public function recomendaciones(Request $request)
    {
        // ── 1 & 2. Stock físico bajo (0, 1, 2 o ≤ stock_minimo) ─────────────
        $bajoStock = DB::table('inventario as inv')
            ->join('productos as p', 'p.id', '=', 'inv.producto_id')
            ->join('tiendas as t',   't.id', '=', 'inv.tienda_id')
            ->where('p.activo', true)
            ->whereRaw('inv.cantidad_disponible <= GREATEST(COALESCE(inv.stock_minimo, 0), 2)')
            ->selectRaw("
                t.id            AS tienda_id,
                t.nombre        AS tienda_nombre,
                p.id            AS producto_id,
                p.nombre        AS producto_nombre,
                p.categoria,
                p.foto_url,
                inv.cantidad_disponible                           AS stock_actual,
                inv.cantidad_disponible - inv.cantidad_reservada AS stock_libre,
                inv.stock_minimo
            ")
            ->get()
            ->keyBy(fn($r) => "{$r->tienda_id}_{$r->producto_id}");

        // ── 3. Top ventas últimos 30 días con cobertura < 14 días ────────────
        $topVentas = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->where('p.activo', true)
            ->whereNotIn('o.estado', ['cancelado'])
            ->where('o.created_at', '>=', now()->subDays(30))
            ->selectRaw("
                o.tienda_id,
                t.nombre   AS tienda_nombre,
                oi.producto_id,
                p.nombre   AS producto_nombre,
                p.categoria,
                p.foto_url,
                SUM(oi.cantidad) AS ventas_mes
            ")
            ->groupBy('o.tienda_id', 't.nombre', 'oi.producto_id', 'p.nombre', 'p.categoria', 'p.foto_url')
            ->having(DB::raw('SUM(oi.cantidad)'), '>=', 3)   // al menos 3 ventas en el mes
            ->orderByDesc(DB::raw('SUM(oi.cantidad)'))
            ->get();

        // Inventario actual para los top-ventas
        $tvTiendas   = $topVentas->pluck('tienda_id')->unique()->values();
        $tvProductos = $topVentas->pluck('producto_id')->unique()->values();

        $invTopVentas = collect();
        if ($tvTiendas->isNotEmpty()) {
            $invTopVentas = DB::table('inventario')
                ->whereIn('tienda_id',   $tvTiendas)
                ->whereIn('producto_id', $tvProductos)
                ->get()
                ->keyBy(fn($r) => "{$r->tienda_id}_{$r->producto_id}");
        }

        // Agregar top-ventas que no ya estén en bajo stock y tienen < 14 días de cobertura
        $topVentasRiesgo = collect();
        foreach ($topVentas as $v) {
            $key        = "{$v->tienda_id}_{$v->producto_id}";
            if ($bajoStock->has($key)) continue;   // ya cubierto

            $inv        = $invTopVentas->get($key);
            $stock      = $inv ? (int) $inv->cantidad_disponible : 0;
            $cobertura  = $v->ventas_mes > 0 ? round($stock / ($v->ventas_mes / 30)) : 999;

            if ($cobertura < 14) {
                $topVentasRiesgo->push((object) [
                    'tienda_id'       => $v->tienda_id,
                    'tienda_nombre'   => $v->tienda_nombre,
                    'producto_id'     => $v->producto_id,
                    'producto_nombre' => $v->producto_nombre,
                    'categoria'       => $v->categoria,
                    'foto_url'        => $v->foto_url,
                    'stock_actual'    => $stock,
                    'stock_libre'     => $inv ? $inv->cantidad_disponible - $inv->cantidad_reservada : 0,
                    'stock_minimo'    => $inv?->stock_minimo ?? 0,
                    'ventas_mes'      => (int) $v->ventas_mes,
                    'cobertura_dias'  => $cobertura,
                    'motivo'          => 'top_ventas',
                ]);
            }
        }

        // ── Enriquecer bajo stock con ventas ─────────────────────────────────
        $ventasBajo = collect();
        if ($bajoStock->isNotEmpty()) {
            $ventasBajo = DB::table('orden_items as oi')
                ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
                ->whereIn('oi.producto_id', $bajoStock->pluck('producto_id')->unique())
                ->whereIn('o.tienda_id',    $bajoStock->pluck('tienda_id')->unique())
                ->whereNotIn('o.estado', ['cancelado'])
                ->where('o.created_at', '>=', now()->subDays(30))
                ->selectRaw('o.tienda_id, oi.producto_id, SUM(oi.cantidad) AS ventas_mes')
                ->groupBy('o.tienda_id', 'oi.producto_id')
                ->get()
                ->keyBy(fn($r) => "{$r->tienda_id}_{$r->producto_id}");
        }

        $bajoStockEnriquecido = $bajoStock->values()->map(function ($row) use ($ventasBajo) {
            $row->ventas_mes    = (int) ($ventasBajo->get("{$row->tienda_id}_{$row->producto_id}")?->ventas_mes ?? 0);
            $row->cobertura_dias = null;
            $row->motivo        = $row->stock_actual <= 0 ? 'sin_stock' : 'bajo_stock';
            return $row;
        });

        // ── Unir todo y agrupar por tienda ───────────────────────────────────
        $todos = $bajoStockEnriquecido->concat($topVentasRiesgo);

        if ($todos->isEmpty()) {
            return response()->json([]);
        }

        $perPage = min((int) $request->query('per_page', 12), 50);
        $page    = max((int) $request->query('page', 1), 1);

        $grouped = $todos
            ->groupBy('tienda_id')
            ->map(function ($items) use ($perPage, $page) {
                $first  = $items->first();
                // Score: sin_stock=3, bajo_stock=2, top_ventas=1 — luego por ventas_mes
                $sorted = $items->sortByDesc(fn($i) =>
                    (match ($i->motivo) { 'sin_stock' => 30000, 'bajo_stock' => 20000, default => 10000 })
                    + min((int) $i->ventas_mes, 9999)
                )->values();

                $total  = $sorted->count();
                $pagina = $sorted->forPage($page, $perPage)->values();

                return [
                    'tienda_id'     => $first->tienda_id,
                    'tienda_nombre' => $first->tienda_nombre,
                    'sin_stock'     => $items->where('motivo', 'sin_stock')->count(),
                    'bajo_stock'    => $items->where('motivo', 'bajo_stock')->count(),
                    'top_ventas'    => $items->where('motivo', 'top_ventas')->count(),
                    'total'         => $total,
                    'page'          => $page,
                    'last_page'     => (int) ceil($total / $perPage),
                    'per_page'      => $perPage,
                    'productos'     => $pagina,
                ];
            })
            ->values();

        return response()->json($grouped);
    }

    private function recalcularEstadoSurtido(int $surtidoId): void
    {
        $tiendas    = SurtidoTienda::where('surtido_id', $surtidoId)->get();
        $pendientes = $tiendas->where('estado', 'pendiente')->count();
        $rechazados = $tiendas->where('estado', 'rechazado')->count();

        if ($pendientes === 0) {
            $nuevoEstado = $rechazados > 0 ? 'rechazado_parcial' : 'completado';
            Surtido::where('id', $surtidoId)->update(['estado' => $nuevoEstado]);
        }
    }
}
