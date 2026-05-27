<?php

namespace App\Http\Controllers;

use App\Events\InventarioActualizado;
use App\Events\OrdenActualizada;
use App\Events\OrdenListaParaEntrega;
use App\Mail\CotizacionMail;
use App\Services\NotificacionService;
use App\Models\Inventario;
use App\Models\Usuario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProductoVariante;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrdenController extends Controller
{
    /**
     * GET /api/ordenes
     * Vendedor: solo las suyas. Supervisor: todas.
     * Filtros: estado, tienda_id, desde, hasta.
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Orden::with([
            'cliente:id,nombre,telefono',
            'tienda:id,nombre',
            'vendedor:id,nombre',
            'items.produccion.pasoActual',
        ])->withSum('pagos', 'monto');

        if ($usuario->rol === 'vendedor') {
            if ($usuario->facturacion) {
                $query->where(function ($q) use ($usuario) {
                    $q->where('vendedor_id', $usuario->id)
                      ->orWhere(function ($q2) use ($usuario) {
                          $q2->where('tienda_id', $usuario->tienda_default_id)
                              ->where('estado', 'entregado');
                      });
                });
            } else {
                $query->where('vendedor_id', $usuario->id);
            }
        }

        if ($v = $request->query('estado')) {
            $query->where('estado', $v);
        }
        if ($v = $request->query('tienda_id')) {
            $query->where('tienda_id', $v);
        }
        if ($v = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $v);
        }
        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->whereHas('cliente', fn($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$term]));
        }

        $ordenes = $query->orderByDesc('created_at')->paginate(20);

        $ordenes->getCollection()->transform(function ($o) {
            $o->total_pagado    = (float) ($o->pagos_sum_monto ?? 0);
            $o->saldo_pendiente = (float) $o->valor_total - $o->total_pagado;

            // Paso actual de producción (solo órdenes en_produccion con pasos activos)
            $o->paso_produccion_actual = null;
            if ($o->estado === 'en_produccion') {
                foreach ($o->items as $item) {
                    if (! $item->produccion) continue;
                    if ($item->produccion->estado === 'pendiente_despachador') {
                        $o->paso_produccion_actual = 'pendiente_despachador';
                        break;
                    }
                    $paso = $item->produccion->pasoActual;
                    if ($paso && $paso->estado === 'en_proceso') {
                        $o->paso_produccion_actual = $paso->tipo_proceso;
                        break;
                    }
                }
            }

            unset($o->items);
            return $o;
        });

        return response()->json($ordenes);
    }

    /**
     * POST /api/ordenes
     *
     * Crea la orden, reserva inventario, registra anticipo y
     * crea registros de producción para items personalizados.
     * Todo en una sola transacción atómica.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'                    => 'required|exists:clientes,id',
            'tienda_id'                     => 'required|exists:tiendas,id',
            'canal'                         => 'required|in:fisica,whatsapp,red_social,otro',
            'tipo'                          => 'nullable|in:venta,restauracion',
            'anticipo_pct'                  => 'nullable|numeric|min:1|max:100',
            'notas'                              => 'nullable|string|max:1000',
            'factura_foto_url'                   => 'nullable|string|max:500',
            'firma_url'                          => 'required|string|max:500',
            'departamento_envio'                 => 'nullable|string|max:100',
            'ciudad_envio'                       => 'nullable|string|max:100',
            'direccion_envio'                    => 'nullable|string|max:300',
            'anticipo_monto'                     => 'required|numeric|min:1',
            'anticipo_metodo'                    => 'required|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'                => 'nullable|string|max:100',
            'items'                              => 'required|array|min:1',
            'items.*.producto_id'                => 'nullable|exists:productos,id',
            'items.*.nombre_custom'              => 'required_without:items.*.producto_id|nullable|string|max:200',
            'items.*.categoria_custom'           => 'nullable|string|max:100',
            'items.*.variante_id'                => 'nullable|exists:producto_variantes,id',
            'items.*.tienda_origen_id'           => 'nullable|exists:tiendas,id',
            'items.*.cantidad'                   => 'required|integer|min:1',
            'items.*.precio_unitario'            => 'required|numeric|min:0',
            'items.*.es_personalizado'           => 'nullable|boolean',
            'items.*.specs_personalizacion'      => 'nullable|array',
            'items.*.boceto_url'                 => 'nullable|string|max:500',
            'items.*.fecha_entrega_prometida'    => 'nullable|date',
        ]);

        // Todo usuario debe tener su firma registrada antes de crear órdenes
        if (! $request->user()->firma_url) {
            return response()->json([
                'message' => 'Debes registrar tu firma en Mi Perfil antes de crear órdenes.',
            ], 422);
        }

        $tiendaId   = $data['tienda_id'];
        $anticupoPct = $data['anticipo_pct'] ?? 50;

        // Calcular valor total server-side
        $valorTotal = collect($data['items'])->sum(
            fn ($i) => $i['cantidad'] * $i['precio_unitario']
        );

        // Validar que el anticipo cubra el porcentaje mínimo
        $minimoAnticipo = round($valorTotal * $anticupoPct / 100, 2);
        if ($data['anticipo_monto'] < $minimoAnticipo) {
            return response()->json([
                'message' => "El anticipo mínimo es " . number_format($minimoAnticipo, 2) . " COP ({$anticupoPct}% de " . number_format($valorTotal, 2) . ").",
                'errors'  => ['anticipo_monto' => ["Debe ser al menos {$minimoAnticipo}"]],
            ], 422);
        }

        // Protección contra doble envío: misma orden del mismo vendedor en los últimos 15 segundos
        $duplicado = Orden::where('vendedor_id', $request->user()->id)
            ->where('cliente_id', $data['cliente_id'])
            ->where('tienda_id', $tiendaId)
            ->where('valor_total', $valorTotal)
            ->where('created_at', '>=', now()->subSeconds(15))
            ->first();

        if ($duplicado) {
            return response()->json([
                'message' => 'Esta orden ya fue registrada hace unos segundos.',
                'orden_id' => $duplicado->id,
            ], 409);
        }

        $orden = DB::transaction(function () use ($data, $tiendaId, $anticupoPct, $valorTotal, $request) {

            // --- 1. Verificar stock para items no personalizados (con bloqueo) ---
            foreach ($data['items'] as $item) {
                if (! ($item['es_personalizado'] ?? false) && ! empty($item['producto_id'])) {
                    $varianteId    = $item['variante_id']      ?? null;
                    $origenTiendaId = $item['tienda_origen_id'] ?? $tiendaId;

                    if ($varianteId) {
                        $inv = InventarioVariante::where('variante_id', $varianteId)
                            ->where('tienda_id', $origenTiendaId)
                            ->lockForUpdate()->first();
                    } else {
                        $inv = Inventario::where('producto_id', $item['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->lockForUpdate()->first();
                    }

                    $stockLibre = $inv
                        ? $inv->cantidad_disponible - $inv->cantidad_reservada
                        : 0;

                    if ($stockLibre < $item['cantidad']) {
                        $where = $varianteId ? "variante ID {$varianteId}" : "producto ID {$item['producto_id']}";
                        abort(422, "Stock insuficiente para {$where}. Stock libre: {$stockLibre}, solicitado: {$item['cantidad']}.");
                    }
                }
            }

            // --- 2. Crear la orden ---
            $orden = Orden::create([
                'cliente_id'        => $data['cliente_id'],
                'vendedor_id'       => $request->user()->id,
                'tienda_id'         => $tiendaId,
                'canal'             => $data['canal'],
                'tipo'              => $data['tipo'] ?? 'venta',
                'estado'            => 'pendiente_anticipo',
                'valor_total'       => $valorTotal,
                'anticipo_pct'      => $anticupoPct,
                'notas'             => $data['notas'] ?? null,
                'factura_foto_url'  => $data['factura_foto_url'] ?? null,
                'firma_url'           => $data['firma_url'] ?? null,
                'departamento_envio' => $data['departamento_envio'] ?? null,
                'ciudad_envio'       => $data['ciudad_envio'] ?? null,
                'direccion_envio'    => $data['direccion_envio'] ?? null,
            ]);

            // --- 3. Crear items, reservar stock y crear producción ---
            foreach ($data['items'] as $itemData) {
                $esPersonalizado  = (bool) ($itemData['es_personalizado'] ?? false);
                $esProductoCustom = empty($itemData['producto_id']); // no existe en catálogo

                $varianteId     = $itemData['variante_id']      ?? null;
                $origenTiendaId = $itemData['tienda_origen_id'] ?? $tiendaId;

                // Snapshot del nombre de variante para legibilidad
                $specsExtra = $itemData['specs_personalizacion'] ?? null;
                if ($varianteId && ! $esPersonalizado && ! $esProductoCustom) {
                    $v = ProductoVariante::find($varianteId);
                    $specsExtra = array_merge($specsExtra ?? [], [
                        'variante_marca' => $v?->marca_tela,
                        'variante_color' => $v?->nombre_color,
                    ]);
                }

                $item = OrdenItem::create([
                    'orden_id'              => $orden->id,
                    'producto_id'           => $itemData['producto_id'] ?? null,
                    'nombre_custom'         => $esProductoCustom ? ($itemData['nombre_custom'] ?? null) : null,
                    'categoria_custom'      => $esProductoCustom ? ($itemData['categoria_custom'] ?? null) : null,
                    'variante_id'           => $varianteId,
                    'tienda_origen_id'      => $origenTiendaId !== $tiendaId ? $origenTiendaId : null,
                    'cantidad'              => $itemData['cantidad'],
                    'precio_unitario'       => $itemData['precio_unitario'],
                    'es_personalizado'      => $esPersonalizado || $esProductoCustom,
                    'specs_personalizacion' => $specsExtra,
                    'boceto_url'            => $itemData['boceto_url'] ?? null,
                    'fecha_entrega_prom'    => $itemData['fecha_entrega_prometida'] ?? null,
                ]);

                if ($esPersonalizado || $esProductoCustom) {
                    Produccion::create([
                        'orden_item_id'    => $item->id,
                        'fecha_inicio'     => now()->toDateString(),
                        'fecha_compromiso' => $itemData['fecha_entrega_prometida'] ?? null,
                        'estado'           => 'pendiente',
                    ]);
                } else {
                    // Reservar stock en la tienda de origen (puede ser otra tienda)
                    $motivo = "Orden #{$orden->id}" . ($varianteId ? " ({$specsExtra['variante_marca']} - {$specsExtra['variante_color']})" : '');

                    if ($varianteId) {
                        InventarioVariante::where('variante_id', $varianteId)
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                        // Las variantes son parte del stock base → reservar en ambos
                        Inventario::where('producto_id', $itemData['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                    } else {
                        Inventario::where('producto_id', $itemData['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                    }

                    InventarioMovimiento::create([
                        'producto_id' => $itemData['producto_id'],
                        'tienda_id'   => $origenTiendaId,
                        'tipo'        => 'reserva',
                        'cantidad'    => $itemData['cantidad'],
                        'motivo'      => $motivo,
                        'usuario_id'  => $request->user()->id,
                    ]);
                }
            }

            // --- 4. Registrar anticipo ---
            $orden->pagos()->create([
                'vendedor_id' => $request->user()->id,
                'tipo'        => 'anticipo',
                'monto'       => $data['anticipo_monto'],
                'metodo'      => $data['anticipo_metodo'],
                'referencia'  => $data['anticipo_referencia'] ?? null,
            ]);

            return $orden;
        });

        $ordenCargada = $orden->load([
            'cliente:id,nombre,cedula,telefono',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
            'items.produccion',
            'pagos',
        ]);

        event(new OrdenActualizada(
            $orden->id,
            (int) $tiendaId,
            'pendiente_anticipo',
            $ordenCargada->cliente->nombre,
        ));

        NotificacionService::crear(
            'venta_nueva',
            'Nueva venta registrada',
            "Orden #{$orden->id} — {$ordenCargada->cliente->nombre} · $" . number_format($valorTotal, 0, ',', '.') . " COP",
            ['orden_id' => $orden->id, 'tienda_id' => (int) $tiendaId, 'valor_total' => $valorTotal],
        );

        // Notificar al supervisor que debe asignar fecha de entrega
        NotificacionService::crear(
            'asignar_fecha',
            'Asignar fecha de entrega',
            "Orden #{$orden->id} de {$ordenCargada->cliente->nombre} necesita fecha de entrega",
            ['orden_id' => $orden->id],
            // usuario_id null → va al supervisor
        );

        // Notificar cambio de inventario, detectar ventas cruzadas y alertar si sin stock
        $origenesExternos = [];
        foreach ($data['items'] as $itemData) {
            if (! ($itemData['es_personalizado'] ?? false) && ! empty($itemData['producto_id'])) {
                $origenTiendaId = $itemData['tienda_origen_id'] ?? $tiendaId;
                event(new InventarioActualizado((int) $origenTiendaId, (int) $itemData['producto_id'], 'reserva'));
                $this->notificarSiSinStock((int) $itemData['producto_id'], (int) $origenTiendaId);

                if ($origenTiendaId && (int) $origenTiendaId !== (int) $tiendaId) {
                    $origenesExternos[] = (int) $origenTiendaId;
                }
            }
        }

        // Notificar a vendedores de la tienda fuente cuando se usa su stock
        foreach (array_unique($origenesExternos) as $origenId) {
            $itemsOrigen = $ordenCargada->items
                ->where('tienda_origen_id', $origenId)
                ->where('es_personalizado', false);

            $productosStr = $itemsOrigen
                ->map(fn($i) => "{$i->producto->nombre} ({$i->cantidad})")
                ->implode(', ');

            $productosIds = $itemsOrigen->pluck('producto_id')->values();

            $vendedoresOrigen = Usuario::where('tienda_default_id', $origenId)
                ->where('rol', 'vendedor')
                ->where('activo', true)
                ->pluck('id');

            foreach ($vendedoresOrigen as $vendedorId) {
                NotificacionService::crear(
                    'venta_otra_tienda',
                    'Venta desde otra tienda',
                    "Orden #{$orden->id} - {$productosStr}",
                    [
                        'orden_id'  => $orden->id,
                        'tienda_id' => $origenId,
                        'productos' => $productosIds,
                    ],
                    $vendedorId,
                );
            }
        }

        // Enviar cotización por email si el cliente tiene email
        if ($ordenCargada->cliente->email) {
            try {
                Mail::to($ordenCargada->cliente->email)
                    ->send(new CotizacionMail($orden->id));
            } catch (\Throwable) {
                // El email nunca bloquea la respuesta
            }
        }

        return response()->json($ordenCargada, 201);
    }

    /**
     * POST /api/ordenes/{id}/reenviar-cotizacion
     * Re-envía la cotización por email al cliente.
     */
    public function reenviarCotizacion(Request $request, int $id)
    {
        $usuario = $request->user();

        $orden = Orden::with('cliente:id,nombre,email')->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $email = $request->input('email') ?? $orden->cliente->email;

        if (! $email) {
            return response()->json(['message' => 'El cliente no tiene email registrado.'], 422);
        }

        Mail::to($email)->send(new CotizacionMail($orden->id));

        return response()->json(['message' => "Cotización enviada a {$email}."]);
    }

    /**
     * GET /api/ordenes/{id}
     */
    public function show(Request $request, int $id)
    {
        $usuario = $request->user();

        $orden = Orden::with([
            'cliente',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,precio_base,personalizable,foto_url,medidas,material',
            'items.produccion',
            'pagos.facturacionTomadaPor:id,nombre',
            'ediciones.usuario:id,nombre',
        ])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            if (! $usuario->facturacion) {
                return response()->json(['message' => 'No autorizado.'], 403);
            }
        }

        $orden->total_pagado    = $orden->totalPagado();
        $orden->saldo_pendiente = $orden->saldoPendiente();

        return response()->json($orden);
    }

    /**
     * PATCH /api/ordenes/{id}
     * Edita datos de la orden (notas, canal, ítems).
     * Solo disponible en estados pendiente_anticipo y en_produccion.
     * Registra auditoría en orden_ediciones.
     */
    public function update(Request $request, int $id)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'notas'                         => 'sometimes|nullable|string|max:1000',
            'canal'                         => 'sometimes|nullable|in:fisica,whatsapp,red_social,otro',
            'departamento_envio'            => 'sometimes|nullable|string|max:100',
            'ciudad_envio'                  => 'sometimes|nullable|string|max:100',
            'direccion_envio'               => 'sometimes|nullable|string|max:300',
            'items'                         => 'sometimes|nullable|array',
            'items.*.id'                    => 'required_with:items|integer|exists:orden_items,id',
            'items.*.specs_personalizacion' => 'sometimes|nullable|array',
            'items.*.precio_unitario'       => 'sometimes|nullable|numeric|min:0',
            'items.*.fecha_entrega_prom'    => 'sometimes|nullable|date',
            'items.*.cantidad'              => 'sometimes|nullable|integer|min:1',
            'items.*.producto_id'           => 'sometimes|nullable|exists:productos,id',
        ]);

        $orden = Orden::with(['items', 'items.producto:id,nombre'])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if (! in_array($orden->estado, ['pendiente_anticipo', 'en_produccion'])) {
            return response()->json([
                'message' => 'No se puede editar una orden en estado "' . $orden->estado . '".',
            ], 422);
        }

        $cambios = [];

        DB::transaction(function () use ($data, $orden, $usuario, &$cambios) {
            $updateOrden = [];

            // ── Cambios a nivel de orden ──────────────────────────────────────
            if (array_key_exists('notas', $data) && $data['notas'] !== $orden->notas) {
                $cambios[] = ['campo' => 'notas', 'label' => 'Notas', 'antes' => $orden->notas, 'despues' => $data['notas']];
                $updateOrden['notas'] = $data['notas'];
            }
            if (array_key_exists('canal', $data) && $data['canal'] !== $orden->canal) {
                $cambios[] = ['campo' => 'canal', 'label' => 'Canal', 'antes' => $orden->canal, 'despues' => $data['canal']];
                $updateOrden['canal'] = $data['canal'];
            }
            if (array_key_exists('departamento_envio', $data) && $data['departamento_envio'] !== $orden->departamento_envio) {
                $cambios[] = ['campo' => 'departamento_envio', 'label' => 'Departamento de envío', 'antes' => $orden->departamento_envio, 'despues' => $data['departamento_envio']];
                $updateOrden['departamento_envio'] = $data['departamento_envio'];
            }
            if (array_key_exists('ciudad_envio', $data) && $data['ciudad_envio'] !== $orden->ciudad_envio) {
                $cambios[] = ['campo' => 'ciudad_envio', 'label' => 'Ciudad de envío', 'antes' => $orden->ciudad_envio, 'despues' => $data['ciudad_envio']];
                $updateOrden['ciudad_envio'] = $data['ciudad_envio'];
            }
            if (array_key_exists('direccion_envio', $data) && $data['direccion_envio'] !== $orden->direccion_envio) {
                $cambios[] = ['campo' => 'direccion_envio', 'label' => 'Dirección de envío', 'antes' => $orden->direccion_envio, 'despues' => $data['direccion_envio']];
                $updateOrden['direccion_envio'] = $data['direccion_envio'];
            }

            // ── Cambios a nivel de ítems ──────────────────────────────────────
            if (! empty($data['items'])) {
                $idsDeOrden = $orden->items->pluck('id')->toArray();

                foreach ($data['items'] as $itemData) {
                    if (! in_array($itemData['id'], $idsDeOrden)) continue;

                    $item          = $orden->items->firstWhere('id', $itemData['id']);
                    $nombreProd    = $item->producto?->nombre ?? "Ítem #{$item->id}";
                    $updateItem    = [];
                    $origenId      = $item->tienda_origen_id ?? $orden->tienda_id;

                    // Precio
                    if (array_key_exists('precio_unitario', $itemData) && $itemData['precio_unitario'] !== null) {
                        $nuevo  = (float) $itemData['precio_unitario'];
                        $actual = (float) $item->precio_unitario;
                        if ($nuevo !== $actual) {
                            $cambios[]            = ['campo' => "item_{$item->id}_precio", 'label' => "{$nombreProd} — precio", 'antes' => $actual, 'despues' => $nuevo];
                            $updateItem['precio_unitario'] = $nuevo;
                        }
                    }

                    // Fecha entrega (solo supervisor)
                    if ($usuario->rol === 'supervisor' && array_key_exists('fecha_entrega_prom', $itemData)) {
                        $nueva  = $itemData['fecha_entrega_prom'];
                        $actual = $item->fecha_entrega_prom ? substr((string) $item->fecha_entrega_prom, 0, 10) : null;
                        if ($nueva !== $actual) {
                            $cambios[]                      = ['campo' => "item_{$item->id}_fecha", 'label' => "{$nombreProd} — fecha entrega", 'antes' => $actual, 'despues' => $nueva];
                            $updateItem['fecha_entrega_prom'] = $nueva;
                            \App\Models\Produccion::where('orden_item_id', $item->id)->update(['fecha_compromiso' => $nueva]);
                        }
                    }

                    // Specs (solo ítems personalizados)
                    if ($item->es_personalizado && array_key_exists('specs_personalizacion', $itemData)) {
                        $antes   = $item->specs_personalizacion;
                        $despues = $itemData['specs_personalizacion'];
                        if (json_encode($antes) !== json_encode($despues)) {
                            $cambios[]                          = ['campo' => "item_{$item->id}_specs", 'label' => "{$nombreProd} — especificaciones", 'antes' => $antes, 'despues' => $despues];
                            $updateItem['specs_personalizacion'] = $despues;
                        }
                    }

                    // Cantidad y/o producto (solo ítems NO personalizados)
                    if (! $item->es_personalizado) {
                        $cantNueva     = isset($itemData['cantidad'])    ? (int)   $itemData['cantidad']    : (int) $item->cantidad;
                        $prodNuevoId   = isset($itemData['producto_id']) ? (int)   $itemData['producto_id'] : null;
                        $cambiaProducto = $prodNuevoId && $prodNuevoId !== (int) $item->producto_id;
                        $cambiaCantidad = $cantNueva !== (int) $item->cantidad;

                        if ($cambiaProducto) {
                            // Verificar stock del nuevo producto
                            $invNuevo   = Inventario::where('producto_id', $prodNuevoId)->where('tienda_id', $origenId)->lockForUpdate()->first();
                            $stockLibre = $invNuevo ? ($invNuevo->cantidad_disponible - $invNuevo->cantidad_reservada) : 0;
                            if ($stockLibre < $cantNueva) {
                                abort(422, "Stock insuficiente para el nuevo producto. Stock libre: {$stockLibre}, necesario: {$cantNueva}.");
                            }

                            // Liberar reserva del producto anterior
                            Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $origenId)->decrement('cantidad_reservada', (int) $item->cantidad);
                            InventarioMovimiento::create(['producto_id' => $item->producto_id, 'tienda_id' => $origenId, 'tipo' => 'liberacion', 'cantidad' => (int) $item->cantidad, 'motivo' => "Edición orden #{$orden->id} — cambio de producto", 'usuario_id' => $usuario->id]);

                            // Reservar nuevo producto
                            Inventario::where('producto_id', $prodNuevoId)->where('tienda_id', $origenId)->increment('cantidad_reservada', $cantNueva);
                            InventarioMovimiento::create(['producto_id' => $prodNuevoId, 'tienda_id' => $origenId, 'tipo' => 'reserva', 'cantidad' => $cantNueva, 'motivo' => "Edición orden #{$orden->id} — nuevo producto", 'usuario_id' => $usuario->id]);

                            $nombreNuevo = \App\Models\Producto::find($prodNuevoId)?->nombre ?? "Producto #{$prodNuevoId}";
                            $cambios[]   = ['campo' => "item_{$item->id}_producto", 'label' => "Producto cambiado", 'antes' => $nombreProd, 'despues' => $nombreNuevo];
                            $updateItem['producto_id'] = $prodNuevoId;
                            $updateItem['variante_id'] = null;
                            if ($cambiaCantidad) {
                                $cambios[] = ['campo' => "item_{$item->id}_cantidad", 'label' => "{$nombreNuevo} — cantidad", 'antes' => (int) $item->cantidad, 'despues' => $cantNueva];
                                $updateItem['cantidad'] = $cantNueva;
                            }
                        } elseif ($cambiaCantidad) {
                            $diff = $cantNueva - (int) $item->cantidad;
                            if ($diff > 0) {
                                $inv        = Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $origenId)->lockForUpdate()->first();
                                $stockLibre = $inv ? ($inv->cantidad_disponible - $inv->cantidad_reservada) : 0;
                                if ($stockLibre < $diff) {
                                    abort(422, "Stock insuficiente. Stock libre: {$stockLibre}, necesita {$diff} adicionales.");
                                }
                                Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $origenId)->increment('cantidad_reservada', $diff);
                                InventarioMovimiento::create(['producto_id' => $item->producto_id, 'tienda_id' => $origenId, 'tipo' => 'reserva', 'cantidad' => $diff, 'motivo' => "Edición orden #{$orden->id} — ajuste cantidad", 'usuario_id' => $usuario->id]);
                            } else {
                                Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $origenId)->decrement('cantidad_reservada', abs($diff));
                                InventarioMovimiento::create(['producto_id' => $item->producto_id, 'tienda_id' => $origenId, 'tipo' => 'liberacion', 'cantidad' => abs($diff), 'motivo' => "Edición orden #{$orden->id} — ajuste cantidad", 'usuario_id' => $usuario->id]);
                            }
                            $cambios[] = ['campo' => "item_{$item->id}_cantidad", 'label' => "{$nombreProd} — cantidad", 'antes' => (int) $item->cantidad, 'despues' => $cantNueva];
                            $updateItem['cantidad'] = $cantNueva;
                        }
                    }

                    if (! empty($updateItem)) {
                        $item->update($updateItem);
                    }
                }
            }

            // Recalcular valor total
            $orden->refresh()->load('items');
            $nuevoTotal = $orden->items->sum(fn ($i) => $i->cantidad * $i->precio_unitario);
            if ((float) $nuevoTotal !== (float) $orden->valor_total) {
                $cambios[]            = ['campo' => 'valor_total', 'label' => 'Total de la orden', 'antes' => (float) $orden->valor_total, 'despues' => (float) $nuevoTotal];
                $updateOrden['valor_total'] = $nuevoTotal;
            }

            if (! empty($updateOrden)) {
                $orden->update($updateOrden);
            }

            if (! empty($cambios)) {
                \App\Models\OrdenEdicion::create([
                    'orden_id'   => $orden->id,
                    'usuario_id' => $usuario->id,
                    'cambios'    => $cambios,
                ]);
            }
        });

        $ordenFresh = Orden::with([
            'cliente',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,precio_base,personalizable,foto_url,medidas,material',
            'items.produccion',
            'pagos',
            'ediciones.usuario:id,nombre',
        ])->find($id);

        $ordenFresh->total_pagado    = $ordenFresh->totalPagado();
        $ordenFresh->saldo_pendiente = $ordenFresh->saldoPendiente();

        if (! empty($cambios)) {
            NotificacionService::crear(
                'orden_editada',
                'Orden editada',
                "Orden #{$orden->id} ({$ordenFresh->cliente->nombre}) fue editada por {$usuario->nombre}",
                ['orden_id' => $orden->id],
            );
        }

        return response()->json($ordenFresh);
    }

    /**
     * PATCH /api/ordenes/{id}/estado
     *
     * Transiciones que afectan inventario:
     *   → entregado : descuenta cantidad_disponible y libera cantidad_reservada
     *   → cancelado : solo libera cantidad_reservada
     */
    public function updateEstado(Request $request, int $id)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'estado' => 'required|in:pendiente_anticipo,en_produccion,listo_entrega,en_camino,entregado,cancelado',
        ]);

        if ($usuario->rol === 'vendedor') {
            return response()->json(['message' => 'Solo el supervisor puede cambiar el estado de las órdenes.'], 403);
        }

        $orden = Orden::with('items')->findOrFail($id);

        // Regla 8: Bloquear cambios si está en listo_entrega o en_camino
        if (in_array($orden->estado, ['listo_entrega', 'en_camino'])) {
            return response()->json([
                'message' => 'Esta orden está en el módulo de Despacho. Solo puedes cambiar su estado desde allí.',
            ], 403);
        }

        $estadoAnterior = $orden->estado;
        $estadoNuevo    = $data['estado'];

        if ($estadoAnterior === $estadoNuevo) {
            return response()->json($orden, 200);
        }

        DB::transaction(function () use ($orden, $estadoNuevo, $estadoAnterior, $usuario) {

            $itemsStock = $orden->items->where('es_personalizado', false);

            foreach ($itemsStock as $item) {
                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;

                if ($estadoNuevo === 'entregado') {
                    if ($item->variante_id) {
                        InventarioVariante::where('variante_id', $item->variante_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                            ]);
                        // Variante es parte del stock base → descontar en ambos
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                            ]);
                    } else {
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                            ]);
                    }
                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $origenId,
                        'tipo'        => 'salida',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Entrega orden #{$orden->id}",
                        'usuario_id'  => $usuario->id,
                    ]);
                } elseif ($estadoNuevo === 'cancelado' && $estadoAnterior !== 'cancelado') {
                    if ($item->variante_id) {
                        InventarioVariante::where('variante_id', $item->variante_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', $item->cantidad);
                        // Liberar también en el stock base
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', $item->cantidad);
                    } else {
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', $item->cantidad);
                    }
                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $origenId,
                        'tipo'        => 'liberacion',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Cancelación orden #{$orden->id}",
                        'usuario_id'  => $usuario->id,
                    ]);
                }
            }

            $updateData = ['estado' => $estadoNuevo];
            if ($estadoNuevo === 'listo_entrega') {
                $updateData['listo_entrega_at'] = now();
            }
            $orden->update($updateData);
        });

        $ordenFresh = $orden->fresh(['items.producto:id,nombre', 'items.produccion', 'pagos', 'cliente:id,nombre', 'tienda:id,nombre']);

        event(new OrdenActualizada(
            $orden->id,
            (int) $orden->tienda_id,
            $estadoNuevo,
            $ordenFresh->cliente->nombre,
        ));

        if ($estadoNuevo === 'listo_entrega') {
            event(new OrdenListaParaEntrega(
                $orden->id,
                $ordenFresh->cliente->nombre,
                $ordenFresh->listo_entrega_at?->toIso8601String() ?? now()->toIso8601String(),
            ));

            NotificacionService::crear(
                'listo_entrega',
                'Orden lista para entrega',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} está lista para despachar",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
            );
        }

        if ($estadoNuevo === 'entregado') {
            // Supervisor
            NotificacionService::crear(
                'entregado',
                'Orden entregada',
                "Orden #{$orden->id} entregada a {$ordenFresh->cliente->nombre}",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
            );
            // Vendedor
            NotificacionService::crear(
                'entregado',
                'Tu orden fue entregada',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} recibió su pedido",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'en_produccion') {
            NotificacionService::crear(
                'en_produccion',
                'Tu pedido entró en producción',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} está en producción",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'cancelado') {
            NotificacionService::crear(
                'cancelado',
                'Tu orden fue cancelada',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} fue cancelada",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        // Notificar inventario cuando se entrega o cancela
        if (in_array($estadoNuevo, ['entregado', 'cancelado'])) {
            foreach ($orden->items->where('es_personalizado', false) as $item) {
                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;
                $tipo = $estadoNuevo === 'entregado' ? 'salida' : 'liberacion';
                event(new InventarioActualizado((int) $origenId, (int) $item->producto_id, $tipo));
            }
        }

        // Verificar stock agotado al entregar
        if ($estadoNuevo === 'entregado') {
            foreach ($orden->items->where('es_personalizado', false) as $item) {
                $this->notificarSiSinStock(
                    (int) $item->producto_id,
                    (int) ($item->tienda_origen_id ?? $orden->tienda_id),
                );
            }
        }

        return response()->json($ordenFresh);
    }

    /**
     * PATCH /api/ordenes/{id}/fechas-entrega
     * Solo supervisor. Asigna fecha de entrega a cada ítem y notifica al vendedor.
     */
    public function asignarFechas(Request $request, int $id)
    {
        $data = $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.id'    => 'required|integer|exists:orden_items,id',
            'items.*.fecha' => 'required|date',
        ]);

        $orden = Orden::with(['items', 'cliente:id,nombre', 'vendedor:id,nombre'])->findOrFail($id);

        DB::transaction(function () use ($data, $orden) {
            foreach ($data['items'] as $itemData) {
                $orden->items()
                    ->where('id', $itemData['id'])
                    ->update(['fecha_entrega_prom' => $itemData['fecha']]);

                // Sincronizar fecha_compromiso en producción si existe
                $item = $orden->items->firstWhere('id', $itemData['id']);
                if ($item) {
                    \App\Models\Produccion::where('orden_item_id', $item->id)
                        ->update(['fecha_compromiso' => $itemData['fecha']]);
                }
            }
        });

        // Notificar al vendedor que ya tiene fecha de entrega
        NotificacionService::crear(
            'fecha_asignada',
            'Fecha de entrega asignada',
            "La orden #{$orden->id} de {$orden->cliente->nombre} ya tiene fecha de entrega",
            ['orden_id' => $orden->id],
            $orden->vendedor_id,
        );

        return response()->json(['message' => 'Fechas asignadas correctamente.']);
    }

    /**
     * GET /api/ordenes/{id}/pdf
     */
    public function pdf(Request $request, int $id)
    {
        $usuario = $request->user();

        $orden = Orden::with([
            'cliente',
            'tienda:id,nombre',
            'vendedor:id,nombre,firma_url',
            'items.producto:id,nombre,categoria',
            'pagos',
        ])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            if (! $usuario->facturacion) {
                return response()->json(['message' => 'No autorizado.'], 403);
            }
        }

        $orden->total_pagado    = $orden->totalPagado();
        $orden->saldo_pendiente = $orden->saldoPendiente();
        $orden->porcentaje_pagado = $orden->valor_total > 0
            ? min(100, round(($orden->total_pagado / $orden->valor_total) * 100))
            : 0;

        // Convertir firmas a base64 para renderizado confiable en DomPDF
        $firmaCliente = $this->urlToBase64($orden->firma_url);
        $firmaVendedor = $this->urlToBase64($orden->vendedor?->firma_url);

        // Logo: leer AVIF y convertir a PNG base64 para DomPDF
        $logoBase64 = $this->avifToPngBase64(public_path('img/logo.avif'));

        // Bocetos de ítems personalizados: convertir URLs a base64
        $bocetosBase64 = [];
        foreach ($orden->items as $item) {
            if ($item->es_personalizado && $item->boceto_url) {
                $bocetosBase64[$item->id] = $this->urlToBase64($item->boceto_url);
            }
        }

        $pdf = Pdf::loadView('pdf.orden', compact('orden', 'firmaCliente', 'firmaVendedor', 'logoBase64', 'bocetosBase64'));
        $pdf->setPaper('letter');

        return $pdf->download('orden-' . $orden->id . '.pdf');
    }

    private function urlToBase64(?string $url): ?string
    {
        if (! $url) return null;
        try {
            $bytes = file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
                'ssl'  => ['verify_peer' => false],
            ]));
            return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function notificarSiSinStock(int $productoId, int $tiendaId): void
    {
        $inv = Inventario::with('producto:id,nombre', 'tienda:id,nombre')
            ->where('producto_id', $productoId)
            ->where('tienda_id', $tiendaId)
            ->first();

        if (! $inv) return;

        $libre    = $inv->cantidad_disponible - $inv->cantidad_reservada;
        $nombre   = $inv->producto?->nombre ?? "Producto #{$productoId}";
        $tiendaNm = $inv->tienda?->nombre   ?? "Tienda #{$tiendaId}";

        if ($inv->cantidad_disponible <= 0) {
            NotificacionService::crear(
                'stock_agotado',
                'Stock agotado',
                "\"$nombre\" se quedó sin stock en $tiendaNm.",
                ['producto_id' => $productoId, 'tienda_id' => $tiendaId],
            );
        } elseif ($libre <= 0) {
            NotificacionService::crear(
                'sin_stock_libre',
                'Sin unidades disponibles para venta',
                "\"$nombre\" no tiene unidades libres en $tiendaNm — todo el stock está reservado.",
                ['producto_id' => $productoId, 'tienda_id' => $tiendaId],
            );
        }
    }

    private function avifToPngBase64(string $path): ?string
    {
        if (! file_exists($path)) return null;
        try {
            $img = imagecreatefromavif($path);
            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return 'data:image/png;base64,' . base64_encode($data);
        } catch (\Throwable) {
            return null;
        }
    }
}
