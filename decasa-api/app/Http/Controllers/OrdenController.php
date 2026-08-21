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
use App\Support\ConvierteImagenesPdf;
use App\Support\StockVariantes;
use App\Services\NumeracionOrdenes;
use App\Support\PdfOrdenUnaHoja;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrdenController extends Controller
{
    use ConvierteImagenesPdf;

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
            'vendedor:id,nombre,independiente',
            'items.produccion.pasoActual',
        ])->withSum('pagos', 'monto')
            // Las cotizaciones tienen su propio módulo: no se mezclan con órdenes.
            ->where('estado', '!=', 'cotizacion');

        if ($usuario->rol === 'vendedor' && ! $usuario->ve_todas_ordenes) {
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
        // Apartado de órdenes con descuento especial: ?serie=FV2 solo esas, ?serie=normales
        // solo las que llevan consecutivo corriente.
        if ($v = $request->query('serie')) {
            $v === 'normales'
                ? $query->whereNull('serie')
                : $query->where('serie', strtoupper($v));
        }
        if ($search = $request->query('search')) {
            $limpio = ltrim(trim($search), '#');           // permite escribir "#123"
            $term   = '%' . mb_strtolower($limpio) . '%';
            // "FV2-3" o "fv2 3": buscar en la serie especial. La serie puede
            // llevar dígitos (FV2), por eso el separador es obligatorio.
            // El prefijo puede ser de una sola letra: la serie de restauración
            // es "R", y con el mínimo en dos "R-1092" no se encontraba.
            $serieNum = null;
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]{0,9})[\s\-]+(\d+)$/', $limpio, $m)) {
                $serieNum = ['serie' => strtoupper($m[1]), 'numero' => (int) $m[2]];
            }

            $query->where(function ($q) use ($term, $limpio, $serieNum) {
                $q->whereHas('cliente', fn($c) => $c->whereRaw('LOWER(nombre) LIKE ?', [$term]))
                  ->orWhereRaw('LOWER(numero_orden) LIKE ?', [$term]);
                if (is_numeric($limpio)) {
                    $q->orWhere('id', (int) $limpio);
                }
                if ($serieNum) {
                    $q->orWhere(fn($q2) => $q2->where('serie', $serieNum['serie'])
                                              ->where('serie_numero', $serieNum['numero']));
                }
            });
        }

        // Las que uno fijó van de primeras. El orden se arma en la consulta y no
        // al pintar, porque la lista viene paginada: si se ordenara después,
        // una orden fijada que cayó en la página 3 nunca subiría.
        $uid = $request->user()->id;
        $ordenes = $query
            ->addSelect([
                'fijada' => DB::table('orden_fijadas')
                    ->selectRaw('1')
                    ->whereColumn('orden_fijadas.orden_id', 'ordenes.id')
                    ->where('orden_fijadas.usuario_id', $uid)
                    ->limit(1),
            ])
            ->orderByRaw('(SELECT 1 FROM orden_fijadas f WHERE f.orden_id = ordenes.id AND f.usuario_id = ?) IS NOT NULL DESC', [$uid])
            ->orderByDesc('created_at')
            ->paginate(20);

        $hoy = now()->startOfDay();

        $ordenes->getCollection()->transform(function ($o) use ($hoy) {
            $o->fijada          = (bool) $o->fijada;
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
            // 0 es válido: el formulario ofrece "$0 — sin anticipo" y la orden
            // queda pendiente de pago. Con min:1 esa opción devolvía un error de
            // validación en inglés sobre un campo que el vendedor no ve, y la
            // conclusión era "solo me deja crear la orden con el 50%".
            'anticipo_pct'                  => 'nullable|numeric|min:0|max:100',
            'descuento_total'               => 'nullable|numeric|min:0',
            'fecha_sugerida_vendedor'       => 'nullable|date',
            'notas'                              => 'nullable|string|max:1000',
            'factura_foto_url'                   => 'nullable|string|max:500',
            'firma_url'                          => 'nullable|string|max:500',
            'anexo_foto_url'                     => 'nullable|string|max:500',
            'departamento_envio'                 => 'nullable|string|max:100',
            'ciudad_envio'                       => 'nullable|string|max:100',
            'direccion_envio'                    => 'nullable|string|max:300',
            'anticipo_monto'                       => 'required|numeric|min:0',
            'anticipo_metodo'                      => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'                  => 'nullable|string|max:100',
            'anticipo_pagos'                       => 'nullable|array|min:1',
            'anticipo_pagos.*.monto'               => 'required_with:anticipo_pagos|numeric|min:0.01',
            'anticipo_pagos.*.metodo'              => 'required_with:anticipo_pagos|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_pagos.*.referencia'          => 'nullable|string|max:100',
            'guardar_borrador'                     => 'nullable|boolean',
            'entrega_inmediata'                    => 'nullable|boolean',
            // Orden con descuento especial (serie FV2): numeración propia, sigue contando
            // como venta y generando comisión.
            'es_fv2'                               => 'nullable|boolean',
            'tienda_abonada_id'                    => 'nullable|integer|exists:tiendas,id',
            'motivo_serie'                         => 'nullable|string|max:300',
            // Descuento que solo vale si paga en efectivo o transferencia. Se
            // acepta en pesos o en %; el monto tiene prioridad porque es lo que
            // el vendedor escribió y no debe recalcularse desde un % redondeado.
            'descuento_condicionado_pct'           => 'nullable|numeric|min:0|max:100',
            'descuento_condicionado_monto'         => 'nullable|numeric|min:0',
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
            'items.*.fabricar_pedido'            => 'nullable|boolean',
            'items.*.es_restauracion'            => 'nullable|boolean',
            'items.*.es_regalo'                  => 'nullable|boolean',
            'items.*.usa_stock_tienda'           => 'nullable|boolean',
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
        $esFv2           = $request->boolean('es_fv2', false);

        // Abonarle media venta a una tienda es cosa de vendedores independientes:
        // van a un almacen, les pasan el contacto y cierran ellos. Un vendedor de
        // tienda ya vende para la suya, no tiene a quien abonarle nada.
        $tiendaAbonadaId = $data['tienda_abonada_id'] ?? null;
        if ($tiendaAbonadaId) {
            // store() marca como vendedor al usuario que la crea, asi que el
            // dueno de la venta es quien esta logueado.
            if (! $request->user()->independiente) {
                return response()->json([
                    'message' => 'Solo un vendedor independiente puede abonarle la venta a una tienda.',
                ], 422);
            }
            if ((int) $tiendaAbonadaId === (int) $tiendaId) {
                return response()->json([
                    'message' => 'La tienda que se lleva la mitad no puede ser la misma de la orden.',
                ], 422);
            }
            // Las dos formas de compartir a la vez no estan definidas y no
            // cuadran: la venta se repartiria entre tres por dos reglas
            // distintas y sumaria mas de lo que vale.
            if ($request->boolean('es_compartida')) {
                return response()->json([
                    'message' => 'Una venta se comparte con otro asesor o con un almacén, no con los dos.',
                ], 422);
            }
        }

        // Venta directa: el cliente paga (total o parcial) y se lleva los productos en
        // el acto. La orden nace 'entregado', descuenta stock de una y no pasa por
        // supervisor ni despacho. Solo válida para productos de inventario.
        $entregaInmediata = ! $guardarBorrador && $request->boolean('entrega_inmediata', false);
        if ($entregaInmediata) {
            $tienePersonalizados = collect($data['items'])->contains(
                fn($i) => ($i['es_personalizado'] ?? false) || empty($i['producto_id'])
            );
            if ($tienePersonalizados) {
                return response()->json([
                    'message' => 'La entrega inmediata solo aplica a productos de inventario. Quita los ítems personalizados, de diseño especial o para fabricar.',
                ], 422);
            }
        }

        // Calcular valor total server-side (subtotal de ítems menos descuento global)
        $subtotalItems = collect($data['items'])->sum(
            fn ($i) => $i['cantidad'] * $i['precio_unitario']
        );
        $descuentoTotal = min((float) ($data['descuento_total'] ?? 0), $subtotalItems);
        $baseCondicionado = $subtotalItems - $descuentoTotal;

        // Descuento por pagar en efectivo o transferencia. Se calcula sobre la base
        // ya rebajada para no descontar dos veces sobre lo mismo.
        //
        // Si algún método del anticipo inicial no es efectivo ni transferencia,
        // el descuento no se otorga desde el principio: la condición ya está
        // incumplida y no tiene sentido darlo para quitarlo en el mismo acto.
        $montoCondicionadoPedido = isset($data['descuento_condicionado_monto'])
            ? (float) $data['descuento_condicionado_monto']
            : null;
        $pctCondicionado = $montoCondicionadoPedido !== null
            ? ($baseCondicionado > 0 ? $montoCondicionadoPedido / $baseCondicionado * 100 : 0)
            : (float) ($data['descuento_condicionado_pct'] ?? 0);

        $metodosAnticipo = ! empty($data['anticipo_pagos'])
            ? array_column($data['anticipo_pagos'], 'metodo')
            : (($data['anticipo_monto'] ?? 0) > 0 ? [$data['anticipo_metodo'] ?? 'efectivo'] : []);

        $anticipoPierdeDescuento = collect($metodosAnticipo)
            ->contains(fn($m) => Orden::metodoPierdeDescuento($m));

        // Si vino el monto se respeta tal cual; si vino el %, se calcula. Nunca
        // se recalcula un monto recibido a partir de su % derivado: perdería pesos.
        $descuentoCondicionado = 0.0;
        if (! $anticipoPierdeDescuento) {
            $descuentoCondicionado = $montoCondicionadoPedido !== null
                ? round($montoCondicionadoPedido, 2)
                : round($baseCondicionado * $pctCondicionado / 100, 2);

            $descuentoCondicionado = max(0.0, min($descuentoCondicionado, $baseCondicionado));
        }

        $valorTotal = $baseCondicionado - $descuentoCondicionado;

        // Una restauración no le suma a la meta de la tienda y una venta sí,
        // así que no pueden ir juntas: la orden entera es de una cosa o de la
        // otra. Si se mezclaran no habría forma de decir cuánto cuenta.
        $conRest = collect($data['items'])->filter(fn ($i) => ! empty($i['es_restauracion']))->count();
        if ($conRest > 0 && $conRest < count($data['items'])) {
            return response()->json([
                'message' => 'Una orden es de venta o de restauración, no las dos. ' .
                             'Haz la restauración en una orden aparte.',
            ], 422);
        }

        // Detectar si hay ítems personalizados sin precio (cotización pendiente)
        // Un obsequio tambien va en $0, y no espera ningun precio. Antes se
        // miraba solo el precio, asi que una venta con un regalo quedaba
        // atrapada esperando una cotizacion que nadie iba a mandar.
        $tieneItemsCotizacionPendiente = collect($data['items'])->contains(
            fn($i) => ($i['es_personalizado'] ?? false)
                      && (($i['precio_unitario'] ?? 0) == 0)
                      && ! ($i['es_regalo'] ?? false)
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

        $orden = DB::transaction(function () use ($data, $tiendaId, $anticupoPct, $valorTotal, $descuentoTotal, $request, $tieneItemsCotizacionPendiente, $guardarBorrador, $entregaInmediata, $esFv2, $descuentoCondicionado, $pctCondicionado, $tiendaAbonadaId) {

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
                'estado'            => $guardarBorrador
                    ? 'borrador'
                    : ($entregaInmediata
                        ? 'entregado'
                        : ($tieneItemsCotizacionPendiente ? 'pendiente_cotizacion' : 'pendiente_anticipo')),
                'listo_entrega_at'  => $entregaInmediata ? now() : null,
                'valor_total'       => $valorTotal,
                'descuento_total'   => $descuentoTotal,
                'descuento_condicionado'     => $descuentoCondicionado,
                'descuento_condicionado_pct' => $descuentoCondicionado > 0 ? $pctCondicionado : null,
                'anticipo_pct'      => $anticupoPct,
                'notas'             => $data['notas'] ?? null,
                // Lo que el vendedor le prometió al cliente. Es referencia para
                // quien asigna la fecha real, no la fecha de entrega.
                'fecha_sugerida_vendedor' => $data['fecha_sugerida_vendedor'] ?? null,
                'es_compartida'     => $data['es_compartida'] ?? false,
                'covendedor_id'     => ($data['es_compartida'] ?? false) ? ($data['covendedor_id'] ?? null) : null,
                'factura_foto_url'  => $data['factura_foto_url'] ?? null,
                'firma_url'           => $data['firma_url'] ?? null,
                'anexo_foto_url'      => $data['anexo_foto_url'] ?? null,
                'departamento_envio' => $data['departamento_envio'] ?? null,
                'ciudad_envio'       => $data['ciudad_envio'] ?? null,
                'direccion_envio'    => $data['direccion_envio'] ?? null,
                // Serie especial: el número FV2-N se asigna al confirmar la orden
                'tienda_abonada_id'  => $tiendaAbonadaId,
                'serie'              => $esFv2 ? Orden::SERIE_FV2 : null,
                'motivo_serie'       => $esFv2 ? ($data['motivo_serie'] ?? null) : null,
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
                    'fabricar_pedido'       => (bool) ($itemData['fabricar_pedido'] ?? false) && ! $esProductoCustom,
                    'es_restauracion'       => (bool) ($itemData['es_restauracion'] ?? false),
                    'es_regalo'             => (bool) ($itemData['es_regalo'] ?? false),
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
                } elseif ($entregaInmediata) {
                    // Venta directa: el producto sale ya → descontar stock disponible
                    // (sin reservar, porque el cliente se lo lleva en el acto).
                    if ($varianteId) {
                        InventarioVariante::where('variante_id', $varianteId)
                            ->where('tienda_id', $origenTiendaId)
                            ->decrement('cantidad_disponible', $itemData['cantidad']);
                        if ($comboConfigId) {
                            InventarioVarianteCombinacion::where('variante_id', $varianteId)
                                ->where('config_id', $comboConfigId)
                                ->where('tienda_id', $origenTiendaId)
                                ->decrement('cantidad_disponible', $itemData['cantidad']);
                        }
                        Inventario::where('producto_id', $itemData['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->decrement('cantidad_disponible', $itemData['cantidad']);
                    } else {
                        Inventario::where('producto_id', $itemData['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->decrement('cantidad_disponible', $itemData['cantidad']);
                    }

                    // Bajó el stock base: el reparto por tela/medida tiene que
                    // seguir cabiendo dentro de lo que quedó. Sin esto, vender
                    // sin elegir variante dejaba unidades marcadas de un color
                    // que ya no existe en la tienda.
                    StockVariantes::cuadrar(
                        (int) $itemData['producto_id'], (int) $origenTiendaId,
                        "Venta directa orden #{$orden->id}"
                    );

                    InventarioMovimiento::create([
                        'producto_id' => $itemData['producto_id'],
                        'tienda_id'   => $origenTiendaId,
                        'tipo'        => 'salida',
                        'cantidad'    => $itemData['cantidad'],
                        'motivo'      => "Venta directa orden #{$orden->id}",
                        'usuario_id'  => $request->user()->id,
                    ]);
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
                $uid = $request->user()->id;
                if (!empty($data['anticipo_pagos'])) {
                    foreach ($data['anticipo_pagos'] as $p) {
                        if (($p['monto'] ?? 0) > 0) {
                            $orden->pagos()->create(['vendedor_id' => $uid, 'tipo' => 'anticipo', 'monto' => $p['monto'], 'metodo' => $p['metodo'], 'referencia' => $p['referencia'] ?? null]);
                        }
                    }
                } else {
                    $orden->pagos()->create(['vendedor_id' => $uid, 'tipo' => 'anticipo', 'monto' => $data['anticipo_monto'], 'metodo' => $data['anticipo_metodo'], 'referencia' => $data['anticipo_referencia'] ?? null]);
                }
            }

            return $orden;
        });

        $ordenCargada = $orden->load([
            'cliente:id,nombre,cedula,telefono',
            'vendedor:id,nombre,independiente',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
            'items.variante', 'items.comboConfig.tipo', 'items.comboConfig.opcion',
            'items.tiendaOrigen:id,nombre',
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

        // El consecutivo se gasta cuando la venta es de verdad.
        //
        // Un borrador no lo toma, y una orden que está esperando el precio del
        // taller tampoco: si el cliente no acepta, ese número quedaría quemado
        // para siempre. Se le asigna al confirmar que aceptó
        // (confirmarCotizacion), y ahí mismo nace la comisión.
        $esperandoPrecio = $estadoFinal === 'pendiente_cotizacion';

        if (! $guardarBorrador && ! $esperandoPrecio) {
            self::asignarNumeroOrden($orden);
            $ordenCargada->numero_orden = $orden->numero_orden;
            ComisionController::crearParaOrden($orden);
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
                    "Orden {$orden->referencia} — {$ordenCargada->cliente->nombre} · $" . number_format($valorTotal, 0, ',', '.') . " COP",
                    ['orden_id' => $orden->id, 'tienda_id' => (int) $tiendaId, 'valor_total' => $valorTotal],
                    $sup->id,
                );

                if ($sup->notif_asignar_fecha && ! $tieneItemsCotizacionPendiente) {
                    NotificacionService::crear(
                        'asignar_fecha',
                        'Asignar fecha de entrega',
                        "Orden {$orden->referencia} de {$ordenCargada->cliente->nombre} necesita fecha de entrega",
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
                        titulo:    "Pago registrado – Orden {$orden->referencia}",
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
                // Apartar producto no es venderlo: el aviso de "se acabó" sale
                // cuando el producto sale del inventario, al entregarlo.
                event(new InventarioActualizado((int) $origenTiendaId, (int) $itemData['producto_id'], 'reserva'));

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
                        "Orden {$orden->referencia}: {$resumen}",
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
                        "Orden {$orden->referencia} - {$productosStr}",
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

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id && ! $usuario->ve_todas_ordenes) {
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
            'vendedor:id,nombre,independiente',
            'tienda:id,nombre',
            'covendedor:id,nombre',
            'items.producto:id,nombre,categoria,precio_base,personalizable,foto_url,medidas,material',
            'items.variante', 'items.comboConfig.tipo', 'items.comboConfig.opcion',
            'items.tiendaOrigen:id,nombre',
            'items.produccion.pasos.completadoPor:id,nombre',
            'items.produccion.despachador:id,nombre',
            'pagos.facturacionTomadaPor:id,nombre',
            'ediciones.usuario:id,nombre',
        ])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id && ! $usuario->ve_todas_ordenes) {
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

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id && ! $usuario->ve_todas_ordenes) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $esPresencial = $orden->canal === 'fisica';

        $data = $request->validate([
            'firma_url'          => 'required|string|max:500',
            'factura_foto_url'   => 'nullable|string|max:500',
            'anexo_foto_url'     => ($esPresencial ? 'required' : 'nullable') . '|string|max:500',
            'anticipo_monto'              => 'required|numeric|min:0',
            'anticipo_metodo'             => 'required|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'         => 'nullable|string|max:100',
            'anticipo_pagos'              => 'nullable|array|min:1',
            'anticipo_pagos.*.monto'      => 'required_with:anticipo_pagos|numeric|min:0.01',
            'anticipo_pagos.*.metodo'     => 'required_with:anticipo_pagos|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_pagos.*.referencia' => 'nullable|string|max:100',
        ]);

        DB::transaction(function () use ($orden, $data, $usuario) {
            $orden->update([
                'firma_url'        => $data['firma_url'],
                'factura_foto_url' => $data['factura_foto_url'] ?? null,
                'anexo_foto_url'   => $data['anexo_foto_url'] ?? null,
                'estado'           => 'pendiente_anticipo',
            ]);

            if ($data['anticipo_monto'] > 0) {
                if (!empty($data['anticipo_pagos'])) {
                    foreach ($data['anticipo_pagos'] as $p) {
                        if (($p['monto'] ?? 0) > 0) {
                            $orden->pagos()->create(['vendedor_id' => $usuario->id, 'tipo' => 'anticipo', 'monto' => $p['monto'], 'metodo' => $p['metodo'], 'referencia' => $p['referencia'] ?? null]);
                        }
                    }
                } else {
                    $orden->pagos()->create(['vendedor_id' => $usuario->id, 'tipo' => 'anticipo', 'monto' => $data['anticipo_monto'], 'metodo' => $data['anticipo_metodo'], 'referencia' => $data['anticipo_referencia'] ?? null]);
                }
            }
        });

        // Aquí sí es una venta: el cliente aceptó el precio. Ahora toma el
        // consecutivo y nace la comisión. Mientras esperaba el precio no tenía
        // número, para no quemar uno si el cliente decía que no.
        if (! $orden->numero_orden) {
            self::asignarNumeroOrden($orden);
            ComisionController::crearParaOrden($orden->fresh());
        }

        $orden->refresh();
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
                "Orden {$orden->referencia} — {$clienteNombre} confirmó el precio",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $tiendaId],
                $sup->id,
            );

            if ($sup->notif_asignar_fecha) {
                NotificacionService::crear(
                    'asignar_fecha',
                    'Asignar fecha de entrega',
                    "Orden {$orden->referencia} de {$clienteNombre} necesita fecha de entrega",
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
                    "Pago registrado – Orden {$orden->referencia}",
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
            'fecha_sugerida_vendedor'       => 'sometimes|nullable|date',
            // Fotos de la orden: se pueden reemplazar o quitar al editar
            'factura_foto_url'              => 'sometimes|nullable|string|max:500',
            'anexo_foto_url'                => 'sometimes|nullable|string|max:500',
            // La firma es la constancia de que el cliente aceptó: se puede
            // reemplazar (firmó torcido, se cortó el trazo) pero no borrar.
            'firma_url'                     => 'sometimes|string|max:500',
            'canal'                         => 'sometimes|nullable|in:fisica,whatsapp,instagram,facebook,pagina,red_social,otro',
            'departamento_envio'            => 'sometimes|nullable|string|max:100',
            'ciudad_envio'                  => 'sometimes|nullable|string|max:100',
            'direccion_envio'               => 'sometimes|nullable|string|max:300',
            // 0 es válido, igual que al crear: una orden puede quedar sin anticipo
            'anticipo_pct'                  => 'sometimes|nullable|numeric|min:0|max:100',
            'descuento_total'               => 'sometimes|nullable|numeric|min:0',
            'descuento_condicionado_monto'  => 'sometimes|nullable|numeric|min:0',
            'vendedor_id'                   => 'sometimes|nullable|integer|exists:usuarios,id',
            'tienda_id'                     => 'sometimes|nullable|integer|exists:tiendas,id',
            'tienda_abonada_id'             => 'sometimes|nullable|integer|exists:tiendas,id',
            'covendedor_id'                 => 'sometimes|nullable|integer|exists:usuarios,id',
            'es_compartida'                 => 'sometimes|boolean',
            'items'                         => 'sometimes|nullable|array',
            'items.*.id'                    => 'required_with:items|integer|exists:orden_items,id',
            'items.*.specs_personalizacion' => 'sometimes|nullable|array',
            'items.*.precio_unitario'       => 'sometimes|nullable|numeric|min:0',
            'items.*.fecha_entrega_prom'    => 'sometimes|nullable|date',
            'items.*.cantidad'              => 'sometimes|nullable|integer|min:1',
            'items.*.producto_id'           => 'sometimes|nullable|exists:productos,id',
            // Bocetos de un ítem que ya existe: la lista que se manda reemplaza
            // a la que había. Antes solo se podían poner al crear el ítem.
            'items.*.boceto_urls'           => 'sometimes|nullable|array|max:10',
            'items.*.boceto_urls.*'         => 'nullable|string|max:500',
            'items_eliminar'                => 'sometimes|nullable|array',
            'items_eliminar.*'              => 'integer|exists:orden_items,id',
            'items_nuevos'                       => 'sometimes|nullable|array',
            'items_nuevos.*.producto_id'         => 'nullable|integer|exists:productos,id',
            'items_nuevos.*.variante_id'         => 'nullable|integer|exists:producto_variantes,id',
            'items_nuevos.*.tienda_origen_id'    => 'nullable|integer|exists:tiendas,id',
            'items_nuevos.*.nombre_custom'       => 'required_without:items_nuevos.*.producto_id|nullable|string|max:200',
            'items_nuevos.*.categoria_custom'    => 'nullable|string|max:100',
            'items_nuevos.*.cantidad'            => 'required_with:items_nuevos|integer|min:1',
            'items_nuevos.*.precio_unitario'     => 'required_with:items_nuevos|numeric|min:0',
            'items_nuevos.*.es_personalizado'    => 'nullable|boolean',
            'items_nuevos.*.fabricar_pedido'     => 'nullable|boolean',
            'items_nuevos.*.es_restauracion'     => 'nullable|boolean',
            'items_nuevos.*.es_regalo'            => 'nullable|boolean',
            'items_nuevos.*.specs_personalizacion' => 'nullable|array',
            'items_nuevos.*.boceto_urls'         => 'nullable|array|max:10',
            'items_nuevos.*.boceto_urls.*'       => 'nullable|string|max:500',
            'items_nuevos.*.fecha_entrega_prom'  => 'sometimes|nullable|date',
        ]);

        $orden = Orden::with(['items', 'items.producto:id,nombre'])->findOrFail($id);

        if (in_array($usuario->rol, ['vendedor', 'ebanista']) && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // 'pendiente_cotizacion' se permite a propósito: es la salida cuando se
        // dejó activada la consulta de costo sin querer. El vendedor le pone el
        // precio él mismo y la orden sigue su curso, en vez de quedarse trabada
        // esperando a un supervisor que no tiene nada que cotizar.
        if (! in_array($orden->estado, ['borrador', 'pendiente_anticipo', 'en_produccion', 'pendiente_cotizacion'])) {
            return response()->json([
                'message' => 'No se puede editar una orden en estado "' . $orden->estado . '".',
            ], 422);
        }

        $camposReasignacion = ['vendedor_id', 'tienda_id', 'covendedor_id', 'es_compartida', 'tienda_abonada_id'];
        $reasignando        = collect($camposReasignacion)->contains(fn ($c) => array_key_exists($c, $data));

        if ($reasignando) {
            if ($usuario->rol !== 'supervisor') {
                return response()->json(['message' => 'Solo un supervisor puede reasignar vendedor/tienda.'], 403);
            }

            $comisionLiquidada = Comision::where('orden_id', $orden->id)
                ->whereIn('estado', ['lista', 'pagada'])
                ->exists();
            if ($comisionLiquidada) {
                return response()->json(['message' => 'No se puede reasignar: la comisión de esta orden ya está lista o pagada.'], 422);
            }
        }

        $cambios = [];

        DB::transaction(function () use ($data, $orden, $usuario, &$cambios, $reasignando) {
            $updateOrden = [];

            // ── Cambios a nivel de orden ──────────────────────────────────────
            if (array_key_exists('fecha_sugerida_vendedor', $data)) {
                $nuevaSug  = $data['fecha_sugerida_vendedor'] ? substr((string) $data['fecha_sugerida_vendedor'], 0, 10) : null;
                $actualSug = $orden->fecha_sugerida_vendedor?->toDateString();
                if ($nuevaSug !== $actualSug) {
                    $cambios[] = ['campo' => 'fecha_sugerida_vendedor', 'label' => 'Fecha prometida al cliente', 'antes' => $actualSug, 'despues' => $nuevaSug];
                    $updateOrden['fecha_sugerida_vendedor'] = $nuevaSug;
                }
            }
            // Fotos de la orden. Se guarda que cambiaron, no la URL entera: en
            // el historial una dirección de Cloudinary no le dice nada a nadie.
            foreach ([
                'factura_foto_url' => 'Foto de la factura',
                'anexo_foto_url'   => 'Foto anexa',
            ] as $campo => $label) {
                if (! array_key_exists($campo, $data)) continue;
                $nueva = $data[$campo] ?: null;
                if ($nueva === $orden->$campo) continue;

                $cambios[] = [
                    'campo'   => $campo,
                    'label'   => $label,
                    'antes'   => $orden->$campo ? 'tenía foto' : 'sin foto',
                    'despues' => $nueva ? ($orden->$campo ? 'se reemplazó' : 'se agregó') : 'se quitó',
                ];
                $updateOrden[$campo] = $nueva;
            }

            // La firma va aparte de las otras fotos: no es un adjunto sino la
            // prueba de que el cliente aceptó la orden. Se deja reemplazar
            // porque a veces sale cortada o firma quien no era, pero nunca
            // vaciar — una orden sin firma no debería existir — y el cambio
            // queda anotado con nombre y hora en el historial.
            if (array_key_exists('firma_url', $data) && $data['firma_url']) {
                if ($data['firma_url'] !== $orden->firma_url) {
                    $cambios[] = [
                        'campo'   => 'firma_url',
                        'label'   => 'Firma del cliente',
                        'antes'   => $orden->firma_url ? 'firmada' : 'sin firma',
                        'despues' => 'se volvió a tomar',
                    ];
                    $updateOrden['firma_url'] = $data['firma_url'];
                }
            }

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
            if (array_key_exists('anticipo_pct', $data) && (float) $data['anticipo_pct'] !== (float) $orden->anticipo_pct) {
                $cambios[] = ['campo' => 'anticipo_pct', 'label' => '% de anticipo sugerido', 'antes' => (float) $orden->anticipo_pct, 'despues' => (float) $data['anticipo_pct']];
                $updateOrden['anticipo_pct'] = $data['anticipo_pct'];
            }

            // ── Reasignación de vendedor/tienda (solo supervisor) ──────────────
            if ($reasignando) {
                if (array_key_exists('vendedor_id', $data) && (int) $data['vendedor_id'] !== (int) $orden->vendedor_id) {
                    $nombreAntes  = Usuario::find($orden->vendedor_id)?->nombre ?? $orden->vendedor_id;
                    $nombreDespues = Usuario::find($data['vendedor_id'])?->nombre ?? $data['vendedor_id'];
                    $cambios[]    = ['campo' => 'vendedor_id', 'label' => 'Vendedor', 'antes' => $nombreAntes, 'despues' => $nombreDespues];
                    $updateOrden['vendedor_id'] = $data['vendedor_id'];
                }
                if (array_key_exists('tienda_id', $data) && (int) $data['tienda_id'] !== (int) $orden->tienda_id) {
                    $nombreAntes  = Tienda::find($orden->tienda_id)?->nombre ?? $orden->tienda_id;
                    $nombreDespues = Tienda::find($data['tienda_id'])?->nombre ?? $data['tienda_id'];
                    $cambios[]    = ['campo' => 'tienda_id', 'label' => 'Tienda', 'antes' => $nombreAntes, 'despues' => $nombreDespues];
                    $updateOrden['tienda_id'] = $data['tienda_id'];
                }
                if (array_key_exists('covendedor_id', $data) && (int) $data['covendedor_id'] !== (int) $orden->covendedor_id) {
                    $nombreAntes  = $orden->covendedor_id ? (Usuario::find($orden->covendedor_id)?->nombre ?? $orden->covendedor_id) : null;
                    $nombreDespues = $data['covendedor_id'] ? (Usuario::find($data['covendedor_id'])?->nombre ?? $data['covendedor_id']) : null;
                    $cambios[]    = ['campo' => 'covendedor_id', 'label' => 'Co-vendedor', 'antes' => $nombreAntes, 'despues' => $nombreDespues];
                    $updateOrden['covendedor_id'] = $data['covendedor_id'];
                }
                if (array_key_exists('es_compartida', $data) && (bool) $data['es_compartida'] !== (bool) $orden->es_compartida) {
                    $cambios[] = ['campo' => 'es_compartida', 'label' => 'Venta compartida', 'antes' => (bool) $orden->es_compartida, 'despues' => (bool) $data['es_compartida']];
                    $updateOrden['es_compartida'] = $data['es_compartida'];
                }
                if (array_key_exists('tienda_abonada_id', $data)
                    && (int) $data['tienda_abonada_id'] !== (int) $orden->tienda_abonada_id) {
                    // Aqui el dueno de la venta es el vendedor de la orden, no
                    // el supervisor que la esta corrigiendo.
                    $vendedorFinal = Usuario::find($updateOrden['vendedor_id'] ?? $orden->vendedor_id);
                    if ($data['tienda_abonada_id'] && ! $vendedorFinal?->independiente) {
                        throw new \RuntimeException('Solo un vendedor independiente puede abonarle la venta a una tienda.');
                    }
                    if ($data['tienda_abonada_id']
                        && (int) $data['tienda_abonada_id'] === (int) ($updateOrden['tienda_id'] ?? $orden->tienda_id)) {
                        throw new \RuntimeException('La tienda que se lleva la mitad no puede ser la misma de la orden.');
                    }
                    $compartidaFinal = array_key_exists('es_compartida', $data)
                        ? (bool) $data['es_compartida'] : (bool) $orden->es_compartida;
                    if ($data['tienda_abonada_id'] && $compartidaFinal) {
                        throw new \RuntimeException('Una venta se comparte con otro asesor o con un almacén, no con los dos.');
                    }
                    $nombreAntes   = $orden->tienda_abonada_id ? (Tienda::find($orden->tienda_abonada_id)?->nombre ?? $orden->tienda_abonada_id) : null;
                    $nombreDespues = $data['tienda_abonada_id'] ? (Tienda::find($data['tienda_abonada_id'])?->nombre ?? $data['tienda_abonada_id']) : null;
                    $cambios[]     = [
                        'campo' => 'tienda_abonada_id',
                        'label' => 'Mitad de la venta abonada a',
                        'antes' => $nombreAntes ?? 'nadie (venta entera del vendedor)',
                        'despues' => $nombreDespues ?? 'nadie (venta entera del vendedor)',
                    ];
                    $updateOrden['tienda_abonada_id'] = $data['tienda_abonada_id'] ?: null;
                }
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

                        // Se comparan normalizadas: sin campos vacíos y con las
                        // claves ordenadas. Si no, guardar sin tocar nada dejaba
                        // una edición igualita en el historial solo porque un
                        // campo pasó de null a "" o cambió el orden de las claves.
                        $normalizar = function ($specs) {
                            $s = collect((array) $specs)
                                ->reject(fn ($v) => $v === null || $v === '' || $v === [])
                                ->map(fn ($v) => is_scalar($v) ? trim((string) $v) : $v)
                                ->all();
                            ksort($s);
                            return $s;
                        };

                        if ($normalizar($antes) !== $normalizar($despues)) {
                            $cambios[]                          = ['campo' => "item_{$item->id}_specs", 'label' => "{$nombreProd} — especificaciones", 'antes' => $antes, 'despues' => $despues];
                            $updateItem['specs_personalizacion'] = $despues;
                        }
                    }

                    // Bocetos del ítem. La lista que llega reemplaza a la que
                    // había: quitar una foto es mandar la lista sin ella.
                    // Se guarda igual que al crear — la primera en boceto_url y
                    // el resto en boceto_fotos — para que todo lo que ya lee
                    // esos campos (PDF, taller, detalle) siga funcionando.
                    if ($item->es_personalizado && array_key_exists('boceto_urls', $itemData)) {
                        $nuevos = array_values(array_filter($itemData['boceto_urls'] ?? []));
                        $antes  = $item->bocetos_list;

                        if ($nuevos !== $antes) {
                            $cambios[] = [
                                'campo'   => "item_{$item->id}_bocetos",
                                'label'   => "{$nombreProd} — bocetos",
                                'antes'   => count($antes) . ' foto(s)',
                                'despues' => count($nuevos) . ' foto(s)',
                            ];
                            $updateItem['boceto_url']   = $nuevos[0] ?? null;
                            $updateItem['boceto_fotos'] = count($nuevos) > 1 ? $nuevos : null;
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
                    // Un personalizado no tiene producto de catálogo: sin el
                    // nombre_custom el historial decía "Ítem #161", que no le
                    // dice nada a quien lee el aviso.
                    $nombreProd = $item->producto?->nombre ?? $item->nombre_custom ?? "Ítem #{$item->id}";
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
            $origenesExternosEdit = [];   // [tienda_origen_id => ["Nombre (cant)", ...]]
            if (! empty($data['items_nuevos'])) {
                foreach ($data['items_nuevos'] as $nuevoData) {
                    $esCustom        = empty($nuevoData['producto_id']);                 // diseño especial (fuera de catálogo)
                    $esPersonalizado = (bool) ($nuevoData['es_personalizado'] ?? false) || $esCustom;
                    $fabricarPedido  = (bool) ($nuevoData['fabricar_pedido'] ?? false) && ! $esCustom;
                    $productoId      = $esCustom ? null : (int) $nuevoData['producto_id'];
                    $varianteId      = $esCustom ? null : ($nuevoData['variante_id'] ?? null);
                    $cantidad        = (int) $nuevoData['cantidad'];
                    $precio          = (float) $nuevoData['precio_unitario'];
                    $origenId        = (int) ($nuevoData['tienda_origen_id'] ?? $orden->tienda_id);

                    $bocetos = array_values(array_filter($nuevoData['boceto_urls'] ?? []));

                    // Solo los ítems de stock verifican y reservan inventario.
                    if (! $esPersonalizado) {
                        if ($varianteId) {
                            $invV       = InventarioVariante::where('variante_id', $varianteId)->where('tienda_id', $origenId)->lockForUpdate()->first();
                            $stockLibre = $invV ? ($invV->cantidad_disponible - $invV->cantidad_reservada) : 0;
                        } else {
                            $inv        = Inventario::where('producto_id', $productoId)->where('tienda_id', $origenId)->lockForUpdate()->first();
                            $stockLibre = $inv ? ($inv->cantidad_disponible - $inv->cantidad_reservada) : 0;
                        }
                        if ($stockLibre < $cantidad) {
                            $nomProd = Producto::find($productoId)?->nombre ?? "Producto #{$productoId}";
                            abort(422, "Stock insuficiente para \"{$nomProd}\". Libre: {$stockLibre}, necesario: {$cantidad}.");
                        }
                    }

                    $nuevoItem = OrdenItem::create([
                        'orden_id'              => $orden->id,
                        'producto_id'           => $productoId,
                        'variante_id'           => $varianteId,
                        'nombre_custom'         => $esCustom ? ($nuevoData['nombre_custom'] ?? null) : null,
                        'categoria_custom'      => $esCustom ? ($nuevoData['categoria_custom'] ?? null) : null,
                        'cantidad'              => $cantidad,
                        'precio_unitario'       => $precio,
                        'es_personalizado'      => $esPersonalizado,
                        'fabricar_pedido'       => $fabricarPedido,
                        'es_restauracion'       => (bool) ($nuevoData['es_restauracion'] ?? false),
                        'es_regalo'             => (bool) ($nuevoData['es_regalo'] ?? false),
                        'tienda_origen_id'      => $esPersonalizado ? null : ($origenId !== (int) $orden->tienda_id ? $origenId : null),
                        'specs_personalizacion' => $nuevoData['specs_personalizacion'] ?? null,
                        'boceto_url'            => $bocetos[0] ?? null,
                        'boceto_fotos'          => count($bocetos) > 1 ? $bocetos : null,
                        'fecha_entrega_prom'    => $nuevoData['fecha_entrega_prom'] ?? null,
                    ]);

                    if ($esPersonalizado) {
                        // Ítem a producción: crear su registro (supervisor asigna fecha después).
                        Produccion::create([
                            'orden_item_id'    => $nuevoItem->id,
                            'fecha_inicio'     => now()->toDateString(),
                            'fecha_compromiso' => null,
                            'estado'           => 'pendiente',
                        ]);
                    } else {
                        // Reservar variante (si aplica) + stock base, notificar a la tienda.
                        if ($varianteId) {
                            InventarioVariante::where('variante_id', $varianteId)->where('tienda_id', $origenId)
                                ->increment('cantidad_reservada', $cantidad);
                        }
                        Inventario::where('producto_id', $productoId)->where('tienda_id', $origenId)
                            ->increment('cantidad_reservada', $cantidad);
                        InventarioMovimiento::create([
                            'producto_id' => $productoId,
                            'tienda_id'   => $origenId,
                            'variante_id' => $varianteId,
                            'tipo'        => 'reserva',
                            'cantidad'    => $cantidad,
                            'motivo'      => "Edición orden #{$orden->id} — ítem agregado",
                            'usuario_id'  => $usuario->id,
                        ]);
                        event(new InventarioActualizado((int) $origenId, (int) $productoId, 'reserva'));

                        // Si el stock sale de otra tienda, registrar para avisarle
                        if ($origenId !== (int) $orden->tienda_id) {
                            $nomOrigen = Producto::find($productoId)?->nombre ?? "Producto #{$productoId}";
                            $origenesExternosEdit[$origenId][] = "{$nomOrigen} ({$cantidad})";
                        }
                    }

                    $nomProd   = $esCustom
                        ? ($nuevoData['nombre_custom'] ?? 'Diseño especial')
                        : (Producto::find($productoId)?->nombre ?? "Producto #{$productoId}");
                    $tipoTxt   = $esCustom ? ' (diseño especial)' : ($fabricarPedido ? ' (para fabricar)' : ($esPersonalizado ? ' (personalizado)' : ''));
                    $cambios[] = [
                        'campo'   => "item_nuevo_{$nuevoItem->id}",
                        'label'   => 'Ítem agregado',
                        'antes'   => null,
                        'despues' => "{$nomProd}{$tipoTxt} × {$cantidad} @ $" . number_format($precio, 0, ',', '.'),
                    ];
                }

                $orden->load('items');
            }

            // Avisar a la(s) tienda(s) de la que se sacó stock al agregar ítems
            foreach ($origenesExternosEdit as $origenId => $lista) {
                $resumen = implode(', ', $lista);
                $vendedoresOrigen = Usuario::where('tienda_default_id', $origenId)
                    ->where('rol', 'vendedor')
                    ->where('activo', true)
                    ->pluck('id');
                foreach ($vendedoresOrigen as $vendedorId) {
                    NotificacionService::crear(
                        'venta_otra_tienda',
                        'Stock tomado de tu tienda',
                        "Orden {$orden->referencia} (edición): {$resumen}",
                        ['orden_id' => $orden->id, 'tienda_id' => (int) $origenId],
                        $vendedorId,
                    );
                }
            }

            // Recalcular valor total: subtotal de ítems, menos el descuento
            // comercial, menos el condicionado (efectivo/transferencia).
            //
            // El condicionado se había quedado fuera de esta cuenta: editar
            // cualquier cosa de una orden que lo tuviera se lo borraba del
            // total en silencio y el precio le subía al cliente.
            $orden->refresh()->load('items');
            $subtotal  = $orden->items->sum(fn ($i) => $i->cantidad * $i->precio_unitario);
            $descuento = array_key_exists('descuento_total', $data) && $data['descuento_total'] !== null
                ? min((float) $data['descuento_total'], $subtotal)
                : min((float) $orden->descuento_total, $subtotal);

            $baseCondicionado = max(0, $subtotal - $descuento);

            // Si ya se perdió por pagar con tarjeta, no se resucita al editar.
            $condicionadoVigente = $orden->descuento_condicionado_revertido_at === null;
            $condicionado = array_key_exists('descuento_condicionado_monto', $data) && $data['descuento_condicionado_monto'] !== null
                ? (float) $data['descuento_condicionado_monto']
                : (float) $orden->descuento_condicionado;
            $condicionado = $condicionadoVigente ? min(max(0.0, $condicionado), $baseCondicionado) : 0.0;

            $nuevoTotal = $baseCondicionado - $condicionado;

            if ((float) $descuento !== (float) $orden->descuento_total) {
                $cambios[]                      = ['campo' => 'descuento_total', 'label' => 'Descuento al total', 'antes' => (float) $orden->descuento_total, 'despues' => (float) $descuento];
                $updateOrden['descuento_total'] = $descuento;
            }
            if ($condicionado !== (float) $orden->descuento_condicionado) {
                $cambios[] = ['campo' => 'descuento_condicionado', 'label' => 'Descuento por efectivo/transferencia', 'antes' => (float) $orden->descuento_condicionado, 'despues' => $condicionado];
                $updateOrden['descuento_condicionado']     = $condicionado;
                $updateOrden['descuento_condicionado_pct'] = $condicionado > 0 && $baseCondicionado > 0
                    ? round($condicionado / $baseCondicionado * 100, 2)
                    : null;
            }
            if ((float) $nuevoTotal !== (float) $orden->valor_total) {
                $cambios[]            = ['campo' => 'valor_total', 'label' => 'Total de la orden', 'antes' => (float) $orden->valor_total, 'despues' => (float) $nuevoTotal];
                $updateOrden['valor_total'] = $nuevoTotal;
            }

            // Si la orden estaba esperando cotización y ya ningún ítem quedó sin
            // precio, se destraba sola: no hay nada que cotizar. Pasa cuando el
            // vendedor le pone el precio él mismo desde editar.
            if ($orden->estado === 'pendiente_cotizacion') {
                $orden->refresh()->load('items');
                $faltaPrecio = $orden->items->contains(
                    fn ($i) => $i->es_personalizado && (float) $i->precio_unitario == 0.0
                               && ! $i->es_regalo
                );
                if (! $faltaPrecio) {
                    $updateOrden['estado'] = 'pendiente_anticipo';
                    $cambios[] = [
                        'campo' => 'estado', 'label' => 'Estado de la orden',
                        'antes' => 'pendiente_cotizacion', 'despues' => 'pendiente_anticipo',
                    ];
                    $cerrarConsultas = true;
                }
            }

            if (! empty($updateOrden)) {
                $orden->update($updateOrden);
            }

            $tocoAsignacion = collect(['vendedor_id', 'tienda_id', 'covendedor_id', 'es_compartida', 'tienda_abonada_id'])
                ->contains(fn ($c) => array_key_exists($c, $updateOrden));
            if ($reasignando && $tocoAsignacion) {
                Comision::where('orden_id', $orden->id)->delete();
                ComisionController::crearParaOrden($orden->fresh());
            } elseif (array_key_exists('valor_total', $updateOrden)) {
                // Si cambió el precio, la comisión tiene que seguirlo. Antes se
                // quedaba con el valor del día que se creó la orden y no había
                // forma de corregirla, ni siquiera desde "Recalcular".
                ComisionController::sincronizarValorOrden($orden->fresh());
            }

            // Al salir de "esperando precio" la orden se vuelve una venta de
            // verdad, y ahí es cuando toma el consecutivo y nace la comisión —
            // igual que en confirmarCotizacion. Sin esto quedaba sin número.
            if (! empty($cerrarConsultas)) {
                $ordenParaNumerar = $orden->fresh();
                if (! $ordenParaNumerar->numero_orden && ! $ordenParaNumerar->serie) {
                    self::asignarNumeroOrden($ordenParaNumerar);
                    ComisionController::crearParaOrden($ordenParaNumerar->fresh());
                }
            }

            // Ya no hay nada que cotizar: se le quita de la lista al supervisor
            // en vez de dejarle una consulta viva que nadie va a responder.
            if (! empty($cerrarConsultas)) {
                \App\Models\ConsultaCosto::where('orden_id', $orden->id)
                    ->where('estado', 'pendiente')
                    ->each(function ($consulta) use ($orden, $usuario) {
                        $consulta->update(['estado' => 'respondida', 'respondido_at' => now()]);
                        NotificacionService::crear(
                            'consulta_costo_respondida',
                            'Ya no hace falta cotizar',
                            "{$usuario->nombre} le puso el precio a la orden {$orden->referencia}. La consulta de costo ya no aplica.",
                            ['consulta_id' => $consulta->id, 'orden_id' => $orden->id],
                            $consulta->asignado_a_id,
                        );
                    });
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
            'vendedor:id,nombre,independiente',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,precio_base,personalizable,foto_url,medidas,material',
            'items.variante', 'items.comboConfig.tipo', 'items.comboConfig.opcion',
            'items.tiendaOrigen:id,nombre',
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
                "Orden {$orden->referencia} ({$ordenFresh->cliente->nombre}) fue editada por {$usuario->nombre}",
                ['orden_id' => $orden->id],
            );

            // Si la edición tocó plata (precios, cantidades, descuento o total),
            // facturación necesita saberlo aparte: el aviso de arriba no dice
            // qué cambió y se pierde entre las ediciones de dirección o notas.
            \App\Services\AvisoFacturacion::cambioDeDinero(
                $ordenFresh,
                $usuario,
                $cambios,
                'edición de la orden',
            );

            // El taller trabaja con las medidas del día que arrancó: si le
            // cambian la tela o el tamaño y nadie se lo dice, sigue armando lo
            // viejo. Solo para órdenes que tenga en producción ahora mismo.
            \App\Services\AvisoProduccion::ordenEditada($ordenFresh, $usuario, $cambios);
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
            'anticipo_monto'              => 'required|numeric|min:0',
            'anticipo_metodo'             => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'         => 'nullable|string|max:100',
            'anticipo_pagos'              => 'nullable|array|min:1',
            'anticipo_pagos.*.monto'      => 'required_with:anticipo_pagos|numeric|min:0.01',
            'anticipo_pagos.*.metodo'     => 'required_with:anticipo_pagos|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_pagos.*.referencia' => 'nullable|string|max:100',
            'notas'                       => 'nullable|string|max:1000',
            'factura_foto_url'    => 'required|string|max:500',
            'anexo_foto_url'      => ($esPresencial ? 'required' : 'nullable') . '|string|max:500',
            'departamento_envio'  => 'required|string|max:100',
            'ciudad_envio'        => 'required|string|max:100',
            'direccion_envio'     => 'required|string|max:300',

            // Especificaciones que faltaban al guardar el borrador. Se pueden
            // enviar aquí para no obligar a pasar por el modal de edición.
            'especificaciones'                => 'nullable|array',
            'especificaciones.*.item_id'      => 'required_with:especificaciones|integer|exists:orden_items,id',
            'especificaciones.*.specs'        => 'nullable|array',
            'especificaciones.*.notas'        => 'nullable|string|max:1000',

            // Se deciden aquí porque es aquí donde surten efecto: unas líneas
            // más abajo se gasta el consecutivo y nace la comisión. Si el
            // vendedor no las marcó al guardar el borrador, este es el último
            // momento para hacerlo — después, el número ya está quemado.
            'es_fv2'         => 'nullable|boolean',
            'motivo_serie'   => 'nullable|string|max:300',
            'es_compartida'  => 'nullable|boolean',
            'covendedor_id'  => 'nullable|exists:usuarios,id',
        ]);

        // Una venta compartida sin con quién no es compartida.
        if ($request->boolean('es_compartida') && ! ($data['covendedor_id'] ?? null)) {
            return response()->json([
                'message' => 'Elige con quién se comparte la venta.',
            ], 422);
        }
        if (($data['covendedor_id'] ?? null) == $orden->vendedor_id) {
            return response()->json([
                'message' => 'El co-vendedor no puede ser el mismo vendedor de la orden.',
            ], 422);
        }

        // Guardar lo que llegue antes de validar, para que la comprobación mire
        // el estado final del ítem y no el que tenía al abrir el modal.
        foreach ($data['especificaciones'] ?? [] as $esp) {
            $item = $orden->items->firstWhere('id', $esp['item_id']);
            if (! $item) continue;

            $specs = array_filter($esp['specs'] ?? [], fn($v) => $v !== null && $v !== '');
            if (! empty($esp['notas'])) $specs['notas'] = $esp['notas'];

            if (! empty($specs)) {
                $item->update([
                    'specs_personalizacion' => array_merge($item->specs_personalizacion ?? [], $specs),
                ]);
            }
        }
        $orden->load('items.produccion');

        // Un personalizado sin especificaciones no se puede fabricar: al
        // completar el borrador se crean las órdenes de producción, y el ebanista
        // recibiría un mueble del que no sabe medidas ni acabado.
        $sinEspecificar = $orden->items
            ->filter(fn($i) => $i->es_personalizado)
            ->filter(fn($i) => empty(array_filter(
                $i->specs_personalizacion ?? [],
                fn($v) => $v !== null && $v !== '' && $v !== []
            )))
            ->map(fn($i) => [
                'item_id' => $i->id,
                'nombre'  => $i->producto->nombre ?? $i->nombre_custom ?? 'Producto personalizado',
            ])
            ->values();

        if ($sinEspecificar->isNotEmpty()) {
            return response()->json([
                'message' => $sinEspecificar->count() === 1
                    ? 'Falta especificar "' . $sinEspecificar[0]['nombre'] . '": sin medidas ni acabado no se puede fabricar.'
                    : $sinEspecificar->count() . ' productos personalizados no tienen especificaciones. Sin eso no se pueden fabricar.',
                'items_sin_especificar' => $sinEspecificar,
            ], 422);
        }

        $tieneItemsCotizacion = $orden->items->contains(
            fn($i) => $i->es_personalizado && $i->precio_unitario == 0 && ! $i->es_regalo
        );

        // No se fuerza un mínimo — el vendedor puede poner cualquier monto ≥ 0

        DB::transaction(function () use ($orden, $data, $tieneItemsCotizacion, $usuario, $request) {
            $nuevoEstado = $tieneItemsCotizacion ? 'pendiente_cotizacion' : 'pendiente_anticipo';

            // Solo se tocan si vienen en la petición: un borrador que ya se
            // guardó como FV2 o compartido no debe perderlo porque el modal
            // no mandara el campo.
            $extra = [];
            if ($request->has('es_fv2')) {
                $esFv2 = $request->boolean('es_fv2');
                $extra['serie']        = $esFv2 ? Orden::SERIE_FV2 : null;
                $extra['motivo_serie'] = $esFv2 ? ($data['motivo_serie'] ?? null) : null;
            }
            if ($request->has('es_compartida')) {
                $comp = $request->boolean('es_compartida');
                $extra['es_compartida'] = $comp;
                $extra['covendedor_id'] = $comp ? ($data['covendedor_id'] ?? null) : null;
            }

            $orden->update([
                'estado'             => $nuevoEstado,
                'firma_url'          => $data['firma_url']          ?? $orden->firma_url,
                'notas'              => $data['notas']              ?? $orden->notas,
                'factura_foto_url'   => $data['factura_foto_url']   ?? $orden->factura_foto_url,
                'anexo_foto_url'     => $data['anexo_foto_url']     ?? $orden->anexo_foto_url,
                'departamento_envio' => $data['departamento_envio'] ?? $orden->departamento_envio,
                'ciudad_envio'       => $data['ciudad_envio']       ?? $orden->ciudad_envio,
                'direccion_envio'    => $data['direccion_envio']    ?? $orden->direccion_envio,
                ...$extra,
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
                if (!empty($data['anticipo_pagos'])) {
                    foreach ($data['anticipo_pagos'] as $p) {
                        if (($p['monto'] ?? 0) > 0) {
                            $orden->pagos()->create(['vendedor_id' => $usuario->id, 'tipo' => 'anticipo', 'monto' => $p['monto'], 'metodo' => $p['metodo'], 'referencia' => $p['referencia'] ?? null]);
                        }
                    }
                } else {
                    $orden->pagos()->create(['vendedor_id' => $usuario->id, 'tipo' => 'anticipo', 'monto' => $data['anticipo_monto'], 'metodo' => $data['anticipo_metodo'] ?? 'efectivo', 'referencia' => $data['anticipo_referencia'] ?? null]);
                }
            }
        });

        $ordenFresh = $orden->fresh()->load([
            'cliente:id,nombre,cedula,telefono',
            'vendedor:id,nombre,independiente',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
            'items.variante', 'items.comboConfig.tipo', 'items.comboConfig.opcion',
            'items.tiendaOrigen:id,nombre',
            'items.produccion',
            'pagos',
        ]);

        $ordenFresh->total_pagado    = $ordenFresh->totalPagado();
        $ordenFresh->saldo_pendiente = $ordenFresh->saldoPendiente();

        // El consecutivo se gasta al confirmar el borrador, salvo que quede
        // esperando el precio del taller: ahí todavía puede no cerrarse, y el
        // número se asigna cuando el cliente acepte (confirmarCotizacion).
        if ($ordenFresh->estado !== 'pendiente_cotizacion') {
            self::asignarNumeroOrden($orden);
            $ordenFresh->numero_orden = $orden->numero_orden;
            ComisionController::crearParaOrden($orden);
        }

        // Notify supervisors of the now-confirmed order
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->get();

        $tieneItemsCotizPendiente = $ordenFresh->items->contains(
            fn($i) => $i->es_personalizado && (float) $i->precio_unitario === 0.0 && ! $i->es_regalo
        );

        foreach ($supervisores as $sup) {
            NotificacionService::crear(
                'venta_nueva',
                'Nueva venta registrada',
                "Orden {$orden->referencia} — {$ordenFresh->cliente->nombre} · $" . number_format($orden->valor_total, 0, ',', '.') . " COP",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id, 'valor_total' => $orden->valor_total],
                $sup->id,
            );

            if ($sup->notif_asignar_fecha && ! $tieneItemsCotizPendiente) {
                NotificacionService::crear(
                    'asignar_fecha',
                    'Asignar fecha de entrega',
                    "Orden {$orden->referencia} de {$ordenFresh->cliente->nombre} necesita fecha de entrega",
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
    /**
     * PATCH /api/ordenes/{id}/revertir-entrega
     *
     * Deshace una entrega marcada por error y devuelve el producto al
     * inventario. Es la salida para cuando el vendedor puso "se lo lleva" sin
     * que el cliente se lo llevara, o el supervisor la cerró equivocado.
     *
     * No sirve para las que entregó un conductor: esas tienen acta firmada,
     * fotos y quién recibió. Deshacer eso a mano dejaría el acta contradiciendo
     * al sistema, así que se manda a Despacho.
     */
    public function revertirEntrega(Request $request, int $id)
    {
        $usuario = $request->user();

        if ($usuario->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo el supervisor puede revertir una entrega.'], 403);
        }

        $orden = Orden::with('items')->findOrFail($id);

        if ($orden->estado !== 'entregado') {
            return response()->json([
                'message' => 'Esta orden no está marcada como entregada.',
            ], 422);
        }

        $porConductor = DB::table('despacho_items')->where('orden_id', $id)->exists();
        if ($porConductor) {
            return response()->json([
                'message' => 'Esta entrega la hizo un conductor y tiene acta firmada. Se corrige desde el módulo de Despacho.',
            ], 422);
        }

        $data = $request->validate([
            'motivo' => 'required|string|min:3|max:300',
        ]);

        DB::transaction(function () use ($orden, $usuario, $data) {
            // El producto vuelve al inventario, y queda reservado para esta
            // orden: sigue viva y comprometida, no disponible para vender otra vez.
            foreach ($orden->items->where('es_personalizado', false) as $item) {
                if (! $item->producto_id) continue;
                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;
                $cant     = (int) $item->cantidad;

                if ($item->variante_id) {
                    InventarioVariante::where('variante_id', $item->variante_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible + {$cant}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cant}"),
                        ]);
                    if ($item->combo_config_id) {
                        InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                            ->where('config_id', $item->combo_config_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible + {$cant}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cant}"),
                            ]);
                    }
                }

                Inventario::where('producto_id', $item->producto_id)
                    ->where('tienda_id', $origenId)
                    ->update([
                        'cantidad_disponible' => DB::raw("cantidad_disponible + {$cant}"),
                        'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cant}"),
                    ]);

                InventarioMovimiento::create([
                    'producto_id' => $item->producto_id,
                    'tienda_id'   => $origenId,
                    'tipo'        => 'entrada',
                    'cantidad'    => $cant,
                    'motivo'      => "Entrega revertida orden #{$orden->id}: {$data['motivo']}",
                    'usuario_id'  => $usuario->id,
                ]);

                event(new InventarioActualizado((int) $origenId, (int) $item->producto_id, 'entrada'));
            }

            $orden->update([
                'estado'           => 'pendiente_anticipo',
                'listo_entrega_at' => null,
            ]);

            \App\Models\OrdenEdicion::create([
                'orden_id'   => $orden->id,
                'usuario_id' => $usuario->id,
                'cambios'    => [[
                    'campo'   => 'estado',
                    'label'   => 'Entrega revertida',
                    'antes'   => 'entregado',
                    'despues' => 'pendiente_anticipo — ' . $data['motivo'],
                ]],
            ]);
        });

        // Que el vendedor se entere: su orden volvió a estar viva
        if ($orden->vendedor_id && $orden->vendedor_id !== $usuario->id) {
            NotificacionService::crear(
                'orden_editada',
                'Se revirtió una entrega',
                "{$usuario->nombre} devolvió la orden {$orden->referencia} a \"en espera\". Motivo: {$data['motivo']}",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        return response()->json($orden->fresh());
    }

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

        // Transiciones válidas (despacho controla listo_entrega y en_camino).
        //
        // 'entregado' directo es para cuando el cliente se llevó el producto de
        // la tienda al pagar y no se marcó entrega inmediata al hacer la orden.
        // No pasa por despacho porque no hubo transporte: nadie lo llevó.
        // Descuenta inventario igual que una entrega normal.
        $transiciones = [
            'borrador'              => ['cancelado'],
            'pendiente_cotizacion'  => ['cancelado'],
            'pendiente_anticipo'    => ['en_produccion', 'listo_entrega', 'entregado', 'cancelado'],
            'en_produccion'         => ['listo_entrega', 'entregado', 'cancelado'],
            // Despacho las maneja; de todos modos se bloquean más arriba
            'listo_entrega'         => [],
            'en_camino'             => [],
            // Estados finales: de aquí no se sale
            'entregado'             => [],
            'cancelado'             => [],
        ];

        // Se mira si la clave existe, no si la lista trae algo: una lista vacía
        // significa "de este estado no se sale", no "vale cualquier cosa". Con
        // la comprobación anterior una orden CANCELADA se podía marcar como
        // entregada, y eso descuenta inventario que ya se había liberado.
        $permitidos = $transiciones[$estadoAnterior] ?? null;
        if ($permitidos !== null && !in_array($estadoNuevo, $permitidos, true)) {
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
                    // Bajó el stock base: el reparto tiene que seguir cabiendo.
                    StockVariantes::cuadrar(
                        (int) $item->producto_id, (int) $origenId, "Entrega orden #{$orden->id}"
                    );
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
                // Avisar al taller ANTES de cancelarlas: después ya no se ve que
                // había trabajo vivo, y es justo lo que hay que decirle.
                \App\Services\AvisoProduccion::ordenCancelada($orden, $usuario);

                \App\Models\Produccion::whereHas('ordenItem', fn($q) => $q->where('orden_id', $orden->id))
                    ->whereNotIn('estado', ['cancelado', 'completado'])
                    ->update(['estado' => 'cancelado']);

                // Una venta cancelada no se comisiona. Quedaba la comisión viva
                // y pagable, y peor: su valor le seguía sumando a la meta de la
                // tienda, empujándola sobre el objetivo con una venta que no
                // existió y destrabando el pool de todo el equipo.
                // Las ya pagadas no se tocan: esa plata ya salió y borrar el
                // registro sería perder el rastro de que se pagó.
                Comision::where('orden_id', $orden->id)
                    ->where('estado', '!=', 'pagada')
                    ->delete();
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
                    "Orden {$orden->referencia} — {$ordenFresh->cliente->nombre} está lista para despachar",
                    ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
                    $sup->id,
                );
            }

            // Notificar al vendedor
            NotificacionService::crear(
                'listo_entrega',
                'Tu pedido está listo para entrega',
                "Orden {$orden->referencia} — {$ordenFresh->cliente->nombre} está lista para ser despachada",
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
                    "Orden {$orden->referencia} entregada a {$ordenFresh->cliente->nombre}",
                    ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
                    $sup->id,
                );
            }
            // Vendedor
            NotificacionService::crear(
                'entregado',
                'Tu orden fue entregada',
                "Orden {$orden->referencia} — {$ordenFresh->cliente->nombre} recibió su pedido",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'en_produccion') {
            NotificacionService::crear(
                'en_produccion',
                'Tu pedido entró en producción',
                "Orden {$orden->referencia} — {$ordenFresh->cliente->nombre} está en producción",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'cancelado') {
            NotificacionService::crear(
                'cancelado',
                'Tu orden fue cancelada',
                "Orden {$orden->referencia} — {$ordenFresh->cliente->nombre} fue cancelada",
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
                        "La orden {$orden->referencia} de {$ordenFresh->cliente->nombre} fue cancelada. La consulta de costo ya no aplica.",
                        ['consulta_id' => $consulta->id, 'orden_id' => $orden->id],
                        $consulta->asignado_a_id,
                    );
                });

            // Una orden cancelada que ya recibió plata deja un dinero sin destino:
            // facturación tiene que decidir si se devuelve o queda a favor.
            \App\Services\AvisoFacturacion::ordenCanceladaConPagos($ordenFresh, $usuario);
        }

        // Notificar inventario cuando se entrega o cancela
        if (in_array($estadoNuevo, ['entregado', 'cancelado'])) {
            foreach ($orden->items->where('es_personalizado', false) as $item) {
                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;
                $tipo = $estadoNuevo === 'entregado' ? 'salida' : 'liberacion';
                event(new InventarioActualizado((int) $origenId, (int) $item->producto_id, $tipo));
            }
        }

        // Al entregar, el producto sale del inventario: si era el último, avisar
        if ($estadoNuevo === 'entregado') {
            foreach ($orden->items->where('es_personalizado', false) as $item) {
                if (! $item->producto_id) continue;
                self::notificarSiSeAcabo(
                    (int) $item->producto_id,
                    (int) ($item->tienda_origen_id ?? $orden->tienda_id),
                    (int) $item->cantidad,
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

        $orden = Orden::with(['items', 'cliente:id,nombre', 'vendedor:id,nombre,independiente'])->findOrFail($id);

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
            "La orden {$orden->referencia} de {$orden->cliente->nombre} ya tiene fecha de entrega",
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
            'items.variante', 'items.comboConfig.tipo', 'items.comboConfig.opcion',
            'items.tiendaOrigen:id,nombre',
            'pagos',
        ])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id && ! $usuario->ve_todas_ordenes) {
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

        // Los bocetos ya no van en el PDF: la orden se imprime y cada boceto
        // se llevaba media hoja. Se consultan en el detalle de la orden, que es
        // donde además se ven a tamaño completo.
        // Lo más grande que quepa en una hoja: se prueban varias escalas de
        // mayor a menor y se deja la primera que no se pase (ver
        // PdfOrdenUnaHoja). Antes se imprimía siempre al mismo tamaño y se
        // veía chiquito, porque el tamaño estaba elegido para que cupiera
        // hasta la orden más larga.
        [$pdf] = PdfOrdenUnaHoja::generar(
            compact('orden', 'firmaCliente', 'firmaVendedor', 'logoBase64')
        );

        return $pdf->download('orden-' . $orden->id . '.pdf');
    }

    /**
     * Avisa cuando se vendió la última unidad de un producto en una tienda.
     *
     * Solo al VENDERSE de verdad —cuando sale del inventario, no cuando se
     * aparta—, y solo en el momento en que cruza a cero. Antes también avisaba
     * al reservar la última libre, y como eso pasa en cada venta buena, la
     * misma alerta llegaba una y otra vez a los seis supervisores.
     *
     * @param int $cantidadVendida Unidades que acaban de salir, para saber si
     *                             el producto venía con stock y se acabó ahora.
     */
    public static function notificarSiSeAcabo(int $productoId, int $tiendaId, int $cantidadVendida = 1): void
    {
        $inv = Inventario::with('producto:id,nombre', 'tienda:id,nombre')
            ->where('producto_id', $productoId)
            ->where('tienda_id', $tiendaId)
            ->first();

        if (! $inv) return;

        $quedan = (int) $inv->cantidad_disponible;
        if ($quedan > 0) return;

        // Ya estaba en cero antes de esta venta: no se acabó ahora, y avisar
        // otra vez es el ruido que hacía inservible la alerta.
        if ($quedan + $cantidadVendida <= 0) return;

        $nombre   = $inv->producto?->nombre ?? "Producto #{$productoId}";
        $tiendaNm = $inv->tienda?->nombre   ?? "Tienda #{$tiendaId}";

        // Es un aviso para quien surte, no para toda la supervisión: se activa
        // a mano por persona. Si nadie lo tiene marcado, cae a supervisión para
        // que la alerta no se pierda en silencio.
        $destinatarios = Usuario::where('activo', true)->where('notif_stock', true)->get();
        if ($destinatarios->isEmpty()) {
            $destinatarios = Usuario::where('activo', true)->where('rol', 'supervisor')->get();
        }

        foreach ($destinatarios as $d) {
            NotificacionService::crear(
                tipo:      'stock_agotado',
                titulo:    'Se vendió el último',
                mensaje:   "Se vendió la última unidad de \"$nombre\" en $tiendaNm. Hay que reponer.",
                datos:     ['producto_id' => $productoId, 'tienda_id' => $tiendaId],
                usuarioId: $d->id,
            );
        }
    }

    // Tiendas que comparten secuencia de numeración por grupo
    private const GRUPOS_SECUENCIA = [
        'pereira' => ['Decasa Unicentro Pereira', 'Decasa Circunvalar'],
        // Los independientes no tienen tienda propia, pero venden en Armenia:
        // llevan su consecutivo. Antes no estaban en ningún grupo y caían en la
        // rama de abajo, que reparte números sin reservarlos.
        'armenia' => ['Decasa Norte', 'Decasa Vía El Edén', 'Decasa Vía Jardines', 'Bodega Fábrica', 'Tienda Virtual', 'Independientes'],
    ];

    /**
     * DELETE /api/ordenes/{id}
     *
     * Solo borradores. Un borrador nunca fue una venta —no tiene consecutivo, no
     * generó comisión ni producción—, así que no hay nada que conservar: dejarlo
     * cancelado en la lista es basura. Se libera el inventario que tenía apartado.
     *
     * También borra los borradores que ya quedaron cancelados antes de que
     * existiera este endpoint: son los que ensucian la lista de órdenes.
     */
    public function destroy(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::with('items')->findOrFail($id);

        // Un borrador cancelado nunca fue venta: sin consecutivo, sin serie y sin
        // un peso cobrado. Con cualquiera de esas tres cosas sí hubo operación.
        $borradorCancelado = $orden->estado === 'cancelado'
            && $orden->numero_orden === null
            && $orden->serie === null
            && $orden->cotizacion_numero === null
            && $orden->pagos()->count() === 0;

        if ($orden->estado !== 'borrador' && ! $borradorCancelado) {
            return response()->json([
                'message' => 'Solo se pueden eliminar borradores. Una orden confirmada se cancela, no se borra.',
            ], 422);
        }

        if ($usuario->rol !== 'supervisor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'Solo puedes eliminar tus propios borradores.'], 403);
        }

        DB::transaction(function () use ($orden, $usuario, $borradorCancelado) {
            // Devolver al stock lo que el borrador tenía apartado. Si ya estaba
            // cancelado la reserva se liberó en ese momento: hacerlo otra vez
            // dejaría el cantidad_reservada en negativo.
            foreach ($borradorCancelado ? [] : $orden->items as $item) {
                if ($item->es_personalizado || ! $item->producto_id) continue;

                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;

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
                }

                Inventario::where('producto_id', $item->producto_id)
                    ->where('tienda_id', $origenId)
                    ->decrement('cantidad_reservada', $item->cantidad);

                InventarioMovimiento::create([
                    'producto_id' => $item->producto_id,
                    'tienda_id'   => $origenId,
                    'tipo'        => 'liberacion',
                    'cantidad'    => $item->cantidad,
                    'motivo'      => "Borrador eliminado (orden interna #{$orden->id})",
                    'usuario_id'  => $usuario->id,
                ]);
            }

            $itemIds = $orden->items->pluck('id');
            Produccion::whereIn('orden_item_id', $itemIds)->delete();
            DB::table('consultas_costo')->where('orden_id', $orden->id)->delete();
            DB::table('orden_ediciones')->where('orden_id', $orden->id)->delete();
            $orden->pagos()->delete();
            OrdenItem::whereIn('id', $itemIds)->delete();
            $orden->delete();
        });

        return response()->json(['message' => 'Borrador eliminado.']);
    }

    /**
     * Grupo de numeración al que pertenece una tienda ('armenia', 'pereira' o
     * null si no está en ninguno). Lo usa también CotizacionController para
     * numerar las COT-N por grupo.
     */
    /**
     * GET /api/ordenes/{id}/numeracion?serie=FV2&correr=1
     *
     * Qué pasaría, sin tocar nada. Se muestra antes de aplicar porque correr
     * los consecutivos no se puede deshacer con un botón.
     */
    public function previsualizarNumeracion(Request $request, int $id)
    {
        $orden = $this->ordenParaNumeracion($request, $id);

        $data = $request->validate([
            'serie'  => ['required', Rule::in([Orden::SERIE_FV2, Orden::SERIE_RESTAURACION])],
            'correr' => 'nullable|boolean',
        ]);

        return response()->json(NumeracionOrdenes::previsualizarConversion(
            $orden, $data['serie'], $request->boolean('correr')
        ));
    }

    /** POST /api/ordenes/{id}/numeracion/convertir */
    public function convertirSerie(Request $request, int $id)
    {
        $orden = $this->ordenParaNumeracion($request, $id);

        $data = $request->validate([
            'serie'  => ['required', Rule::in([Orden::SERIE_FV2, Orden::SERIE_RESTAURACION])],
            'correr' => 'nullable|boolean',
            'motivo' => 'nullable|string|max:300',
        ]);

        $resultado = NumeracionOrdenes::convertir(
            $orden, $data['serie'], (bool) ($data['correr'] ?? false), $request->user(), $data['motivo'] ?? null
        );

        return response()->json($resultado);
    }

    /** PATCH /api/ordenes/{id}/numeracion — corregir el número a mano. */
    public function cambiarNumero(Request $request, int $id)
    {
        $orden = $this->ordenParaNumeracion($request, $id);

        $data = $request->validate([
            'numero_orden' => 'required|integer|min:1|max:99999999',
        ]);

        return response()->json(
            NumeracionOrdenes::cambiarNumero($orden, (int) $data['numero_orden'], $request->user())
        );
    }

    /**
     * Tocar consecutivos es solo del supervisor: es el talonario de la
     * empresa, y un número mal puesto se arrastra a facturación y comisiones.
     */
    private function ordenParaNumeracion(Request $request, int $id): Orden
    {
        if ($request->user()->rol !== 'supervisor') {
            abort(403, 'Solo un supervisor puede cambiar la numeración de una orden.');
        }

        return Orden::with('cliente:id,nombre')->findOrFail($id);
    }

    public static function grupoDeTienda(?int $tiendaId): ?string
    {
        if (! $tiendaId) return null;

        $tiendaNombre = DB::table('tiendas')->where('id', $tiendaId)->value('nombre');

        foreach (self::GRUPOS_SECUENCIA as $key => $nombres) {
            if (in_array($tiendaNombre, $nombres, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Numera una orden de serie especial (FV2-1, FV2-2...). Consecutivo propio
     * y global: no consume el número de orden normal, igual que se hacía a mano.
     */
    public static function asignarNumeroSerie(Orden $orden, string $serie = Orden::SERIE_FV2): void
    {
        $clave = strtolower($serie);

        DB::transaction(function () use ($orden, $serie, $clave) {
            $actual = DB::table('orden_secuencias')
                ->where('grupo', $clave)
                ->lockForUpdate()
                ->value('ultimo_numero');

            if ($actual === null) {
                DB::table('orden_secuencias')->insert(['grupo' => $clave, 'ultimo_numero' => 0]);
                $actual = 0;
            }

            $siguiente = $actual + 1;

            DB::table('orden_secuencias')
                ->where('grupo', $clave)
                ->update(['ultimo_numero' => $siguiente]);

            $orden->update([
                'serie'        => $serie,
                'serie_numero' => $siguiente,
                'numero_orden' => null,   // no gasta consecutivo normal
            ]);
        });
    }

    /**
     * ¿Toda la orden es restauración?
     *
     * Se consulta la tabla en vez de $orden->items para no depender de que la
     * relación esté cargada: aquí se decide el número, y equivocarse quema un
     * consecutivo que no se puede devolver.
     */
    public static function esSoloRestauracion(Orden $orden): bool
    {
        $total = OrdenItem::where('orden_id', $orden->id)->count();
        if ($total === 0) return false;

        $restauraciones = OrdenItem::where('orden_id', $orden->id)
            ->where('es_restauracion', true)->count();

        return $total === $restauraciones;
    }

    public static function asignarNumeroOrden(Orden $orden): void
    {
        // Las órdenes de serie especial llevan su propia numeración.
        // El FV2 se marca a mano, así que manda sobre lo demás.
        if ($orden->serie) {
            self::asignarNumeroSerie($orden, $orden->serie);
            return;
        }

        // Una restauración no es una venta de mueble: no gasta consecutivo de
        // venta, lleva el suyo (R-1092). Va aquí dentro y no en cada sitio que
        // numera —crear, confirmar cotización, completar borrador, destrabar—
        // para que ninguno se quede fuera por olvido.
        if (self::esSoloRestauracion($orden)) {
            self::asignarNumeroSerie($orden, Orden::SERIE_RESTAURACION);
            return;
        }

        $grupo = self::grupoDeTienda($orden->tienda_id);

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
                // Una tienda sin grupo no deberia existir. Si aparece —una nueva
                // que nadie asigno— antes se le daba MAX+1 sin reservarlo en
                // ninguna secuencia: el numero salia del rango de Armenia y la
                // siguiente venta de Armenia lo repetia. Paso de verdad con la
                // tienda de Independientes.
                //
                // Se usa el consecutivo de Armenia, que es de donde ese MAX
                // salia igualmente, pero reservandolo. Y se deja aviso para que
                // alguien la meta en su grupo.
                Log::warning('Orden de una tienda sin grupo de consecutivo: se numera con el de Armenia', [
                    'orden_id'  => $orden->id,
                    'tienda_id' => $orden->tienda_id,
                    'tienda'    => DB::table('tiendas')->where('id', $orden->tienda_id)->value('nombre'),
                ]);

                $actual = DB::table('orden_secuencias')->where('grupo', 'armenia')
                    ->lockForUpdate()->value('ultimo_numero') ?? 0;
                $siguiente = $actual + 1;
                DB::table('orden_secuencias')->where('grupo', 'armenia')
                    ->update(['ultimo_numero' => $siguiente]);
                $orden->update(['numero_orden' => $siguiente, 'grupo_secuencia' => 'armenia']);
            }
        });
    }

}
