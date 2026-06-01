<?php

namespace App\Http\Controllers;

use App\Events\DespachoAsignado;
use App\Events\OrdenEntregada;
use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DespachoController extends Controller
{
    /**
     * GET /api/despacho/cola
     * Órdenes en listo_entrega, ordenadas cronológicamente.
     */
    public function cola(Request $request)
    {
        $ordenes = Orden::with([
            'cliente:id,nombre,telefono,direccion',
            'tienda:id,nombre',
        ])->withSum('pagos', 'monto')
            ->where('estado', 'listo_entrega')
            ->orderBy('listo_entrega_at')
            ->get();

        $ordenes->transform(function ($o) {
            $o->total_pagado    = (float) ($o->pagos_sum_monto ?? 0);
            $o->saldo_pendiente = (float) $o->valor_total - $o->total_pagado;
            unset($o->pagos_sum_monto);
            return $o;
        });

        return response()->json($ordenes);
    }

    /**
     * GET /api/despacho/asignados
     * Órdenes en estado en_camino con su despacho y conductor.
     */
    public function asignados(Request $request)
    {
        $conductorId = $request->query('conductor_id');
        $desde       = $request->query('desde');
        $hasta       = $request->query('hasta');

        $items = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'despacho.supervisor:id,nombre',
            'orden:id,cliente_id,tienda_id,valor_total,estado,created_at',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
        ])->whereHas('despacho', function ($q) use ($conductorId, $desde, $hasta) {
            $q->whereIn('estado', ['asignado', 'en_ruta']);
            if ($conductorId) $q->where('conductor_id', $conductorId);
            if ($desde)       $q->whereDate('created_at', '>=', $desde);
            if ($hasta)       $q->whereDate('created_at', '<=', $hasta);
        })
            ->orderBy('despacho_id')
            ->orderBy('posicion')
            ->get();

        $agrupado = $items->groupBy('despacho_id')->values();

        return response()->json($agrupado);
    }

    /**
     * POST /api/despacho/asignar
     * Crea un despacho con sus items.
     */
    public function asignar(Request $request)
    {
        $data = $request->validate([
            'conductor_id'       => 'required|exists:usuarios,id',
            'ordenes'            => 'required|array|min:1',
            'ordenes.*.orden_id' => 'required|exists:ordenes,id',
            'ordenes.*.posicion' => 'required|integer|min:1',
            'notas'              => 'nullable|string|max:1000',
        ]);

        $conductor = Usuario::findOrFail($data['conductor_id']);
        if ($conductor->rol !== 'conductor') {
            return response()->json(['message' => 'El usuario seleccionado no es un conductor.'], 422);
        }

        $usuario = $request->user();

        $despacho = DB::transaction(function () use ($data, $usuario) {
            $despacho = Despacho::create([
                'conductor_id'  => $data['conductor_id'],
                'supervisor_id' => $usuario->id,
                'estado'        => 'asignado',
                'notas'         => $data['notas'] ?? null,
            ]);

            foreach ($data['ordenes'] as $item) {
                // lockForUpdate previene race condition si dos requests asignan la misma orden simultáneamente
                $orden = Orden::lockForUpdate()->findOrFail($item['orden_id']);

                if ($orden->estado !== 'listo_entrega') {
                    abort(422, "La orden #{$orden->id} no está en estado listo_entrega.");
                }

                // Verificar que la orden no esté ya en otro despacho activo de cualquier conductor
                $yaAsignada = DespachoItem::where('orden_id', $item['orden_id'])
                    ->where('estado', 'pendiente')
                    ->whereHas('despacho', fn($q) => $q->whereIn('estado', ['asignado', 'en_ruta']))
                    ->exists();

                if ($yaAsignada) {
                    abort(422, "La orden #{$orden->id} ya está en un despacho activo.");
                }

                DespachoItem::create([
                    'despacho_id' => $despacho->id,
                    'orden_id'    => $item['orden_id'],
                    'posicion'    => $item['posicion'],
                    'estado'      => 'pendiente',
                ]);

                $orden->update([
                    'estado' => 'en_camino',
                ]);
            }

            return $despacho;
        });

        $despacho->load('items.orden.cliente:id,nombre', 'conductor:id,nombre');

        event(new DespachoAsignado(
            $despacho->id,
            (int) $data['conductor_id'],
            count($data['ordenes']),
        ));

        NotificacionService::crear(
            'despacho_asignado',
            'Nuevas entregas asignadas',
            "Tienes " . count($data['ordenes']) . " entrega(s) asignada(s) por " . $usuario->nombre,
            ['despacho_id' => $despacho->id],
            $data['conductor_id'],
        );

        return response()->json($despacho, 201);
    }

    /**
     * GET /api/despacho/conductores
     * Lista de conductores activos.
     */
    public function conductores(Request $request)
    {
        $conductores = Usuario::where('rol', 'conductor')
            ->where('activo', true)
            ->get(['id', 'nombre', 'email', 'tienda_default_id']);

        return response()->json($conductores);
    }

    /**
     * GET /api/despacho/historial
     */
    public function historial(Request $request)
    {
        $query = Despacho::with([
            'conductor:id,nombre',
            'items.orden.cliente:id,nombre',
        ])->where('estado', 'completado');

        if ($v = $request->query('conductor_id')) {
            $query->where('conductor_id', $v);
        }
        if ($v = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $v);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * GET /api/despacho/{id}
     */
    public function show(int $id)
    {
        $despacho = Despacho::with([
            'conductor:id,nombre',
            'supervisor:id,nombre',
            'items.orden.cliente:id,nombre,telefono,direccion',
            'items.orden.tienda:id,nombre',
            'items.orden.pagos',
        ])->findOrFail($id);

        $despacho->items->each(function ($item) {
            $item->orden->total_pagado    = (float) $item->orden->pagos->sum('monto');
            $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $item->orden->total_pagado;
        });

        return response()->json($despacho);
    }

    /**
     * GET /api/despacho/por-orden/{ordenId}
     * Devuelve los datos del despacho_item y despacho para una orden entregada.
     * Accesible por supervisor, vendedor y conductor.
     */
    public function porOrden(int $ordenId)
    {
        $item = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'despacho.supervisor:id,nombre',
        ])->where('orden_id', $ordenId)->first();

        if (! $item) {
            return response()->json(null);
        }

        return response()->json($item);
    }

    /**
     * GET /api/despacho/mis-entregas
     * Conductor autenticado: lista sus entregas activas ordenadas por posicion.
     */
    public function misEntregas(Request $request)
    {
        $usuario = $request->user();

        $items = DespachoItem::with([
            'despacho:id,conductor_id,notas',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
            'orden.pagos:id,orden_id,monto',
        ])->whereHas('despacho', function ($q) use ($usuario) {
            $q->where('conductor_id', $usuario->id)
                ->whereIn('estado', ['asignado', 'en_ruta']);
        })->where('estado', 'pendiente')
            ->orderBy('despacho_id')  // despachos más antiguos primero (los que ya venía haciendo)
            ->orderBy('posicion')     // dentro de cada despacho, el orden asignado
            ->get()
            ->unique('orden_id')  // evita duplicados si la misma orden está en dos despachos activos
            ->values();

        $items->each(function ($item) {
            $totalPagado = (float) $item->orden->pagos->sum('monto');
            $item->orden->total_pagado    = $totalPagado;
            $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $totalPagado;
            unset($item->orden->pagos);
        });

        return response()->json($items);
    }

    /**
     * GET /api/despacho/mis-entregas/historial
     * Conductor autenticado: entregas ya completadas, paginadas.
     */
    public function misHistorial(Request $request)
    {
        $usuario = $request->user();

        $items = DespachoItem::with([
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
        ])->whereHas('despacho', function ($q) use ($usuario) {
            $q->where('conductor_id', $usuario->id);
        })->where('estado', 'entregado')
            ->orderByDesc('entregado_at')
            ->paginate(20);

        return response()->json($items);
    }

    /**
     * GET /api/despacho/mis-entregas/{despachoItemId}
     */
    public function showEntrega(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();

        $item = DespachoItem::with([
            'despacho:id,conductor_id,notas',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
            'orden.pagos:id,orden_id,monto,metodo,referencia,created_at',
        ])->findOrFail($despachoItemId);

        if ($item->despacho->conductor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $item->orden->total_pagado    = $item->orden->totalPagado();
        $item->orden->saldo_pendiente = $item->orden->saldoPendiente();

        return response()->json($item);
    }

    /**
     * POST /api/despacho/mis-entregas/{despachoItemId}/pago
     * Multipart: monto, metodo, referencia, foto_producto, foto_pago
     */
    public function registrarPago(Request $request, int $despachoItemId)
    {
        $data = $request->validate([
            'monto'         => 'required|numeric|min:1',
            'metodo'        => 'required|in:efectivo,transferencia,tarjeta,otro',
            'referencia'    => 'nullable|string|max:100',
            'foto_producto' => 'required|image|max:10240',
            'foto_pago'     => 'required|image|max:10240',
        ]);

        $usuario = $request->user();

        $item = DespachoItem::with('despacho', 'orden')->findOrFail($despachoItemId);

        if ($item->despacho->conductor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($item->estado === 'entregado') {
            return response()->json(['message' => 'Esta entrega ya fue completada.'], 422);
        }

        $saldoPendiente = $item->orden->saldoPendiente();
        if ($data['monto'] > $saldoPendiente + 0.01) {
            return response()->json([
                'message' => "El monto ($data[monto]) supera el saldo pendiente (" . round($saldoPendiente, 2) . ").",
            ], 422);
        }

        $fotoProducto = $this->subirCloudinary($request->file('foto_producto'));
        $fotoPago     = $this->subirCloudinary($request->file('foto_pago'));

        DB::transaction(function () use ($item, $data, $usuario, $fotoProducto, $fotoPago) {
            $item->update([
                'foto_producto' => $fotoProducto,
                'foto_pago'     => $fotoPago,
            ]);

            Pago::create([
                'orden_id'    => $item->orden_id,
                'vendedor_id' => $usuario->id,
                'tipo'        => 'saldo_final',
                'monto'       => $data['monto'],
                'metodo'      => $data['metodo'],
                'referencia'  => $data['referencia'] ?? null,
            ]);
        });

        $item->load('orden.cliente:id,nombre');
        $item->orden->total_pagado    = $item->orden->totalPagado();
        $item->orden->saldo_pendiente = $item->orden->saldoPendiente();

        return response()->json($item);
    }

    /**
     * PATCH /api/despacho/mis-entregas/{despachoItemId}/entregar
     * Marca como entregado — requiere fotos + pago previos.
     */
    public function entregar(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();

        $item = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'orden.cliente:id,nombre',
        ])->findOrFail($despachoItemId);

        if ($item->despacho->conductor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($item->estado === 'entregado') {
            return response()->json(['message' => 'Ya fue entregada.'], 422);
        }
        if (! $item->puedeEntregar()) {
            return response()->json([
                'message' => 'Debes registrar el pago y subir ambas fotos antes de marcar como entregado.',
            ], 422);
        }

        $orden = $item->orden()->with(['items' => fn($q) => $q->where('es_personalizado', false)])->first();

        DB::transaction(function () use ($item, $orden, $now = null) {
            $now = now();

            $item->update([
                'estado'       => 'entregado',
                'entregado_at' => $now,
            ]);

            $orden->update(['estado' => 'entregado']);

            Produccion::whereIn('orden_item_id', function ($q) use ($item) {
                $q->select('id')
                    ->from('orden_items')
                    ->where('orden_id', $item->orden_id)
                    ->where('es_personalizado', true);
            })->whereIn('estado', ['pendiente', 'en_proceso', 'listo', 'retrasado'])
                ->update([
                    'estado'     => 'entregado',
                    'fecha_real' => $now->toDateString(),
                ]);

            // Decrementar inventario de los ítems de stock (no personalizados)
            foreach ($orden->items as $ordenItem) {
                $origenId = $ordenItem->tienda_origen_id ?? $orden->tienda_id;
                if ($ordenItem->variante_id) {
                    InventarioVariante::where('variante_id', $ordenItem->variante_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$ordenItem->cantidad}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$ordenItem->cantidad}"),
                        ]);
                    Inventario::where('producto_id', $ordenItem->producto_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$ordenItem->cantidad}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$ordenItem->cantidad}"),
                        ]);
                } else {
                    Inventario::where('producto_id', $ordenItem->producto_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$ordenItem->cantidad}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$ordenItem->cantidad}"),
                        ]);
                }
                InventarioMovimiento::create([
                    'producto_id' => $ordenItem->producto_id,
                    'tienda_id'   => $origenId,
                    'tipo'        => 'salida',
                    'cantidad'    => $ordenItem->cantidad,
                    'motivo'      => "Entrega orden #{$orden->id} — conductor",
                    'usuario_id'  => $item->despacho->conductor_id,
                ]);
            }

            $completados = DespachoItem::where('despacho_id', $item->despacho_id)
                ->where('estado', 'entregado')
                ->count();
            $total = DespachoItem::where('despacho_id', $item->despacho_id)->count();

            if ($completados >= $total) {
                $item->despacho()->update(['estado' => 'completado']);
            }
        });

        event(new OrdenEntregada(
            $item->orden_id,
            $item->orden->cliente->nombre,
            $item->despacho->conductor->nombre,
        ));

        NotificacionService::crear(
            'entregado',
            'Orden entregada por conductor',
            "Orden #{$item->orden_id} de {$item->orden->cliente->nombre} fue entregada por {$item->despacho->conductor->nombre}",
            ['orden_id' => $item->orden_id],
        );

        $facturacionVendedores = Usuario::where('rol', 'vendedor')
            ->where('facturacion', true)
            ->where('tienda_default_id', $item->orden->tienda_id)
            ->get();

        foreach ($facturacionVendedores as $vendedor) {
            NotificacionService::crear(
                'facturar',
                'Orden pendiente de facturación',
                "Orden #{$item->orden_id} de {$item->orden->cliente->nombre} fue entregada — pendiente de facturación",
                ['orden_id' => $item->orden_id],
                $vendedor->id,
            );
        }

        return response()->json($item);
    }

    private function subirCloudinary($file): string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $timestamp = time();
        $folder    = 'decasa/entregas';

        $signature = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");

        $response = Http::attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => $folder,
        ]);

        if (! $response->ok()) {
            abort(502, 'Error al subir imagen a Cloudinary.');
        }

        return $response->json('secure_url');
    }
}
