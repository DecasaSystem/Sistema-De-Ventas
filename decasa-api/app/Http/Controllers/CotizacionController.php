<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProductoVariante;
use App\Models\Usuario;
use App\Mail\CotizacionEnviadaMail;
use App\Services\NotificacionService;
use App\Support\ConvierteImagenesPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Cotizaciones: propuestas de precio para clientes que todavía no compran.
 *
 * Diferencias con una orden (a propósito):
 *   - no valida stock ni reserva inventario
 *   - no crea producción ni comisión
 *   - no consume consecutivo de orden (usa COT-N por grupo de tienda)
 *   - el cliente es opcional; basta un contacto suelto o nada
 *   - no pide firma, anexo, comprobante ni anticipo
 */
class CotizacionController extends Controller
{
    use ConvierteImagenesPdf;

    /** Días de vigencia por defecto de una cotización. */
    private const DIAS_VIGENCIA = 15;

    /**
     * GET /api/cotizaciones
     * Vendedor: solo las suyas. Supervisor y facturador: todas.
     * Filtros: cotizacion_estado, tienda_id, desde, hasta, search, vencidas.
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Orden::cotizaciones()
            ->with([
                'cliente:id,nombre,telefono',
                'tienda:id,nombre',
                'vendedor:id,nombre',
            ])
            ->withCount('items');

        if (! $this->veTodas($usuario)) {
            $query->where('vendedor_id', $usuario->id);
        }

        if ($v = $request->query('cotizacion_estado')) {
            $query->where('cotizacion_estado', $v);
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

        // Vencidas se filtra por fecha, no por estado guardado.
        if ($request->boolean('vencidas')) {
            $query->whereDate('cotizacion_valida_hasta', '<', now()->toDateString())
                  ->whereNotIn('cotizacion_estado', ['convertida', 'perdida']);
        }

        if ($search = $request->query('search')) {
            $limpio = ltrim(trim($search), '#');
            $limpio = preg_replace('/^cot-?/i', '', $limpio);
            $term   = '%' . mb_strtolower($limpio) . '%';

            $query->where(function ($q) use ($term, $limpio) {
                $q->whereHas('cliente', fn($c) => $c->whereRaw('LOWER(nombre) LIKE ?', [$term]))
                  ->orWhereRaw('LOWER(contacto_nombre) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(contacto_telefono) LIKE ?', [$term]);
                if (is_numeric($limpio)) {
                    $q->orWhere('cotizacion_numero', (int) $limpio);
                }
            });
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(20)
        );
    }

    /**
     * POST /api/cotizaciones
     * No toca inventario ni exige stock: es solo una propuesta de precio.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // Cliente opcional: puede venir un cliente formal, un contacto suelto, o nada.
            'cliente_id'                         => 'nullable|exists:clientes,id',
            'contacto_nombre'                    => 'nullable|string|max:150',
            'contacto_telefono'                  => 'nullable|string|max:40',
            'contacto_email'                     => 'nullable|email|max:150',

            'tienda_id'                          => 'required|exists:tiendas,id',
            'canal'                              => 'required|in:fisica,whatsapp,instagram,facebook,pagina,red_social,otro',
            'notas'                              => 'nullable|string|max:1000',
            'descuento_total'                    => 'nullable|numeric|min:0',
            'dias_vigencia'                      => 'nullable|integer|min:1|max:180',

            'items'                              => 'required|array|min:1',
            'items.*.producto_id'                => 'nullable|integer|exists:productos,id',
            'items.*.nombre_custom'              => 'required_without:items.*.producto_id|nullable|string|max:200',
            'items.*.categoria_custom'           => 'nullable|string|max:100',
            'items.*.variante_id'                => 'nullable|integer|exists:producto_variantes,id',
            'items.*.combo_config_id'            => 'nullable|integer',
            'items.*.tienda_origen_id'           => 'nullable|integer|exists:tiendas,id',
            'items.*.cantidad'                   => 'required|integer|min:1',
            'items.*.precio_unitario'            => 'required|numeric|min:0',
            'items.*.es_personalizado'           => 'nullable|boolean',
            'items.*.fabricar_pedido'            => 'nullable|boolean',
            'items.*.specs_personalizacion'      => 'nullable|array',
            'items.*.boceto_url'                 => 'nullable|string|max:500',
            'items.*.boceto_urls'                => 'nullable|array',
        ]);

        $tiendaId = $data['tienda_id'];

        $subtotal       = collect($data['items'])->sum(fn($i) => $i['cantidad'] * $i['precio_unitario']);
        $descuentoTotal = min((float) ($data['descuento_total'] ?? 0), $subtotal);
        $valorTotal     = $subtotal - $descuentoTotal;

        $cotizacion = DB::transaction(function () use ($data, $tiendaId, $valorTotal, $descuentoTotal, $request) {
            $orden = Orden::create([
                'cliente_id'              => $data['cliente_id'] ?? null,
                'contacto_nombre'         => $data['contacto_nombre'] ?? null,
                'contacto_telefono'       => $data['contacto_telefono'] ?? null,
                'contacto_email'          => $data['contacto_email'] ?? null,
                'vendedor_id'             => $request->user()->id,
                'tienda_id'               => $tiendaId,
                'canal'                   => $data['canal'],
                'tipo'                    => 'venta',
                'estado'                  => 'cotizacion',
                'cotizacion_estado'       => 'abierta',
                'cotizacion_valida_hasta' => now()->addDays($data['dias_vigencia'] ?? self::DIAS_VIGENCIA)->toDateString(),
                'valor_total'             => $valorTotal,
                'descuento_total'         => $descuentoTotal,
                'anticipo_pct'            => 0,
                'notas'                   => $data['notas'] ?? null,
            ]);

            // Ítems: misma forma que en una orden, pero sin tocar inventario
            // ni crear producción.
            foreach ($data['items'] as $itemData) {
                $esPersonalizado  = (bool) ($itemData['es_personalizado'] ?? false);
                $esProductoCustom = empty($itemData['producto_id']);

                $varianteId     = $itemData['variante_id']      ?? null;
                $origenTiendaId = $itemData['tienda_origen_id'] ?? $tiendaId;

                $specsExtra = $itemData['specs_personalizacion'] ?? null;
                if ($varianteId && ! $esPersonalizado && ! $esProductoCustom) {
                    $v = ProductoVariante::find($varianteId);
                    $specsExtra = array_merge($specsExtra ?? [], [
                        'variante_marca' => $v?->marca_tela,
                        'variante_color' => $v?->nombre_color,
                    ]);
                }

                OrdenItem::create([
                    'orden_id'              => $orden->id,
                    'producto_id'           => $itemData['producto_id'] ?? null,
                    'nombre_custom'         => $esProductoCustom ? ($itemData['nombre_custom'] ?? null) : null,
                    'categoria_custom'      => $esProductoCustom ? ($itemData['categoria_custom'] ?? null) : null,
                    'variante_id'           => $varianteId,
                    'combo_config_id'       => $itemData['combo_config_id'] ?? null,
                    'tienda_origen_id'      => $origenTiendaId !== $tiendaId ? $origenTiendaId : null,
                    'cantidad'              => $itemData['cantidad'],
                    'precio_unitario'       => $itemData['precio_unitario'],
                    'es_personalizado'      => $esPersonalizado || $esProductoCustom,
                    'fabricar_pedido'       => (bool) ($itemData['fabricar_pedido'] ?? false) && ! $esProductoCustom,
                    'specs_personalizacion' => $specsExtra,
                    'boceto_url'            => isset($itemData['boceto_urls'])
                        ? (array_values(array_filter($itemData['boceto_urls']))[0] ?? null)
                        : ($itemData['boceto_url'] ?? null),
                    'boceto_fotos'          => isset($itemData['boceto_urls']) && count(array_filter($itemData['boceto_urls'])) > 1
                        ? array_values(array_filter($itemData['boceto_urls']))
                        : null,
                    'fecha_entrega_prom'    => null,
                ]);
            }

            $this->asignarNumeroCotizacion($orden);

            return $orden;
        });

        return response()->json(
            $cotizacion->load(['cliente:id,nombre,telefono', 'tienda:id,nombre', 'vendedor:id,nombre', 'items']),
            201
        );
    }

    /**
     * GET /api/cotizaciones/{id}
     */
    public function show(Request $request, int $id)
    {
        $cotizacion = Orden::cotizaciones()
            ->with([
                'cliente',
                'tienda:id,nombre',
                'vendedor:id,nombre,firma_url',
                'items.producto:id,nombre,categoria',
                'items.variante',
            ])
            ->findOrFail($id);

        if (! $this->puedeVer($request->user(), $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json($cotizacion);
    }

    /**
     * PATCH /api/cotizaciones/{id}/estado
     * Marca la cotización como enviada o perdida. Convertir en orden es otro
     * flujo aparte porque exige cliente formal, firma y anticipo.
     */
    public function cambiarEstado(Request $request, int $id)
    {
        $data = $request->validate([
            'cotizacion_estado' => 'required|in:abierta,enviada,perdida',
            'motivo_perdida'    => 'nullable|string|max:300',
        ]);

        $cotizacion = Orden::cotizaciones()->findOrFail($id);

        if (! $this->puedeVer($request->user(), $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($cotizacion->cotizacion_estado === 'convertida') {
            return response()->json([
                'message' => 'Esta cotización ya se convirtió en orden.',
            ], 422);
        }

        $cotizacion->update([
            'cotizacion_estado' => $data['cotizacion_estado'],
            'motivo_perdida'    => $data['cotizacion_estado'] === 'perdida'
                ? ($data['motivo_perdida'] ?? null)
                : null,
        ]);

        return response()->json($cotizacion->fresh());
    }

    /**
     * DELETE /api/cotizaciones/{id}
     * Se puede borrar sin consecuencias: no reservó stock ni generó comisión.
     */
    public function destroy(Request $request, int $id)
    {
        $cotizacion = Orden::cotizaciones()->findOrFail($id);

        if (! $this->puedeVer($request->user(), $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($cotizacion->cotizacion_estado === 'convertida') {
            return response()->json([
                'message' => 'No se puede eliminar una cotización que ya es orden.',
            ], 422);
        }

        DB::transaction(function () use ($cotizacion) {
            OrdenItem::where('orden_id', $cotizacion->id)->delete();
            $cotizacion->delete();
        });

        return response()->json(['message' => 'Cotización eliminada.']);
    }

    /**
     * POST /api/cotizaciones/{id}/verificar
     * Antes de convertir: avisa si algún precio cambió respecto al catálogo y
     * si falta stock. No modifica nada — es para que el vendedor decida.
     */
    public function verificar(Request $request, int $id)
    {
        $cotizacion = Orden::cotizaciones()
            ->with(['items.producto:id,nombre,precio_base', 'items.variante'])
            ->findOrFail($id);

        if (! $this->puedeVer($request->user(), $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json([
            'esta_vencida'     => $cotizacion->esta_vencida,
            'valida_hasta'     => $cotizacion->cotizacion_valida_hasta?->toDateString(),
            'precios_cambiados' => $this->preciosCambiados($cotizacion),
            'faltantes_stock'  => $this->faltantesStock($cotizacion),
        ]);
    }

    /**
     * POST /api/cotizaciones/{id}/convertir
     * El cliente aceptó: la cotización pasa a ser orden real. Aquí sí se exige
     * cliente formal y firma, se reserva inventario y se asigna número de orden.
     *
     * Los precios cotizados se respetan aunque el catálogo haya cambiado: es lo
     * que se le prometió al cliente. El cambio solo se advierte.
     */
    public function convertir(Request $request, int $id)
    {
        $usuario = $request->user();

        $cotizacion = Orden::cotizaciones()
            ->with(['items.producto:id,nombre,precio_base', 'items.variante'])
            ->findOrFail($id);

        if (! $this->puedeVer($usuario, $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($cotizacion->cotizacion_estado === 'convertida') {
            return response()->json(['message' => 'Esta cotización ya se convirtió en orden.'], 422);
        }

        $esPresencial = $cotizacion->canal === 'fisica';

        $data = $request->validate([
            // Cliente formal: obligatorio para una orden. Puede venir uno existente
            // o los datos para crearlo.
            'cliente_id'          => 'required_without:cliente_nuevo|nullable|exists:clientes,id',
            'cliente_nuevo'       => 'required_without:cliente_id|nullable|array',
            'cliente_nuevo.nombre'   => 'required_with:cliente_nuevo|string|max:150',
            'cliente_nuevo.telefono' => 'required_with:cliente_nuevo|string|max:40',
            'cliente_nuevo.cedula'   => 'nullable|string|max:40',
            'cliente_nuevo.email'    => 'nullable|email|max:150',

            'firma_url'           => 'required|string|max:500',
            'anticipo_monto'      => 'required|numeric|min:0',
            'anticipo_metodo'     => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia' => 'nullable|string|max:100',
            'anticipo_pagos'                => 'nullable|array|min:1',
            'anticipo_pagos.*.monto'        => 'required_with:anticipo_pagos|numeric|min:0.01',
            'anticipo_pagos.*.metodo'       => 'required_with:anticipo_pagos|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_pagos.*.referencia'   => 'nullable|string|max:100',
            'anticipo_pct'        => 'nullable|numeric|min:0|max:100',

            'anexo_foto_url'      => ($esPresencial ? 'required' : 'nullable') . '|string|max:500',
            'departamento_envio'  => 'nullable|string|max:100',
            'ciudad_envio'        => 'nullable|string|max:100',
            'direccion_envio'     => 'nullable|string|max:300',
            'notas'               => 'nullable|string|max:1000',

            // El vendedor confirma que vio las advertencias de precio.
            'aceptar_cambios_precio' => 'nullable|boolean',

            // Descuento especial: la orden nace con serie FV2.
            'es_fv2'       => 'nullable|boolean',
            'motivo_serie' => 'nullable|string|max:300',
        ]);

        if ($cotizacion->esta_vencida && ! $request->boolean('aceptar_cambios_precio')) {
            return response()->json([
                'message' => 'Esta cotización está vencida. Revisa los precios antes de convertirla.',
                'esta_vencida' => true,
                'precios_cambiados' => $this->preciosCambiados($cotizacion),
            ], 409);
        }

        $cambios = $this->preciosCambiados($cotizacion);
        if (! empty($cambios) && ! $request->boolean('aceptar_cambios_precio')) {
            return response()->json([
                'message' => 'Algunos precios cambiaron desde que se cotizó.',
                'precios_cambiados' => $cambios,
            ], 409);
        }

        $esFv2 = $request->boolean('es_fv2', false);

        $orden = DB::transaction(function () use ($cotizacion, $data, $usuario, $esFv2) {
            // 1. Cliente formal
            $clienteId = $data['cliente_id'] ?? $cotizacion->cliente_id;
            if (! $clienteId && ! empty($data['cliente_nuevo'])) {
                $clienteId = Cliente::create([
                    'nombre'   => $data['cliente_nuevo']['nombre'],
                    'telefono' => $data['cliente_nuevo']['telefono'],
                    'cedula'   => $data['cliente_nuevo']['cedula'] ?? null,
                    'email'    => $data['cliente_nuevo']['email']  ?? null,
                ])->id;
            }

            // 2. Stock: es la primera vez que esta cotización toca inventario.
            //    Se verifica y reserva con bloqueo, igual que una orden nueva.
            foreach ($cotizacion->items as $item) {
                if ($item->es_personalizado || ! $item->producto_id) continue;

                $origenTiendaId = $item->tienda_origen_id ?? $cotizacion->tienda_id;

                $inv = $item->variante_id
                    ? InventarioVariante::where('variante_id', $item->variante_id)
                        ->where('tienda_id', $origenTiendaId)->lockForUpdate()->first()
                    : Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $origenTiendaId)->lockForUpdate()->first();

                $stockLibre = $inv ? $inv->cantidad_disponible - $inv->cantidad_reservada : 0;

                if ($stockLibre < $item->cantidad) {
                    $nombre = $item->producto->nombre ?? $item->nombre_custom ?? 'ítem';
                    abort(422, "Ya no hay stock suficiente de \"{$nombre}\". Libre: {$stockLibre}, necesario: {$item->cantidad}.");
                }

                if ($item->variante_id) {
                    InventarioVariante::where('variante_id', $item->variante_id)
                        ->where('tienda_id', $origenTiendaId)
                        ->increment('cantidad_reservada', $item->cantidad);
                    if ($item->combo_config_id) {
                        InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                            ->where('config_id', $item->combo_config_id)
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $item->cantidad);
                    }
                }

                Inventario::where('producto_id', $item->producto_id)
                    ->where('tienda_id', $origenTiendaId)
                    ->increment('cantidad_reservada', $item->cantidad);

                InventarioMovimiento::create([
                    'producto_id' => $item->producto_id,
                    'tienda_id'   => $origenTiendaId,
                    'tipo'        => 'reserva',
                    'cantidad'    => $item->cantidad,
                    'motivo'      => "Cotización {$cotizacion->cotizacion_ref} convertida en orden",
                    'usuario_id'  => $usuario->id,
                ]);
            }

            // 3. Producción para los ítems personalizados
            foreach ($cotizacion->items->where('es_personalizado', true) as $item) {
                if (! $item->produccion) {
                    Produccion::create([
                        'orden_item_id'    => $item->id,
                        'fecha_inicio'     => now()->toDateString(),
                        'fecha_compromiso' => null,
                        'estado'           => 'pendiente',
                    ]);
                }
            }

            $tieneItemsSinPrecio = $cotizacion->items->contains(
                fn($i) => $i->es_personalizado && (float) $i->precio_unitario === 0.0
            );

            // 4. La cotización se transforma en orden: misma fila, sin duplicar ítems.
            $cotizacion->update([
                'cliente_id'         => $clienteId,
                'estado'             => $tieneItemsSinPrecio ? 'pendiente_cotizacion' : 'pendiente_anticipo',
                'cotizacion_estado'  => 'convertida',
                'firma_url'          => $data['firma_url'],
                'anexo_foto_url'     => $data['anexo_foto_url']     ?? null,
                'departamento_envio' => $data['departamento_envio'] ?? null,
                'ciudad_envio'       => $data['ciudad_envio']       ?? null,
                'direccion_envio'    => $data['direccion_envio']    ?? null,
                'notas'              => $data['notas'] ?? $cotizacion->notas,
                'anticipo_pct'       => $data['anticipo_pct'] ?? 50,
                // Si se marcó descuento especial, la orden nace con serie FV2 y el número
                // se asigna abajo junto con el resto de la numeración.
                'serie'              => $esFv2 ? Orden::SERIE_FV2 : null,
                'motivo_serie'       => $esFv2 ? ($data['motivo_serie'] ?? null) : null,
            ]);

            // 5. Anticipo
            if (($data['anticipo_monto'] ?? 0) > 0) {
                if (! empty($data['anticipo_pagos'])) {
                    foreach ($data['anticipo_pagos'] as $p) {
                        if (($p['monto'] ?? 0) > 0) {
                            $cotizacion->pagos()->create([
                                'vendedor_id' => $usuario->id,
                                'tipo'        => 'anticipo',
                                'monto'       => $p['monto'],
                                'metodo'      => $p['metodo'],
                                'referencia'  => $p['referencia'] ?? null,
                            ]);
                        }
                    }
                } else {
                    $cotizacion->pagos()->create([
                        'vendedor_id' => $usuario->id,
                        'tipo'        => 'anticipo',
                        'monto'       => $data['anticipo_monto'],
                        'metodo'      => $data['anticipo_metodo'] ?? 'efectivo',
                        'referencia'  => $data['anticipo_referencia'] ?? null,
                    ]);
                }
            }

            return $cotizacion;
        });

        // Número de orden real y comisión: solo ahora que es una venta. Si
        // quedó esperando el precio de algún ítem todavía puede no cerrarse, y
        // el número se asigna cuando el cliente acepte (confirmarCotizacion).
        if ($orden->fresh()->estado !== 'pendiente_cotizacion') {
            OrdenController::asignarNumeroOrden($orden);
            ComisionController::crearParaOrden($orden->fresh());
        }

        $ordenFresh = $orden->fresh()->load([
            'cliente:id,nombre,cedula,telefono',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
            'items.produccion',
            'pagos',
        ]);

        foreach (Usuario::where('rol', 'supervisor')->where('activo', true)->where('id', '!=', $usuario->id)->get() as $sup) {
            NotificacionService::crear(
                'venta_nueva',
                'Cotización convertida en venta',
                "Orden {$ordenFresh->referencia} — " . ($ordenFresh->cliente->nombre ?? 'Cliente')
                    . ' · $' . number_format($ordenFresh->valor_total, 0, ',', '.') . ' COP',
                ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
                $sup->id,
            );
        }

        return response()->json($ordenFresh);
    }

    /**
     * GET /api/cotizaciones/{id}/pdf
     * Documento propio: sin saldo, sin firma del cliente y con la validez visible.
     */
    public function pdf(Request $request, int $id)
    {
        $cotizacion = Orden::cotizaciones()
            ->with([
                'cliente',
                'tienda:id,nombre',
                'vendedor:id,nombre,firma_url',
                'items.producto:id,nombre,categoria',
            ])
            ->findOrFail($id);

        if (! $this->puedeVer($request->user(), $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $firmaVendedor = $this->urlToBase64($cotizacion->vendedor?->firma_url);
        $logoBase64    = $this->avifToPngBase64(public_path('img/logo.avif'));

        $pdf = Pdf::loadView('pdf.cotizacion', compact('cotizacion', 'firmaVendedor', 'logoBase64'));
        $pdf->setPaper('letter');

        $nombre = strtolower($cotizacion->cotizacion_ref ?? ('cotizacion-' . $cotizacion->id));

        return $pdf->download($nombre . '.pdf');
    }

    /**
     * POST /api/cotizaciones/{id}/enviar
     *
     * Manda la cotización al cliente por correo. El correo es opcional en todo
     * el módulo —mucha gente solo deja el teléfono—, así que se puede escribir
     * uno en el momento; si tampoco hay, queda WhatsApp, que no pasa por aquí.
     */
    public function enviar(Request $request, int $id)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $cotizacion = Orden::cotizaciones()
            ->with('cliente:id,nombre,email')
            ->findOrFail($id);

        if (! $this->puedeVer($request->user(), $cotizacion)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'email' => 'nullable|email|max:150',
        ]);

        $email = $data['email']
            ?? $cotizacion->cliente?->email
            ?? $cotizacion->contacto_email;

        if (! $email) {
            return response()->json([
                'message' => 'No hay correo al que enviarla. Escribe uno, o mándala por WhatsApp.',
                'errors'  => ['email' => ['Hace falta un correo.']],
            ], 422);
        }

        try {
            Mail::to($email)->send(new CotizacionEnviadaMail($cotizacion->id));
        } catch (\Throwable $e) {
            try {
                \Log::error('CotizacionController::enviar: fallo', [
                    'cotizacion_id' => $cotizacion->id,
                    'error'         => $e->getMessage(),
                ]);
            } catch (\Throwable) {}
            return response()->json(['message' => 'No se pudo enviar el correo: ' . $e->getMessage()], 502);
        }

        // Si el cliente no tenía correo guardado y el vendedor escribió uno, se
        // guarda: la próxima vez ya no hay que volver a pedirlo.
        if (! $cotizacion->contacto_email && ! $cotizacion->cliente?->email) {
            $cotizacion->update(['contacto_email' => $email]);
        }

        return response()->json(['message' => "Cotización enviada a {$email}."]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * COT-N con contador propio por grupo de tienda, separado del consecutivo
     * de órdenes para no gastar números reales en gente que solo pregunta.
     */
    private function asignarNumeroCotizacion(Orden $cotizacion): void
    {
        $grupo  = OrdenController::grupoDeTienda($cotizacion->tienda_id);
        $seqKey = $grupo ? 'cot_' . $grupo : null;

        if ($seqKey) {
            $actual = DB::table('orden_secuencias')
                ->where('grupo', $seqKey)
                ->lockForUpdate()
                ->value('ultimo_numero');

            // La secuencia se crea en la migración, pero si la tienda es nueva
            // y su grupo no existe todavía, se inicializa aquí.
            if ($actual === null) {
                DB::table('orden_secuencias')->insert(['grupo' => $seqKey, 'ultimo_numero' => 0]);
                $actual = 0;
            }

            $siguiente = $actual + 1;

            DB::table('orden_secuencias')
                ->where('grupo', $seqKey)
                ->update(['ultimo_numero' => $siguiente]);
        } else {
            // Tienda sin grupo: consecutivo global de cotizaciones.
            $siguiente = (int) (Orden::cotizaciones()->lockForUpdate()->max('cotizacion_numero') ?? 0) + 1;
        }

        $cotizacion->update([
            'cotizacion_numero' => $siguiente,
            'grupo_secuencia'   => $grupo,
        ]);
    }

    /**
     * Ítems cuyo precio de catálogo ya no coincide con el cotizado.
     * No bloquea: el precio prometido al cliente se respeta, solo se avisa.
     */
    private function preciosCambiados(Orden $cotizacion): array
    {
        $cambios = [];

        foreach ($cotizacion->items as $item) {
            if ($item->es_personalizado || ! $item->producto_id) continue;

            $precioActual = $item->variante_id && $item->variante?->precio_variante
                ? (float) $item->variante->precio_variante
                : (float) ($item->producto->precio_base ?? 0);

            if ($precioActual <= 0) continue;

            if (abs($precioActual - (float) $item->precio_unitario) >= 0.01) {
                $cambios[] = [
                    'item_id'        => $item->id,
                    'nombre'         => $item->producto->nombre ?? $item->nombre_custom,
                    'precio_cotizado' => (float) $item->precio_unitario,
                    'precio_actual'  => $precioActual,
                    'diferencia'     => round($precioActual - (float) $item->precio_unitario, 2),
                ];
            }
        }

        return $cambios;
    }

    /**
     * Ítems de catálogo que hoy no tienen stock libre suficiente.
     */
    private function faltantesStock(Orden $cotizacion): array
    {
        $faltantes = [];

        foreach ($cotizacion->items as $item) {
            if ($item->es_personalizado || ! $item->producto_id) continue;

            $origenTiendaId = $item->tienda_origen_id ?? $cotizacion->tienda_id;

            $inv = $item->variante_id
                ? InventarioVariante::where('variante_id', $item->variante_id)
                    ->where('tienda_id', $origenTiendaId)->first()
                : Inventario::where('producto_id', $item->producto_id)
                    ->where('tienda_id', $origenTiendaId)->first();

            $libre = $inv ? $inv->cantidad_disponible - $inv->cantidad_reservada : 0;

            if ($libre < $item->cantidad) {
                $faltantes[] = [
                    'item_id'   => $item->id,
                    'nombre'    => $item->producto->nombre ?? $item->nombre_custom,
                    'necesario' => (int) $item->cantidad,
                    'libre'     => (int) $libre,
                ];
            }
        }

        return $faltantes;
    }

    private function veTodas($usuario): bool
    {
        return $usuario->rol === 'supervisor'
            || (bool) ($usuario->facturacion ?? false);
    }

    private function puedeVer($usuario, Orden $cotizacion): bool
    {
        return $this->veTodas($usuario) || $cotizacion->vendedor_id === $usuario->id;
    }
}
