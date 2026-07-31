<?php

namespace App\Http\Controllers;

use App\Events\SurtidoAceptado;
use App\Events\SurtidoEnviado;
use App\Events\SurtidoRechazado;
use App\Jobs\EnviarSurtidoProgramado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Models\InventarioVarianteConfig;
use App\Models\Orden;
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
            'tiendas.*.items.*.variante_id'             => 'nullable|exists:producto_variantes,id',
            'tiendas.*.items.*.combo_config_id'         => 'nullable|exists:producto_variante_configs,id',
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
                $todosItems = collect($data['tiendas'])->flatMap(fn($t) => $t['items']);

                // ── Stock por producto (total general) ────────────────────────
                $productosUnicos = $todosItems
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

                // ── Stock por variante (tela/talla) ───────────────────────────
                $varianteTotales = $todosItems
                    ->filter(fn($i) => !empty($i['variante_id']))
                    ->groupBy('variante_id')
                    ->map(fn($items) => collect($items)->sum('cantidad'));

                foreach ($varianteTotales as $varianteId => $cantTotal) {
                    $invVar = InventarioVariante::where('variante_id', $varianteId)
                        ->where('tienda_id', $fabricaId)
                        ->lockForUpdate()->first();
                    $libre = $invVar ? ($invVar->cantidad_disponible - $invVar->cantidad_reservada) : 0;
                    if ($libre < $cantTotal) {
                        abort(422, "Stock insuficiente de esta variante específica en fábrica.");
                    }
                }

                // ── Stock por variante personalizada (config) ─────────────────
                $configTotales = $todosItems
                    ->filter(fn($i) => !empty($i['especificaciones']['config_id']))
                    ->groupBy(fn($i) => (int) $i['especificaciones']['config_id'])
                    ->map(fn($items) => collect($items)->sum('cantidad'));

                foreach ($configTotales as $configId => $cantTotal) {
                    $invVC = InventarioVarianteConfig::where('config_id', $configId)
                        ->where('tienda_id', $fabricaId)
                        ->lockForUpdate()->first();
                    $libre = $invVC ? ($invVC->cantidad_disponible - $invVC->cantidad_reservada) : 0;
                    if ($libre < $cantTotal) {
                        abort(422, "Stock insuficiente de esta variante personalizada en fábrica.");
                    }
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
                        'variante_id'       => $item['variante_id']       ?? null,
                        'combo_config_id'   => $item['combo_config_id']   ?? null,
                        'cantidad'          => $item['cantidad'],
                        'especificaciones'  => $item['especificaciones']   ?? null,
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
        $data    = $request->validate(['notas_vendedor' => 'required|string|min:1|max:500']);
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

        DB::transaction(function () use ($st, $usuario, $fabricaId, $cantidadesMap, $data) {
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
                    // Variante (talla o tapizado) — descontar inventario_variantes de fábrica
                    if ($cantAceptada > 0) {
                        if ($item->variante_id) {
                            // Direct FK reference (new items)
                            InventarioVariante::where('variante_id', $item->variante_id)
                                ->where('tienda_id', $fabricaId)
                                ->decrement('cantidad_disponible', $cantAceptada);
                            if ($item->combo_config_id) {
                                InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                                    ->where('config_id', $item->combo_config_id)
                                    ->where('tienda_id', $fabricaId)
                                    ->decrement('cantidad_disponible', $cantAceptada);
                            }
                        } else {
                            // Fallback: resolve variant from especificaciones (legacy items)
                            $esp = $item->especificaciones;
                            if ($esp && !empty($esp['medida'])) {
                                $medida = mb_strtolower(trim($esp['medida']));
                                $varianteTalla = ProductoVariante::where('producto_id', $item->producto_id)
                                    ->whereNotNull('medida')->get()
                                    ->first(fn($v) => mb_strtolower(trim($v->medida)) === $medida);
                                if ($varianteTalla) {
                                    InventarioVariante::where('variante_id', $varianteTalla->id)
                                        ->where('tienda_id', $fabricaId)
                                        ->decrement('cantidad_disponible', $cantAceptada);
                                }
                            } elseif ($esp && !empty($esp['marca']) && !empty($esp['tela']) && !empty($esp['color'])) {
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
                            } elseif ($esp && !empty($esp['config_id'])) {
                                InventarioVarianteConfig::where('config_id', (int) $esp['config_id'])
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

                if ($item->variante_id) {
                    // Direct FK reference (new items)
                    $varianteId = $item->variante_id;
                    $invVar = InventarioVariante::firstOrCreate(
                        ['variante_id' => $varianteId, 'tienda_id' => $st->tienda_id],
                        ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
                    );
                    $invVar->increment('cantidad_disponible', $cantAceptada);

                    if ($item->combo_config_id) {
                        $invCombo = InventarioVarianteCombinacion::firstOrCreate(
                            ['variante_id' => $varianteId, 'config_id' => $item->combo_config_id, 'tienda_id' => $st->tienda_id],
                            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
                        );
                        $invCombo->increment('cantidad_disponible', $cantAceptada);
                    }
                } else {
                    // Fallback: resolve variant from especificaciones (legacy items)
                    $esp = $item->especificaciones;

                    if ($esp && !empty($esp['medida'])) {
                        // Variante por talla (ej: colchones)
                        $medida   = trim($esp['medida']);
                        $variante = ProductoVariante::where('producto_id', $item->producto_id)
                            ->whereNotNull('medida')->get()
                            ->first(fn($v) => mb_strtolower(trim($v->medida)) === mb_strtolower($medida));

                        if (!$variante) {
                            $variante = ProductoVariante::create([
                                'producto_id' => $item->producto_id,
                                'medida'      => $medida,
                                'activo'      => true,
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

                    } elseif ($esp && !empty($esp['marca']) && !empty($esp['tela']) && !empty($esp['color'])) {
                        // Variante por tela (tapizado)
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

                    } elseif ($esp && !empty($esp['config_id'])) {
                        $invVC = InventarioVarianteConfig::firstOrCreate(
                            ['config_id' => (int) $esp['config_id'], 'tienda_id' => $st->tienda_id],
                            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0]
                        );
                        $invVC->increment('cantidad_disponible', $cantAceptada);
                    }
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
                'estado'         => 'aceptado',
                'notas_vendedor' => $data['notas_vendedor'],
                'respondido_at'  => now(),
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

        $notaTexto = $data['notas_vendedor'];
        NotificacionService::crear(
            'surtido_aceptado',
            $aceptadoParcial ? 'Surtido aceptado parcialmente' : 'Surtido aceptado',
            $aceptadoParcial
                ? "{$st->tienda->nombre} aceptó parcialmente el surtido #{$st->surtido_id}. Nota: {$notaTexto}"
                : "{$st->tienda->nombre} confirmó la recepción del surtido #{$st->surtido_id} ({$usuario->nombre}). Nota: {$notaTexto}",
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
     * Recomienda por venta en riesgo, no por estante vacío.
     *
     * El catálogo tiene cientos de productos y cada tienda maneja solo una parte:
     * que una sede tenga cero de algo que nunca vende no es un problema. Lo que
     * duele es quedarse sin lo que sí se vende. Por eso la métrica es cuántas
     * unidades van a faltar en el próximo mes al ritmo de venta actual.
     *
     * Motivos, de más a menos urgente:
     *  · perdiendo_venta : hay demanda y el stock libre está en cero
     *  · por_agotarse    : no alcanza para cubrir el horizonte objetivo
     *  · bajo_minimo     : bajo el stock_minimo que alguien configuró a mano,
     *                      aunque no tenga rotación (es una decisión humana)
     *
     * Los productos sin stock y sin rotación quedan fuera del listado; solo se
     * informa cuántos son.
     */
    public function recomendaciones(Request $request)
    {
        $VENTANA_DIAS   = (int) $request->query('ventana', 90);   // historial que se mira
        $HORIZONTE_DIAS = (int) $request->query('horizonte', 30); // stock que se quiere tener
        $desde          = now()->subDays($VENTANA_DIAS);

        // ── Demanda: lo que se vendió por tienda y producto ──────────────────
        $ventas = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->whereNotNull('oi.producto_id')
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            ->where('o.created_at', '>=', $desde)
            ->selectRaw('o.tienda_id, oi.producto_id, SUM(oi.cantidad) AS unidades')
            ->groupBy('o.tienda_id', 'oi.producto_id')
            ->get()
            ->keyBy(fn($r) => "{$r->tienda_id}_{$r->producto_id}");

        // Antes se sumaba aquí una "demanda insatisfecha" contando las
        // notificaciones de sin_stock_libre. Estaba mal por dos lados: esa
        // alerta salía DESPUÉS de una venta buena —así que contaba dos veces la
        // misma venta— y dependía de un tipo de notificación que ya no se crea.
        // La demanda son las ventas; el stock dice si hay que reponer.

        // ── Inventario de todo lo que tiene demanda o mínimo configurado ─────
        $inventario = DB::table('inventario as inv')
            ->join('productos as p', 'p.id', '=', 'inv.producto_id')
            ->join('tiendas as t',   't.id', '=', 'inv.tienda_id')
            ->where('p.activo', true)
            ->where('t.activa', true)
            ->selectRaw("
                inv.tienda_id,
                t.nombre AS tienda_nombre,
                inv.producto_id,
                p.nombre AS producto_nombre,
                p.categoria,
                p.foto_url,
                inv.cantidad_disponible,
                inv.cantidad_reservada,
                COALESCE(inv.stock_minimo, 0) AS stock_minimo
            ")
            ->get()
            ->keyBy(fn($r) => "{$r->tienda_id}_{$r->producto_id}");

        $recomendados  = collect();
        $sinRotacion   = 0;

        foreach ($inventario as $key => $inv) {
            $vendidas = (int) ($ventas->get($key)->unidades ?? 0);

            $demandaTotal  = $vendidas;
            $demandaDiaria = $demandaTotal / max($VENTANA_DIAS, 1);
            $necesarioMes  = (int) ceil($demandaDiaria * $HORIZONTE_DIAS);

            $stockLibre = (int) $inv->cantidad_disponible - (int) $inv->cantidad_reservada;
            $minimo     = (int) $inv->stock_minimo;

            $faltante = max(0, $necesarioMes - $stockLibre);

            // Mínimo configurado a mano. Solo cuenta si alguien lo subió por
            // encima de 1: ese es el valor por defecto con el que nace todo el
            // inventario, así que tomarlo como señal metería el catálogo entero.
            $bajoMinimo = $minimo > 1 && $stockLibre <= $minimo;
            if ($bajoMinimo) {
                $faltante = max($faltante, $minimo - $stockLibre);
            }

            if ($faltante <= 0) {
                // Sin stock y sin demanda: no es una alerta, solo catálogo que
                // esa tienda no maneja. Se cuenta pero no se lista.
                if ($stockLibre <= 0 && $demandaTotal === 0) $sinRotacion++;
                continue;
            }

            $cobertura = $demandaDiaria > 0
                ? (int) floor($stockLibre / $demandaDiaria)
                : null;

            $motivo = match (true) {
                $demandaTotal > 0 && $stockLibre <= 0 => 'perdiendo_venta',
                $demandaTotal > 0                     => 'por_agotarse',
                default                               => 'bajo_minimo',
            };

            $recomendados->push((object) [
                'tienda_id'        => (int) $inv->tienda_id,
                'tienda_nombre'    => $inv->tienda_nombre,
                'producto_id'      => (int) $inv->producto_id,
                'producto_nombre'  => $inv->producto_nombre,
                'categoria'        => $inv->categoria,
                'foto_url'         => $inv->foto_url,
                'stock_actual'     => (int) $inv->cantidad_disponible,
                'stock_libre'      => $stockLibre,
                'stock_minimo'     => $minimo,
                'ventas_periodo'   => $vendidas,
                'demanda_diaria'   => round($demandaDiaria, 2),
                'cobertura_dias'   => $cobertura,
                'faltante'         => $faltante,   // lo que hay que llevar
                'motivo'           => $motivo,
            ]);
        }

        if ($recomendados->isEmpty()) {
            return response()->json([
                'tiendas'          => [],
                'sin_rotacion'     => $sinRotacion,
                'ventana_dias'     => $VENTANA_DIAS,
                'horizonte_dias'   => $HORIZONTE_DIAS,
            ]);
        }

        $perPage = min((int) $request->query('per_page', 12), 50);
        $page    = max((int) $request->query('page', 1), 1);

        $tiendas = $recomendados
            ->groupBy('tienda_id')
            ->map(function ($items) use ($perPage, $page) {
                $first = $items->first();

                // Primero lo que ya está costando ventas, y dentro de cada grupo
                // lo que más falta. El faltante ordena solo: es unidades reales.
                $ordenados = $items->sortByDesc(fn($i) =>
                    (match ($i->motivo) {
                        'perdiendo_venta' => 1000000,
                        'por_agotarse'    => 500000,
                        default           => 0,
                    })
                    + min($i->faltante, 9999) * 10
                    + min((int) round($i->demanda_diaria * 100), 9)
                )->values();

                $total = $ordenados->count();

                return [
                    'tienda_id'        => $first->tienda_id,
                    'tienda_nombre'    => $first->tienda_nombre,
                    'perdiendo_venta'  => $items->where('motivo', 'perdiendo_venta')->count(),
                    'por_agotarse'     => $items->where('motivo', 'por_agotarse')->count(),
                    'bajo_minimo'      => $items->where('motivo', 'bajo_minimo')->count(),
                    'unidades_faltantes' => (int) $items->sum('faltante'),
                    'total'            => $total,
                    'page'             => $page,
                    'last_page'        => (int) ceil($total / $perPage),
                    'per_page'         => $perPage,
                    'productos'        => $ordenados->forPage($page, $perPage)->values(),
                ];
            })
            // Las tiendas con ventas en riesgo primero
            ->sortByDesc(fn($t) => $t['perdiendo_venta'] * 1000 + $t['unidades_faltantes'])
            ->values();

        return response()->json([
            'tiendas'        => $tiendas,
            'sin_rotacion'   => $sinRotacion,
            'ventana_dias'   => $VENTANA_DIAS,
            'horizonte_dias' => $HORIZONTE_DIAS,
        ]);
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
