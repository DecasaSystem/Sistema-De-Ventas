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
use App\Models\InventarioVarianteCombinacion;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Tienda;
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

        $hoy = now()->startOfDay();

        $ordenes->getCollection()->transform(function ($o) use ($hoy) {
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

            // Detectar si algún item tiene fecha_entrega_prom vencida y la orden no está entregada/cancelada
            $o->atrasado = !in_array($o->estado, ['entregado', 'cancelado']) &&
                $o->items->some(fn($item) =>
                    $item->fecha_entrega_prom &&
                    \Carbon\Carbon::parse($item->fecha_entrega_prom)->lt($hoy)
                );

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
            'canal'                         => 'required|in:fisica,whatsapp,instagram,facebook,pagina,red_social,otro',
            'tipo'                          => 'nullable|in:venta,restauracion',
            'anticipo_pct'                  => 'nullable|numeric|min:1|max:100',
            'notas'                              => 'nullable|string|max:1000',
            'factura_foto_url'                   => 'nullable|string|max:500',
            'firma_url'                          => 'nullable|string|max:500',
            'anexo_foto_url'                     => 'nullable|string|max:500',
            'departamento_envio'                 => 'nullable|string|max:100',
            'ciudad_envio'                       => 'nullable|string|max:100',
            'direccion_envio'                    => 'nullable|string|max:300',
            'anticipo_monto'                     => 'required|numeric|min:0',
            'anticipo_metodo'                    => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'                => 'nullable|string|max:100',
            'guardar_borrador'                   => 'nullable|boolean',
            'es_compartida'                      => 'nullable|boolean',
            'covendedor_id'                      => 'nullable|integer|exists:usuarios,id',
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
            'items.*.boceto_urls'                => 'nullable|array|max:10',
            'items.*.boceto_urls.*'              => 'nullable|string|max:500',
            'items.*.fecha_entrega_prometida'    => 'nullable|date',
        ]);

        // Todo usuario debe tener su firma registrada antes de crear órdenes
        if (! $request->user()->firma_url) {
            return response()->json([
                'message' => 'Debes registrar tu firma en Mi Perfil antes de crear órdenes.',
            ], 422);
        }

        $guardarBorrador = $request->boolean('guardar_borrador', false);
        $tiendaId        = $data['tienda_id'];
        $anticupoPct     = $data['anticipo_pct'] ?? 50;

        // Calcular valor total server-side
        $valorTotal = collect($data['items'])->sum(
            fn ($i) => $i['cantidad'] * $i['precio_unitario']
        );

        // Detectar si hay ítems personalizados sin precio (cotización pendiente)
        $tieneItemsCotizacionPendiente = collect($data['items'])->contains(
            fn($i) => ($i['es_personalizado'] ?? false) && (($i['precio_unitario'] ?? 0) == 0)
        );

        if (! $guardarBorrador) {
            // Firma requerida solo cuando no hay cotización pendiente
            if (empty($data['firma_url']) && ! $tieneItemsCotizacionPendiente) {
                return response()->json([
                    'message' => 'Se requiere la firma del cliente para confirmar la orden.',
                    'errors'  => ['firma_url' => ['La firma es obligatoria.']],
                ], 422);
            }

            // No se fuerza un mínimo — el vendedor puede poner cualquier monto ≥ 0
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

        $orden = DB::transaction(function () use ($data, $tiendaId, $anticupoPct, $valorTotal, $request, $tieneItemsCotizacionPendiente, $guardarBorrador) {

            // --- 1. Verificar stock para items no personalizados (con bloqueo) ---
            foreach ($data['items'] as $item) {
                if (! ($item['es_personalizado'] ?? false) && ! empty($item['producto_id'])) {
                    $varianteId    = $item['variante_id']      ?? null;
                    $origenTiendaId = $item['tienda_origen_id'] ?? $tiendaId;

                    if ($varianteId) {
                        $comboConfigIdCheck = $item['combo_config_id'] ?? null;
                        if ($comboConfigIdCheck) {
                            $inv = InventarioVarianteCombinacion::where('variante_id', $varianteId)
                                ->where('config_id', $comboConfigIdCheck)
                                ->where('tienda_id', $origenTiendaId)
                                ->lockForUpdate()->first();
                        } else {
                            $inv = InventarioVariante::where('variante_id', $varianteId)
                                ->where('tienda_id', $origenTiendaId)
                                ->lockForUpdate()->first();
                        }
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
                'estado'            => $guardarBorrador ? 'borrador' : ($tieneItemsCotizacionPendiente ? 'pendiente_cotizacion' : 'pendiente_anticipo'),
                'valor_total'       => $valorTotal,
                'anticipo_pct'      => $anticupoPct,
                'notas'             => $data['notas'] ?? null,
                'es_compartida'     => $data['es_compartida'] ?? false,
                'covendedor_id'     => ($data['es_compartida'] ?? false) ? ($data['covendedor_id'] ?? null) : null,
                'factura_foto_url'  => $data['factura_foto_url'] ?? null,
                'firma_url'           => $data['firma_url'] ?? null,
                'anexo_foto_url'      => $data['anexo_foto_url'] ?? null,
                'departamento_envio' => $data['departamento_envio'] ?? null,
                'ciudad_envio'       => $data['ciudad_envio'] ?? null,
                'direccion_envio'    => $data['direccion_envio'] ?? null,
            ]);

            // --- 3. Crear items, reservar stock y crear producción ---
            foreach ($data['items'] as $itemData) {
                $esPersonalizado  = (bool) ($itemData['es_personalizado'] ?? false);
                $esProductoCustom = empty($itemData['producto_id']); // no existe en catálogo

                $varianteId     = $itemData['variante_id']      ?? null;
                $comboConfigId  = $itemData['combo_config_id']  ?? null;
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
                    'combo_config_id'       => $comboConfigId,
                    'tienda_origen_id'      => $origenTiendaId !== $tiendaId ? $origenTiendaId : null,
                    'cantidad'              => $itemData['cantidad'],
                    'precio_unitario'       => $itemData['precio_unitario'],
                    'es_personalizado'      => $esPersonalizado || $esProductoCustom,
                    'specs_personalizacion' => $specsExtra,
                    'boceto_url'            => isset($itemData['boceto_urls'])
                        ? (array_values(array_filter($itemData['boceto_urls']))[0] ?? null)
                        : ($itemData['boceto_url'] ?? null),
                    'boceto_fotos'          => isset($itemData['boceto_urls']) && count(array_filter($itemData['boceto_urls'])) > 1
                        ? array_values(array_filter($itemData['boceto_urls']))
                        : null,
                    'fecha_entrega_prom'    => null, // El supervisor asigna fechas después de confirmar la orden
                ]);

                if ($esPersonalizado || $esProductoCustom) {
                    // Solo crear producción si la orden es confirmada, no si es borrador
                    if (! $guardarBorrador) {
                        Produccion::create([
                            'orden_item_id'    => $item->id,
                            'fecha_inicio'     => now()->toDateString(),
                            'fecha_compromiso' => null, // El supervisor asigna la fecha vía asignarFechas()
                            'estado'           => 'pendiente',
                        ]);
                    }
                } else {
                    // Reservar stock en la tienda de origen (puede ser otra tienda)
                    $varianteMarca = $specsExtra['variante_marca'] ?? '';
                    $varianteColor = $specsExtra['variante_color'] ?? '';
                    $motivo = "Orden #{$orden->id}" . ($varianteId && $specsExtra ? " ({$varianteMarca} - {$varianteColor})" : '');

                    if ($varianteId) {
                        InventarioVariante::where('variante_id', $varianteId)
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                        // Si hay combo, reservar también en inventario_variante_combinaciones
                        if ($comboConfigId) {
                            InventarioVarianteCombinacion::where('variante_id', $varianteId)
                                ->where('config_id', $comboConfigId)
                                ->where('tienda_id', $origenTiendaId)
                                ->increment('cantidad_reservada', $itemData['cantidad']);
                        }
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

            // --- 4. Registrar anticipo solo en órdenes confirmadas (no borradores) ---
            if (! $guardarBorrador && $data['anticipo_monto'] > 0) {
                $orden->pagos()->create([
                    'vendedor_id' => $request->user()->id,
                    'tipo'        => 'anticipo',
                    'monto'       => $data['anticipo_monto'],
                    'metodo'      => $data['anticipo_metodo'],
                    'referencia'  => $data['anticipo_referencia'] ?? null,
                ]);
            }

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

        $estadoFinal = $guardarBorrador ? 'borrador' : ($tieneItemsCotizacionPendiente ? 'pendiente_cotizacion' : 'pendiente_anticipo');

        try {
            event(new OrdenActualizada(
                $orden->id,
                (int) $tiendaId,
                $estadoFinal,
                $ordenCargada->cliente->nombre,
            ));
        } catch (\Throwable) {
            // Broadcasting failure never blocks the response
        }

        if (! $guardarBorrador) {
            $supervisores = Usuario::where('rol', 'supervisor')
                ->where('activo', true)
                ->where('id', '!=', $request->user()->id)
                ->get();

            foreach ($supervisores as $sup) {
                NotificacionService::crear(
                    'venta_nueva',
                    'Nueva venta registrada',
                    "Orden #{$orden->id} — {$ordenCargada->cliente->nombre} · $" . number_format($valorTotal, 0, ',', '.') . " COP",
                    ['orden_id' => $orden->id, 'tienda_id' => (int) $tiendaId, 'valor_total' => $valorTotal],
                    $sup->id,
                );

                if ($sup->notif_asignar_fecha && ! $tieneItemsCotizacionPendiente) {
                    NotificacionService::crear(
                        'asignar_fecha',
                        'Asignar fecha de entrega',
                        "Orden #{$orden->id} de {$ordenCargada->cliente->nombre} necesita fecha de entrega",
                        ['orden_id' => $orden->id],
                        $sup->id,
                    );
                }
            }

            // Notificar a facturadores sobre el anticipo inicial (solo si se registró uno)
            $facturadores = $data['anticipo_monto'] > 0
                ? Usuario::where('facturacion', true)->where('activo', true)->where('id', '!=', $request->user()->id)->get()
                : collect();

            if ($facturadores->isNotEmpty()) {
                $montoFormateado = '$ ' . number_format($data['anticipo_monto'], 0, ',', '.');
                foreach ($facturadores as $facturador) {
                    NotificacionService::crear(
                        tipo:      'abono_registrado',
                        titulo:    "Pago registrado – Orden #{$orden->id}",
                        mensaje:   "{$request->user()->nombre} registró un anticipo de {$montoFormateado} en la orden de {$ordenCargada->cliente->nombre}.",
                        datos:     ['orden_id' => $orden->id],
                        usuarioId: $facturador->id,
                    );
                }
            }
        }

        // Notificar cambio de inventario, detectar ventas cruzadas y alertar si sin stock
        $origenesExternos = [];
        $fabricaId = Tienda::where('es_fabrica', true)->value('id');
        $itemsFabrica = [];

        foreach ($data['items'] as $itemData) {
            if (! ($itemData['es_personalizado'] ?? false) && ! empty($itemData['producto_id'])) {
                $origenTiendaId = $itemData['tienda_origen_id'] ?? $tiendaId;
                event(new InventarioActualizado((int) $origenTiendaId, (int) $itemData['producto_id'], 'reserva'));
                $this->notificarSiSinStock((int) $itemData['producto_id'], (int) $origenTiendaId);

                if ($fabricaId && (int) $origenTiendaId === (int) $fabricaId) {
                    $itemsFabrica[] = $itemData;
                } elseif ($origenTiendaId && (int) $origenTiendaId !== (int) $tiendaId) {
                    $origenesExternos[] = (int) $origenTiendaId;
                }
            }
        }

        // Notificaciones de stock cruzado solo para órdenes confirmadas
        if (! $guardarBorrador) {
            if (!empty($itemsFabrica)) {
                $productoIds = array_column($itemsFabrica, 'producto_id');
                $productos = Producto::whereIn('id', $productoIds)->pluck('nombre', 'id');
                $resumen = collect($itemsFabrica)
                    ->map(fn($i) => ($productos[$i['producto_id']] ?? "Producto #{$i['producto_id']}") . " ({$i['cantidad']} ud.)")
                    ->implode(', ');

                $supervisores = Usuario::where('rol', 'supervisor')
                    ->where('activo', true)
                    ->where('id', '!=', $request->user()->id)
                    ->get();
                foreach ($supervisores as $sup) {
                    NotificacionService::crear(
                        'reserva_fabrica',
                        'Producto tomado de reserva',
                        "Orden #{$orden->id}: {$resumen}",
                        ['orden_id' => $orden->id],
                        $sup->id,
                    );
                }
            }

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
        }

        // Asignar número de orden secuencial solo a órdenes confirmadas (no borradores)
        if (! $guardarBorrador) {
            $this->asignarNumeroOrden($orden);
            $ordenCargada->numero_orden = $orden->numero_orden;
            ComisionController::crearParaOrden($orden);
        }

        // Enviar cotización por email solo en órdenes confirmadas (no borradores — el frontend llama reenviarCotizacion por separado)
        if (! $guardarBorrador && $ordenCargada->cliente->email) {
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
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $usuario = $request->user();

        $orden = Orden::with('cliente:id,nombre,email')->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $email = $request->input('email') ?? $orden->cliente->email;

        if (! $email) {
            return response()->json(['message' => 'El cliente no tiene email registrado.'], 422);
        }

        try {
            Mail::to($email)->send(new CotizacionMail($orden->id));
        } catch (\Throwable $e) {
            try { \Log::error('reenviarCotizacion: fallo', ['orden_id' => $orden->id, 'error' => $e->getMessage()]); } catch (\Throwable) {}
            return response()->json(['message' => 'No se pudo enviar el correo: ' . $e->getMessage()], 502);
        }

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
            'covendedor:id,nombre',
            'items.producto:id,nombre,categoria,precio_base,personalizable,foto_url,medidas,material',
            'items.produccion.pasos.completadoPor:id,nombre',
            'items.produccion.despachador:id,nombre',
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
        $orden->atrasado        = !in_array($orden->estado, ['entregado', 'cancelado']) &&
            $orden->items->some(fn($item) =>
                $item->fecha_entrega_prom &&
                $item->fecha_entrega_prom->lt(now()->startOfDay())
            );

        return response()->json($orden);
    }

    /**
     * POST /api/ordenes/{id}/confirmar-cotizacion
     * El vendedor confirma que el cliente aceptó el precio:
     * registra firma, anticipo y transiciona a pendiente_anticipo.
     */
    public function confirmarCotizacion(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::with('items')->findOrFail($id);

        if ($orden->estado !== 'pendiente_cotizacion') {
            return response()->json(['message' => 'La orden no está pendiente de cotización.'], 422);
        }

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $esPresencial = $orden->canal === 'fisica';

        $data = $request->validate([
            'firma_url'          => 'required|string|max:500',
            'factura_foto_url'   => 'nullable|string|max:500',
            'anexo_foto_url'     => ($esPresencial ? 'required' : 'nullable') . '|string|max:500',
            'anticipo_monto'     => 'required|numeric|min:0',
            'anticipo_metodo'    => 'required|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia' => 'nullable|string|max:100',
        ]);

        DB::transaction(function () use ($orden, $data, $usuario) {
            $orden->update([
                'firma_url'        => $data['firma_url'],
                'factura_foto_url' => $data['factura_foto_url'] ?? null,
                'anexo_foto_url'   => $data['anexo_foto_url'] ?? null,
                'estado'           => 'pendiente_anticipo',
            ]);

            if ($data['anticipo_monto'] > 0) {
                $orden->pagos()->create([
                    'vendedor_id' => $usuario->id,
                    'tipo'        => 'anticipo',
                    'monto'       => $data['anticipo_monto'],
                    'metodo'      => $data['anticipo_metodo'],
                    'referencia'  => $data['anticipo_referencia'] ?? null,
                ]);
            }
        });

        $orden->loadMissing(['cliente:id,nombre', 'tienda:id,nombre']);
        $clienteNombre = $orden->cliente->nombre ?? '';
        $tiendaId      = $orden->tienda_id;

        // Notificar supervisores: orden confirmada + asignar fecha
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('id', '!=', $request->user()->id)
            ->get();
        foreach ($supervisores as $sup) {
            NotificacionService::crear(
                'venta_nueva',
                'Cotización aceptada — orden confirmada',
                "Orden #{$orden->id} — {$clienteNombre} confirmó el precio",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $tiendaId],
                $sup->id,
            );

            if ($sup->notif_asignar_fecha) {
                NotificacionService::crear(
                    'asignar_fecha',
                    'Asignar fecha de entrega',
                    "Orden #{$orden->id} de {$clienteNombre} necesita fecha de entrega",
                    ['orden_id' => $orden->id],
                    $sup->id,
                );
            }
        }

        // Notificar facturadores si se registró anticipo
        if ($data['anticipo_monto'] > 0) {
            $facturadores = Usuario::where('facturacion', true)
                ->where('activo', true)
                ->where('id', '!=', $usuario->id)
                ->get();

            $montoFormateado = '$ ' . number_format($data['anticipo_monto'], 0, ',', '.');
            foreach ($facturadores as $facturador) {
                NotificacionService::crear(
                    'abono_registrado',
                    "Pago registrado – Orden #{$orden->id}",
                    "{$usuario->nombre} registró un anticipo de {$montoFormateado} en la orden de {$clienteNombre}.",
                    ['orden_id' => $orden->id],
                    $facturador->id,
                );
            }
        }

        event(new OrdenActualizada($orden->id, (int) $tiendaId, 'pendiente_anticipo', $clienteNombre));

        return response()->json(['message' => 'Cotización confirmada. Orden en pendiente de anticipo.']);
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
            'canal'                         => 'sometimes|nullable|in:fisica,whatsapp,instagram,facebook,pagina,red_social,otro',
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
            'items_eliminar'                => 'sometimes|nullable|array',
            'items_eliminar.*'              => 'integer|exists:orden_items,id',
            'items_nuevos'                  => 'sometimes|nullable|array',
            'items_nuevos.*.producto_id'    => 'required_with:items_nuevos|integer|exists:productos,id',
            'items_nuevos.*.cantidad'       => 'required_with:items_nuevos|integer|min:1',
            'items_nuevos.*.precio_unitario'=> 'required_with:items_nuevos|numeric|min:0',
            'items_nuevos.*.fecha_entrega_prom' => 'sometimes|nullable|date',
        ]);

        $orden = Orden::with(['items', 'items.producto:id,nombre'])->findOrFail($id);

        if (in_array($usuario->rol, ['vendedor', 'ebanista']) && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if (! in_array($orden->estado, ['borrador', 'pendiente_anticipo', 'en_produccion'])) {
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

            // ── Eliminar ítems ────────────────────────────────────────────────
            if (! empty($data['items_eliminar'])) {
                $idsDeOrden      = $orden->items->pluck('id')->toArray();
                $idsAEliminar    = array_intersect($data['items_eliminar'], $idsDeOrden);
                $itemsQueQuedan  = count($idsDeOrden) - count($idsAEliminar);
                $hayNuevos       = ! empty($data['items_nuevos']);

                if ($itemsQueQuedan < 1 && ! $hayNuevos) {
                    abort(422, 'La orden debe conservar al menos un ítem.');
                }

                $itemsAEliminar = OrdenItem::with(['produccion.pasos', 'producto:id,nombre'])
                    ->whereIn('id', $idsAEliminar)
                    ->get();

                foreach ($itemsAEliminar as $item) {
                    $nombreProd = $item->producto?->nombre ?? "Ítem #{$item->id}";
                    $origenId   = $item->tienda_origen_id ?? $orden->tienda_id;

                    // Bloquear si la producción ya avanzó
                    if ($item->produccion) {
                        $avanzado = $item->produccion->pasos->contains(
                            fn ($p) => in_array($p->estado, ['en_proceso', 'completado'])
                        );
                        if ($avanzado) {
                            abort(422, "No se puede quitar \"{$nombreProd}\" porque su producción ya está en curso.");
                        }
                        $item->produccion->pasos()->delete();
                        $item->produccion->delete();
                    }

                    // Liberar reserva de inventario (solo ítems no personalizados)
                    if (! $item->es_personalizado && $item->producto_id) {
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', max(0, (int) $item->cantidad));
                        InventarioMovimiento::create([
                            'producto_id' => $item->producto_id,
                            'tienda_id'   => $origenId,
                            'tipo'        => 'liberacion',
                            'cantidad'    => (int) $item->cantidad,
                            'motivo'      => "Edición orden #{$orden->id} — ítem eliminado",
                            'usuario_id'  => $usuario->id,
                        ]);
                    }

                    $cambios[] = [
                        'campo'   => "item_{$item->id}_eliminado",
                        'label'   => 'Ítem eliminado',
                        'antes'   => "{$nombreProd} × {$item->cantidad}",
                        'despues' => null,
                    ];
                    $item->delete();
                }

                $orden->load('items');
            }

            // ── Agregar ítems nuevos ──────────────────────────────────────────
            if (! empty($data['items_nuevos'])) {
                foreach ($data['items_nuevos'] as $nuevoData) {
                    $productoId = (int) $nuevoData['producto_id'];
                    $cantidad   = (int) $nuevoData['cantidad'];
                    $precio     = (float) $nuevoData['precio_unitario'];
                    $origenId   = (int) $orden->tienda_id;

                    // Verificar stock disponible
                    $inv        = Inventario::where('producto_id', $productoId)->where('tienda_id', $origenId)->lockForUpdate()->first();
                    $stockLibre = $inv ? ($inv->cantidad_disponible - $inv->cantidad_reservada) : 0;
                    if ($stockLibre < $cantidad) {
                        $nomProd = Producto::find($productoId)?->nombre ?? "Producto #{$productoId}";
                        abort(422, "Stock insuficiente para \"{$nomProd}\". Libre: {$stockLibre}, necesario: {$cantidad}.");
                    }

                    $nuevoItem = OrdenItem::create([
                        'orden_id'           => $orden->id,
                        'producto_id'        => $productoId,
                        'cantidad'           => $cantidad,
                        'precio_unitario'    => $precio,
                        'es_personalizado'   => false,
                        'tienda_origen_id'   => $origenId,
                        'fecha_entrega_prom' => $nuevoData['fecha_entrega_prom'] ?? null,
                    ]);

                    Inventario::where('producto_id', $productoId)->where('tienda_id', $origenId)
                        ->increment('cantidad_reservada', $cantidad);
                    InventarioMovimiento::create([
                        'producto_id' => $productoId,
                        'tienda_id'   => $origenId,
                        'tipo'        => 'reserva',
                        'cantidad'    => $cantidad,
                        'motivo'      => "Edición orden #{$orden->id} — ítem agregado",
                        'usuario_id'  => $usuario->id,
                    ]);

                    $nomProd   = Producto::find($productoId)?->nombre ?? "Producto #{$productoId}";
                    $cambios[] = [
                        'campo'   => "item_nuevo_{$nuevoItem->id}",
                        'label'   => 'Ítem agregado',
                        'antes'   => null,
                        'despues' => "{$nomProd} × {$cantidad} @ $" . number_format($precio, 0, ',', '.'),
                    ];
                }

                $orden->load('items');
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
     * POST /api/ordenes/{id}/completar-borrador
     *
     * Completa una orden en estado borrador: registra la firma del cliente,
     * el anticipo y la transiciona a pendiente_anticipo o pendiente_cotizacion.
     */
    public function completarBorrador(Request $request, int $id)
    {
        $orden = Orden::with('items.produccion')->findOrFail($id);

        if ($orden->estado !== 'borrador') {
            return response()->json(['message' => 'La orden no está en borrador.'], 422);
        }

        $usuario = $request->user();
        if ($usuario->rol !== 'supervisor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No tienes permiso para completar esta orden.'], 403);
        }

        $esPresencial = $orden->canal === 'fisica';

        $data = $request->validate([
            'firma_url'           => 'required|string|max:500',
            'anticipo_monto'      => 'required|numeric|min:0',
            'anticipo_metodo'     => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia' => 'nullable|string|max:100',
            'notas'               => 'nullable|string|max:1000',
            'factura_foto_url'    => 'required|string|max:500',
            'anexo_foto_url'      => ($esPresencial ? 'required' : 'nullable') . '|string|max:500',
            'departamento_envio'  => 'required|string|max:100',
            'ciudad_envio'        => 'required|string|max:100',
            'direccion_envio'     => 'required|string|max:300',
        ]);

        $tieneItemsCotizacion = $orden->items->contains(
            fn($i) => $i->es_personalizado && $i->precio_unitario == 0
        );

        // No se fuerza un mínimo — el vendedor puede poner cualquier monto ≥ 0

        DB::transaction(function () use ($orden, $data, $tieneItemsCotizacion, $usuario) {
            $nuevoEstado = $tieneItemsCotizacion ? 'pendiente_cotizacion' : 'pendiente_anticipo';

            $orden->update([
                'estado'             => $nuevoEstado,
                'firma_url'          => $data['firma_url']          ?? $orden->firma_url,
                'notas'              => $data['notas']              ?? $orden->notas,
                'factura_foto_url'   => $data['factura_foto_url']   ?? $orden->factura_foto_url,
                'anexo_foto_url'     => $data['anexo_foto_url']     ?? $orden->anexo_foto_url,
                'departamento_envio' => $data['departamento_envio'] ?? $orden->departamento_envio,
                'ciudad_envio'       => $data['ciudad_envio']       ?? $orden->ciudad_envio,
                'direccion_envio'    => $data['direccion_envio']    ?? $orden->direccion_envio,
            ]);

            // Crear registros de producción para los items personalizados del borrador
            foreach ($orden->items->where('es_personalizado', true) as $item) {
                if (! $item->produccion) {
                    Produccion::create([
                        'orden_item_id'    => $item->id,
                        'fecha_inicio'     => now()->toDateString(),
                        'fecha_compromiso' => null, // El supervisor asigna la fecha vía asignarFechas()
                        'estado'           => 'pendiente',
                    ]);
                }
            }

            if (($data['anticipo_monto'] ?? 0) > 0) {
                $orden->pagos()->create([
                    'vendedor_id' => $usuario->id,
                    'tipo'        => 'anticipo',
                    'monto'       => $data['anticipo_monto'],
                    'metodo'      => $data['anticipo_metodo'] ?? 'efectivo',
                    'referencia'  => $data['anticipo_referencia'] ?? null,
                ]);
            }
        });

        $ordenFresh = $orden->fresh()->load([
            'cliente:id,nombre,cedula,telefono',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
            'items.produccion',
            'pagos',
        ]);

        $ordenFresh->total_pagado    = $ordenFresh->totalPagado();
        $ordenFresh->saldo_pendiente = $ordenFresh->saldoPendiente();

        // Asignar número de orden secuencial al confirmar el borrador
        $this->asignarNumeroOrden($orden);
        $ordenFresh->numero_orden = $orden->numero_orden;
        ComisionController::crearParaOrden($orden);

        // Notify supervisors of the now-confirmed order
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->get();

        $tieneItemsCotizPendiente = $ordenFresh->items->contains(
            fn($i) => $i->es_personalizado && (float) $i->precio_unitario === 0.0
        );

        foreach ($supervisores as $sup) {
            NotificacionService::crear(
                'venta_nueva',
                'Nueva venta registrada',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} · $" . number_format($orden->valor_total, 0, ',', '.') . " COP",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id, 'valor_total' => $orden->valor_total],
                $sup->id,
            );

            if ($sup->notif_asignar_fecha && ! $tieneItemsCotizPendiente) {
                NotificacionService::crear(
                    'asignar_fecha',
                    'Asignar fecha de entrega',
                    "Orden #{$orden->id} de {$ordenFresh->cliente->nombre} necesita fecha de entrega",
                    ['orden_id' => $orden->id],
                    $sup->id,
                );
            }
        }

        // Notificar a facturadores del anticipo si se registró uno
        $anticipo = $ordenFresh->pagos->where('tipo', 'anticipo')->first();
        if ($anticipo) {
            $facturadores = Usuario::where('facturacion', true)
                ->where('activo', true)
                ->where('id', '!=', $usuario->id)
                ->get();
            $montoFormateado = '$ ' . number_format($anticipo->monto, 0, ',', '.');
            foreach ($facturadores as $facturador) {
                NotificacionService::crear(
                    tipo:      'abono_registrado',
                    titulo:    'Pago registrado – Orden #' . ($orden->numero_orden ?? $orden->id),
                    mensaje:   "{$usuario->nombre} confirmó un anticipo de {$montoFormateado} en la orden de {$ordenFresh->cliente->nombre}.",
                    datos:     ['orden_id' => $orden->id],
                    usuarioId: $facturador->id,
                );
            }
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

        if (in_array($usuario->rol, ['vendedor', 'ebanista'])) {
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

        // Transiciones válidas (despacho controla listo_entrega y en_camino)
        $transiciones = [
            'borrador'              => ['cancelado'],
            'pendiente_cotizacion'  => ['cancelado'],
            'pendiente_anticipo'    => ['en_produccion', 'listo_entrega', 'cancelado'],
            'en_produccion'         => ['listo_entrega', 'cancelado'],
        ];
        $permitidos = $transiciones[$estadoAnterior] ?? [];
        if (!empty($permitidos) && !in_array($estadoNuevo, $permitidos)) {
            return response()->json([
                'message' => "No se puede pasar de \"{$estadoAnterior}\" a \"{$estadoNuevo}\".",
            ], 422);
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
                        if ($item->combo_config_id) {
                            InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                                ->where('config_id', $item->combo_config_id)
                                ->where('tienda_id', $origenId)
                                ->update([
                                    'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                    'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                                ]);
                        }
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
                        if ($item->combo_config_id) {
                            InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                                ->where('config_id', $item->combo_config_id)
                                ->where('tienda_id', $origenId)
                                ->decrement('cantidad_reservada', $item->cantidad);
                        }
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

            // Cancelar registros de producción activos al cancelar la orden
            if ($estadoNuevo === 'cancelado' && $estadoAnterior !== 'cancelado') {
                \App\Models\Produccion::whereHas('ordenItem', fn($q) => $q->where('orden_id', $orden->id))
                    ->whereNotIn('estado', ['cancelado', 'completado'])
                    ->update(['estado' => 'cancelado']);
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

            // Notificar a otros supervisores (excluir al que hizo el cambio para evitar auto-notificación)
            $otrosSupervisores = Usuario::where('rol', 'supervisor')
                ->where('activo', true)
                ->where('id', '!=', $usuario->id)
                ->get();

            foreach ($otrosSupervisores as $sup) {
                NotificacionService::crear(
                    'listo_entrega',
                    'Orden lista para entrega',
                    "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} está lista para despachar",
                    ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
                    $sup->id,
                );
            }

            // Notificar al vendedor
            NotificacionService::crear(
                'listo_entrega',
                'Tu pedido está listo para entrega',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} está lista para ser despachada",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'entregado') {
            // Notificar a otros supervisores (no al que hizo el cambio)
            $otrosSupervisores = Usuario::where('rol', 'supervisor')
                ->where('activo', true)
                ->where('id', '!=', $usuario->id)
                ->get();
            foreach ($otrosSupervisores as $sup) {
                NotificacionService::crear(
                    'entregado',
                    'Orden entregada',
                    "Orden #{$orden->id} entregada a {$ordenFresh->cliente->nombre}",
                    ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
                    $sup->id,
                );
            }
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

            // Cerrar consultas de costo pendientes para evitar que queden huérfanas
            \App\Models\ConsultaCosto::where('orden_id', $orden->id)
                ->where('estado', 'pendiente')
                ->each(function ($consulta) use ($orden, $ordenFresh) {
                    $consulta->update(['estado' => 'respondida', 'respondido_at' => now()]);
                    // Notificar al cotizador que la orden fue cancelada
                    NotificacionService::crear(
                        'cancelado',
                        'Cotización cancelada',
                        "La orden #{$orden->id} de {$ordenFresh->cliente->nombre} fue cancelada. La consulta de costo ya no aplica.",
                        ['consulta_id' => $consulta->id, 'orden_id' => $orden->id],
                        $consulta->asignado_a_id,
                    );
                });
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

        // Verificar que todos los items pertenecen a esta orden
        $itemIdsOrden = $orden->items->pluck('id')->all();
        foreach ($data['items'] as $itemData) {
            if (!in_array($itemData['id'], $itemIdsOrden)) {
                return response()->json(['message' => "El ítem #{$itemData['id']} no pertenece a esta orden."], 422);
            }
        }

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

        // Solo permitir URLs de dominios de almacenamiento confiables
        $dominiosPermitidos = ['res.cloudinary.com', 'cloudinary.com', 'amazonaws.com', 's3.'];
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $esPermitida = collect($dominiosPermitidos)->contains(fn($d) => str_contains($host, $d));

        if (! $esPermitida) {
            \Log::warning('urlToBase64: URL de dominio no permitido', ['url' => $url]);
            return null;
        }

        try {
            $bytes = file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
                'ssl'  => ['verify_peer' => true],
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

    // Tiendas que comparten secuencia de numeración por grupo
    private const GRUPOS_SECUENCIA = [
        'pereira' => ['Decasa Unicentro Pereira', 'Decasa Circunvalar'],
    ];

    private function asignarNumeroOrden(Orden $orden): void
    {
        $tiendaNombre = DB::table('tiendas')->where('id', $orden->tienda_id)->value('nombre');

        $grupo = null;
        foreach (self::GRUPOS_SECUENCIA as $key => $nombres) {
            if (in_array($tiendaNombre, $nombres, true)) {
                $grupo = $key;
                break;
            }
        }

        DB::transaction(function () use ($orden, $grupo) {
            if ($grupo) {
                // Incrementar contador atómico del grupo con bloqueo
                $actual = DB::table('orden_secuencias')
                    ->where('grupo', $grupo)
                    ->lockForUpdate()
                    ->value('ultimo_numero') ?? 0;

                $siguiente = $actual + 1;

                DB::table('orden_secuencias')
                    ->where('grupo', $grupo)
                    ->update(['ultimo_numero' => $siguiente]);

                $orden->update([
                    'numero_orden'    => $siguiente,
                    'grupo_secuencia' => $grupo,
                ]);
            } else {
                // Sin grupo definido: MAX global (comportamiento previo)
                $max = DB::table('ordenes')->lockForUpdate()->max('numero_orden') ?? 0;
                $orden->update(['numero_orden' => $max + 1]);
            }
        });
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
