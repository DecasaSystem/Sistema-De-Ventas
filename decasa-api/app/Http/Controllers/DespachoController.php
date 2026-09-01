<?php

namespace App\Http\Controllers;

use App\Events\DespachoAsignado;
use App\Events\OrdenEntregada;
use App\Models\Camion;
use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\Devolucion;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Services\DescuentoCondicionadoService;
use App\Services\NotificacionService;
use App\Support\ConvierteImagenesPdf;
use App\Support\StockVariantes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DespachoController extends Controller
{
    use ConvierteImagenesPdf;

    /**
     * GET /api/despacho/cola
     * Órdenes en listo_entrega SIN asignar a ninguna ruta/despacho activo.
     */
    public function cola(Request $request)
    {
        $ordenes = Orden::with([
            'cliente:id,nombre,telefono,direccion',
            'tienda:id,nombre',
        ])->withSum('pagos', 'monto')
            ->where('estado', 'listo_entrega')
            ->whereDoesntHave('despachoItem', fn($q) =>
                $q->whereHas('despacho', fn($q2) =>
                    $q2->whereIn('estado', ['borrador', 'asignado', 'en_ruta'])
                )
            )
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

    // ── Rutas (borradores) ────────────────────────────────────────────────────

    /**
     * PATCH /api/despacho/{id}/reprogramar
     * Supervisor reprograma la fecha de una ruta ya enviada (asignado).
     */
    public function reprogramarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'fecha_despacho' => 'required|date',
        ]);

        $despacho = Despacho::whereIn('estado', ['asignado', 'borrador'])->findOrFail($id);
        $despacho->update(['fecha_despacho' => $data['fecha_despacho']]);

        // Notificar al conductor de la nueva fecha
        if ($despacho->conductor_id) {
            $fechaFmt   = \Carbon\Carbon::parse($data['fecha_despacho'])->locale('es')->isoFormat('D [de] MMMM');
            $nombreRuta = $despacho->nombre_ruta ?? "Ruta #{$despacho->id}";
            NotificacionService::crear(
                'ruta_atrasada',
                'Fecha de ruta actualizada',
                "{$nombreRuta} ha sido reprogramada para el {$fechaFmt}",
                ['despacho_id' => $despacho->id],
                $despacho->conductor_id,
            );
        }

        return response()->json($despacho);
    }

    /**
     * GET /api/despacho/rutas
     * Lista rutas en borrador del supervisor.
     */
    public function rutas(Request $request)
    {
        $rutas = Despacho::with([
            'camion:id,nombre',
            'items' => fn($q) => $q->orderBy('posicion'),
            'items.orden:id,cliente_id,valor_total',
            'items.orden.cliente:id,nombre,telefono,direccion',
            'items.orden.pagos:id,orden_id,monto',
        ])->where('estado', 'borrador')
            ->orderBy('fecha_despacho')
            ->orderByDesc('created_at')
            ->get();

        $rutas->each(fn($ruta) => $ruta->items->each(function ($item) {
            if ($item->orden) {
                $pagado = (float) $item->orden->pagos->sum('monto');
                $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $pagado;
                unset($item->orden->pagos);
            }
        }));

        return response()->json($rutas);
    }

    /**
     * POST /api/despacho/rutas
     * Crea una ruta en borrador (nombre + fecha, sin órdenes aún).
     */
    public function crearRuta(Request $request)
    {
        $data = $request->validate([
            'nombre_ruta'    => 'required|string|max:120',
            'fecha_despacho' => 'required|date',
            'instrucciones'  => 'nullable|string|max:2000',
        ]);

        $despacho = Despacho::create([
            'supervisor_id'  => $request->user()->id,
            'estado'         => 'borrador',
            'nombre_ruta'    => $data['nombre_ruta'],
            'fecha_despacho' => $data['fecha_despacho'],
            'instrucciones'  => $data['instrucciones'] ?? null,
        ]);

        return response()->json($despacho, 201);
    }

    /**
     * PATCH /api/despacho/rutas/{id}
     * Edita nombre, fecha o instrucciones de una ruta borrador.
     */
    public function actualizarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'nombre_ruta'    => 'sometimes|nullable|string|max:120',
            'fecha_despacho' => 'sometimes|required|date',
            'instrucciones'  => 'sometimes|nullable|string|max:2000',
        ]);

        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);
        $ruta->update($data);

        return response()->json($ruta);
    }

    /**
     * DELETE /api/despacho/rutas/{id}
     * Elimina una ruta borrador y devuelve sus órdenes a la cola.
     */
    public function eliminarRuta(int $id)
    {
        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);

        DB::transaction(function () use ($ruta) {
            $ruta->items()->delete();
            $ruta->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/despacho/rutas/{id}/ordenes
     * Agrega una orden de la cola a una ruta borrador.
     */
    public function agregarOrdenARuta(Request $request, int $id)
    {
        $data  = $request->validate(['orden_id' => 'required|exists:ordenes,id']);
        $ruta  = Despacho::where('estado', 'borrador')->findOrFail($id);
        $orden = Orden::findOrFail($data['orden_id']);

        if ($orden->estado !== 'listo_entrega') {
            return response()->json(['message' => 'La orden no está en cola de entrega.'], 422);
        }

        $yaEnRuta = DespachoItem::where('orden_id', $data['orden_id'])
            ->whereHas('despacho', fn($q) => $q->whereIn('estado', ['borrador', 'asignado', 'en_ruta']))
            ->exists();

        if ($yaEnRuta) {
            return response()->json(['message' => 'Esta orden ya está en una ruta activa.'], 422);
        }

        $posicion = $ruta->items()->count() + 1;

        $item = DespachoItem::create([
            'despacho_id' => $ruta->id,
            'orden_id'    => $data['orden_id'],
            'posicion'    => $posicion,
            'estado'      => 'pendiente',
        ]);

        $item->load('orden:id,cliente_id,valor_total', 'orden.cliente:id,nombre,telefono,direccion', 'orden.pagos:id,orden_id,monto');
        if ($item->orden) {
            $pagado = (float) $item->orden->pagos->sum('monto');
            $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $pagado;
            unset($item->orden->pagos);
        }

        return response()->json($item, 201);
    }

    /**
     * DELETE /api/despacho/rutas/{id}/ordenes/{itemId}
     * Quita una orden de una ruta borrador (la devuelve a la cola).
     */
    public function quitarOrdenDeRuta(int $id, int $itemId)
    {
        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);
        $item = DespachoItem::where('despacho_id', $ruta->id)->findOrFail($itemId);
        $item->delete();

        // Reindexar posiciones
        $ruta->items()->orderBy('posicion')->get()->each(function ($it, $i) {
            $it->update(['posicion' => $i + 1]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/despacho/rutas/{id}/reordenar
     * Actualiza las posiciones de los items de una ruta borrador.
     */
    public function reordenarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'items'            => 'required|array',
            'items.*.id'       => 'required|integer',
            'items.*.posicion' => 'required|integer|min:1',
        ]);

        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);

        DB::transaction(function () use ($data, $ruta) {
            foreach ($data['items'] as $itemData) {
                DespachoItem::where('despacho_id', $ruta->id)
                    ->where('id', $itemData['id'])
                    ->update(['posicion' => $itemData['posicion']]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/despacho/rutas/{id}/enviar
     * Cierra la ruta: asigna camión, cambia estados y notifica al conductor.
     */
    public function enviarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'camion_id'     => 'required|exists:camiones,id',
            'nombre_ruta'   => 'sometimes|nullable|string|max:120',
            'instrucciones' => 'sometimes|nullable|string|max:2000',
        ]);
        $ruta    = Despacho::with('items.orden')->where('estado', 'borrador')->findOrFail($id);
        $usuario = $request->user();

        if ($ruta->items->isEmpty()) {
            return response()->json(['message' => 'La ruta no tiene órdenes asignadas.'], 422);
        }

        $camion = Camion::findOrFail($data['camion_id']);
        if (! $camion->conductor_id) {
            return response()->json(['message' => 'El camión no tiene conductor asignado.'], 422);
        }

        $conductor = Usuario::findOrFail($camion->conductor_id);
        if (! $conductor->activo || $conductor->rol !== 'conductor') {
            return response()->json(['message' => 'El conductor del camión no está disponible.'], 422);
        }

        DB::transaction(function () use ($ruta, $camion, $conductor, $usuario, $data) {
            // Las órdenes NO pasan a en_camino aquí — lo hacen cuando el conductor inicia la ruta
            $update = [
                'camion_id'    => $camion->id,
                'conductor_id' => $conductor->id,
                'supervisor_id' => $usuario->id,
                'estado'       => 'asignado',
            ];
            if (array_key_exists('nombre_ruta', $data))   $update['nombre_ruta']   = $data['nombre_ruta'];
            if (array_key_exists('instrucciones', $data)) $update['instrucciones'] = $data['instrucciones'];
            $ruta->update($update);
        });

        event(new DespachoAsignado($ruta->id, $conductor->id, $ruta->items->count()));

        $fechaFmt   = \Carbon\Carbon::parse($ruta->fecha_despacho)->locale('es')->isoFormat('D [de] MMMM');
        $nombreRuta = $ruta->nombre_ruta ? " — {$ruta->nombre_ruta}" : '';

        NotificacionService::crear(
            'despacho_asignado',
            'Nuevas entregas asignadas',
            "Tienes {$ruta->items->count()} entrega(s) para el {$fechaFmt}{$nombreRuta}",
            ['despacho_id' => $ruta->id],
            $conductor->id,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/despacho/asignados
     * Órdenes en estado en_camino agrupadas por despacho (camión + fecha).
     */
    public function asignados(Request $request)
    {
        $camionId = $request->query('camion_id');
        $desde    = $request->query('desde');
        $hasta    = $request->query('hasta');

        $items = DespachoItem::with([
            'despacho.camion:id,nombre,placa',
            'despacho.conductor:id,nombre',
            'despacho.supervisor:id,nombre',
            'orden:id,cliente_id,tienda_id,valor_total,estado,created_at',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.pagos:id,orden_id,monto',
        ])->whereHas('despacho', function ($q) use ($camionId, $desde, $hasta) {
            $q->whereIn('estado', ['asignado', 'en_ruta']);
            if ($camionId) $q->where('camion_id', $camionId);
            if ($desde)    $q->whereDate('fecha_despacho', '>=', $desde);
            if ($hasta)    $q->whereDate('fecha_despacho', '<=', $hasta);
        })
            ->orderBy('despacho_id')
            ->orderBy('posicion')
            ->get();

        $items->each(function ($item) {
            if ($item->orden) {
                $totalPagado = (float) $item->orden->pagos->sum('monto');
                $item->orden->total_pagado    = $totalPagado;
                $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $totalPagado;
                unset($item->orden->pagos);
            }
        });

        $agrupado = $items->groupBy('despacho_id')->values();

        return response()->json($agrupado);
    }

    /**
     * POST /api/despacho/asignar
     * Crea un despacho asignado a un camión (el conductor se toma del camión).
     */
    public function asignar(Request $request)
    {
        $data = $request->validate([
            'camion_id'          => 'required|exists:camiones,id',
            'fecha_despacho'     => 'required|date',
            'ordenes'            => 'required|array|min:1',
            'ordenes.*.orden_id' => 'required|exists:ordenes,id',
            'ordenes.*.posicion' => 'required|integer|min:1',
            'nombre_ruta'        => 'nullable|string|max:120',
            'instrucciones'      => 'nullable|string|max:2000',
            'notas'              => 'nullable|string|max:1000',
        ]);

        $camion = Camion::findOrFail($data['camion_id']);

        if (! $camion->conductor_id) {
            return response()->json(['message' => 'El camión no tiene conductor asignado.'], 422);
        }

        $conductor = Usuario::findOrFail($camion->conductor_id);

        if ($conductor->rol !== 'conductor') {
            return response()->json(['message' => 'El usuario asignado al camión no es un conductor.'], 422);
        }
        if (! $conductor->activo) {
            return response()->json(['message' => 'El conductor del camión no está activo.'], 422);
        }

        $usuario = $request->user();

        $despacho = DB::transaction(function () use ($data, $usuario, $camion, $conductor) {
            $despacho = Despacho::create([
                'camion_id'      => $camion->id,
                'conductor_id'   => $conductor->id,
                'supervisor_id'  => $usuario->id,
                'fecha_despacho' => $data['fecha_despacho'],
                'estado'         => 'asignado',
                'nombre_ruta'    => $data['nombre_ruta']   ?? null,
                'instrucciones'  => $data['instrucciones'] ?? null,
                'notas'          => $data['notas']         ?? null,
            ]);

            foreach ($data['ordenes'] as $item) {
                $orden = Orden::lockForUpdate()->findOrFail($item['orden_id']);

                if ($orden->estado !== 'listo_entrega') {
                    abort(422, "La orden {$orden->referencia} no está en estado listo_entrega.");
                }

                $yaAsignada = DespachoItem::where('orden_id', $item['orden_id'])
                    ->whereHas('despacho', fn($q) => $q->whereIn('estado', ['borrador', 'asignado', 'en_ruta']))
                    ->exists();

                if ($yaAsignada) {
                    abort(422, "La orden {$orden->referencia} ya está en un despacho activo.");
                }

                DespachoItem::create([
                    'despacho_id' => $despacho->id,
                    'orden_id'    => $item['orden_id'],
                    'posicion'    => $item['posicion'],
                    'estado'      => 'pendiente',
                ]);

                $orden->update(['estado' => 'en_camino']);
            }

            return $despacho;
        });

        $despacho->load('items.orden.cliente:id,nombre', 'conductor:id,nombre', 'camion:id,nombre,placa');

        event(new DespachoAsignado(
            $despacho->id,
            $conductor->id,
            count($data['ordenes']),
        ));

        $nombreCamion = $camion->nombre ? " — {$camion->nombre}" : '';
        $fechaFmt     = \Carbon\Carbon::parse($data['fecha_despacho'])->locale('es')->isoFormat('D [de] MMMM');

        NotificacionService::crear(
            'despacho_asignado',
            'Nuevas entregas asignadas',
            "Tienes " . count($data['ordenes']) . " entrega(s) para el {$fechaFmt}{$nombreCamion}",
            ['despacho_id' => $despacho->id],
            $conductor->id,
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
            'camion:id,nombre,placa',
            'conductor:id,nombre',
            'items.orden.cliente:id,nombre',
        ])->where('estado', 'completado');

        if ($v = $request->query('camion_id')) {
            $query->where('camion_id', $v);
        }
        if ($v = $request->query('desde')) {
            $query->whereDate('fecha_despacho', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('fecha_despacho', '<=', $v);
        }

        return response()->json($query->orderByDesc('fecha_despacho')->paginate(20));
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
     * PATCH /api/despacho/mis-entregas/rutas/{despachoId}/iniciar
     * Conductor marca una ruta como "en proceso" (asignado → en_ruta).
     * Solo si ya es el día de la fecha asignada.
     */
    public function iniciarRuta(Request $request, int $despachoId)
    {
        $usuario  = $request->user();
        $despacho = Despacho::findOrFail($despachoId);

        if ((int) $despacho->conductor_id !== (int) $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($despacho->estado !== 'asignado') {
            return response()->json(['message' => 'Esta ruta ya está en proceso o fue completada.'], 422);
        }

        // Solo puede iniciar si ya llegó o pasó la fecha de despacho
        if ($despacho->fecha_despacho && $despacho->fecha_despacho->gt(now()->startOfDay())) {
            $fechaFmt = $despacho->fecha_despacho->locale('es')->isoFormat('dddd D [de] MMMM');
            return response()->json([
                'message' => "Esta ruta está programada para el {$fechaFmt}. Aún no puedes iniciarla.",
            ], 422);
        }

        DB::transaction(function () use ($despacho) {
            // Ahora sí pasan a en_camino — el conductor está saliendo
            foreach ($despacho->items()->with('orden')->get() as $item) {
                $item->orden?->update(['estado' => 'en_camino']);
            }
            $despacho->update(['estado' => 'en_ruta']);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/despacho/mis-entregas
     * Conductor autenticado: lista sus entregas activas ordenadas por posicion.
     */
    public function misEntregas(Request $request)
    {
        $usuario = $request->user();

        $items = DespachoItem::with([
            'despacho:id,conductor_id,estado,nombre_ruta,instrucciones,notas,fecha_despacho',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
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
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
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
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
            'orden.pagos:id,orden_id,monto,metodo,referencia,created_at',
        ])->findOrFail($despachoItemId);

        if ($item->despacho->conductor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $item->orden->total_pagado    = $item->orden->totalPagado();
        $item->orden->saldo_pendiente = $item->orden->saldoPendiente();

        // Descuento que se pierde si el cliente paga con tarjeta: el conductor
        // necesita saber cuánto tendría que cobrar en ese caso y por qué, para
        // poder explicárselo al cliente en el momento.
        $orden = $item->orden;
        if ($orden->tieneDescuentoCondicionadoVivo()) {
            $item->descuento_condicionado = [
                'monto'               => (float) $orden->descuento_condicionado,
                'pct'                 => (float) $orden->descuento_condicionado_pct,
                'valor_actual'        => (float) $orden->valor_total,
                'valor_sin_descuento' => $orden->valorSinDescuentoCondicionado(),
                'saldo_actual'        => round($orden->saldoPendiente(), 2),
                'saldo_sin_descuento' => round($orden->valorSinDescuentoCondicionado() - $orden->totalPagado(), 2),
                'metodos_que_lo_conservan' => Orden::METODOS_CON_DESCUENTO,
                'explicacion'         => DescuentoCondicionadoService::explicacion($orden),
            ];
        }

        return response()->json($item);
    }

    /**
     * POST /api/despacho/mis-entregas/{despachoItemId}/pago
     * Multipart: monto, metodo, referencia, foto_producto, foto_pago
     */
    public function registrarPago(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();
        $item    = DespachoItem::with('despacho', 'orden')->findOrFail($despachoItemId);

        if ($item->despacho->conductor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($item->estado === 'entregado') {
            return response()->json(['message' => 'Esta entrega ya fue completada.'], 422);
        }

        $saldoPendiente = $item->orden->saldoPendiente();
        $requierePago   = $saldoPendiente > 0.01;

        // ── Lo que se devuelve ───────────────────────────────────────────────
        // Se lee antes de validar el resto porque cambia las reglas: si el
        // cliente devuelve todo no hay nada que cobrar, y exigirle el pago al
        // conductor lo dejaría trabado en la puerta de la casa.
        $devoluciones = $this->leerDevoluciones($request, $item);
        if ($devoluciones instanceof \Illuminate\Http\JsonResponse) {
            return $devoluciones;
        }

        $devuelveTodo = $devoluciones && $this->devuelveTodaLaOrden($item->orden, $devoluciones);
        if ($devuelveTodo) {
            $requierePago = false;
        }

        $data = $request->validate([
            'monto'         => $requierePago ? 'required|numeric|min:1'                         : 'nullable|numeric|min:0',
            'metodo'        => $requierePago ? 'required|in:efectivo,transferencia,tarjeta,otro' : 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'referencia'    => 'nullable|string|max:100',
            'foto_producto' => 'required|image|max:10240',
            'foto_pago'     => $requierePago ? 'required|image|max:10240' : 'nullable|image|max:10240',
            'foto_anexo'    => 'nullable|image|max:10240',

            // ── Acta de satisfacción ─────────────────────────────────────────
            // La firma es obligatoria salvo que se explique por qué no se pudo
            // conseguir: sin eso, el acta no respalda nada.
            'firma_recibido'       => 'nullable|image|max:10240',
            'firma_omitida_motivo' => 'nullable|string|max:300',
            'recibido_por_nombre'  => 'nullable|string|max:150',
            'recibido_por_cedula'  => 'nullable|string|max:40',
            'conforme'             => 'nullable|boolean',
            'observaciones_entrega'=> 'nullable|string|max:500',
            'foto_novedad'         => 'nullable|image|max:10240',

            // ── Lo que se devuelve ───────────────────────────────────────────
            // JSON con [{orden_item_id, cantidad, motivo}]. Va por producto: en
            // el camión pueden ir la cama y dos mesas y volver solo la cama.
            'devoluciones'         => 'nullable|string',
            'foto_devolucion'      => 'nullable|image|max:10240',
        ]);

        $conforme = $request->has('conforme') ? $request->boolean('conforme') : null;

        if (! $request->hasFile('firma_recibido') && empty($data['firma_omitida_motivo'])) {
            return response()->json([
                'message' => 'Falta la firma de quien recibe. Si no hay quien firme, indica el motivo.',
                'errors'  => ['firma_recibido' => ['Se requiere la firma o el motivo por el que no se pudo obtener.']],
            ], 422);
        }

        if ($request->hasFile('firma_recibido') && empty($data['recibido_por_nombre'])) {
            return response()->json([
                'message' => 'Falta el nombre de quien recibe.',
                'errors'  => ['recibido_por_nombre' => ['Indica quién está recibiendo.']],
            ], 422);
        }

        // Si llegó con novedad hay que decir cuál: "con novedad" a secas no sirve
        // de nada cuando el reclamo llegue después.
        if ($conforme === false && empty($data['observaciones_entrega'])) {
            return response()->json([
                'message' => 'Describe la novedad con la que llegó el producto.',
                'errors'  => ['observaciones_entrega' => ['Explica qué pasó con el producto.']],
            ], 422);
        }

        // ── Descuento condicionado al medio de pago ──────────────────────────
        // Si el cliente paga con tarjeta pierde el descuento por efectivo o
        // transferencia: hay que quitarlo ANTES de validar el monto, porque el
        // saldo a cobrar sube y con el saldo viejo se rechazaría el pago correcto.
        $orden = $item->orden;
        $pierdeDescuento = $requierePago
            && $orden->tieneDescuentoCondicionadoVivo()
            && Orden::metodoPierdeDescuento($data['metodo']);

        if ($pierdeDescuento) {
            $saldoExigido = round($orden->valorSinDescuentoCondicionado() - $orden->totalPagado(), 2);

            // Con tarjeta no se puede cobrar menos: el descuento ya no aplica.
            if ($data['monto'] < $saldoExigido - 0.01) {
                return response()->json([
                    'message' => 'Al pagar con ' . $data['metodo'] . ' el descuento no aplica: debes cobrar '
                        . '$' . number_format($saldoExigido, 0, ',', '.') . '.',
                    'descuento_condicionado' => [
                        'monto'               => (float) $orden->descuento_condicionado,
                        'saldo_sin_descuento' => $saldoExigido,
                        'explicacion'         => DescuentoCondicionadoService::explicacion($orden),
                    ],
                ], 422);
            }

            DescuentoCondicionadoService::quitar($orden, $usuario, $data['metodo']);
            $orden->refresh();
            $item->setRelation('orden', $orden);
            $saldoPendiente = $orden->saldoPendiente();
        }

        if ($requierePago && $data['monto'] > $saldoPendiente + 0.01) {
            return response()->json([
                'message' => "El monto ({$data['monto']}) supera el saldo pendiente (" . round($saldoPendiente, 2) . ").",
            ], 422);
        }

        $fotoProducto = $this->subirCloudinary($request->file('foto_producto'));
        $fotoPago     = $request->hasFile('foto_pago')
            ? $this->subirCloudinary($request->file('foto_pago'))
            : null;
        $fotoAnexo    = $request->hasFile('foto_anexo')
            ? $this->subirCloudinary($request->file('foto_anexo'))
            : null;
        $firmaRecibido = $request->hasFile('firma_recibido')
            ? $this->subirCloudinary($request->file('firma_recibido'))
            : null;
        $fotoNovedad   = $request->hasFile('foto_novedad')
            ? $this->subirCloudinary($request->file('foto_novedad'))
            : null;
        $fotoDevolucion = $request->hasFile('foto_devolucion')
            ? $this->subirCloudinary($request->file('foto_devolucion'))
            : null;

        DB::transaction(function () use ($item, $data, $usuario, $fotoProducto, $fotoPago, $fotoAnexo, $requierePago, $firmaRecibido, $fotoNovedad, $conforme, $devoluciones, $fotoDevolucion) {
            $item->update([
                'foto_producto' => $fotoProducto,
                'foto_pago'     => $fotoPago,

                // Acta de satisfacción
                'firma_recibido_url'    => $firmaRecibido,
                'recibido_por_nombre'   => $data['recibido_por_nombre'] ?? null,
                'recibido_por_cedula'   => $data['recibido_por_cedula'] ?? null,
                'conforme'              => $conforme,
                'observaciones_entrega' => $data['observaciones_entrega'] ?? null,
                'foto_novedad_url'      => $fotoNovedad,
                'firma_omitida_motivo'  => $firmaRecibido ? null : ($data['firma_omitida_motivo'] ?? null),
            ]);

            if ($fotoAnexo) {
                $item->orden->update(['anexo_foto_url' => $fotoAnexo]);
            }

            if ($requierePago) {
                Pago::create([
                    'orden_id'    => $item->orden_id,
                    'vendedor_id' => $usuario->id,
                    'tipo'        => 'saldo_final',
                    'monto'       => $data['monto'],
                    'metodo'      => $data['metodo'],
                    'referencia'  => $data['referencia'] ?? null,
                ]);
            }

            // Lo que se regresó en el camión. Se guarda acá y no al marcar
            // entregado porque es acá donde el conductor sube la foto y
            // escribe el motivo, delante del cliente.
            foreach ($devoluciones as $d) {
                Devolucion::create([
                    'orden_id'         => $item->orden_id,
                    'orden_item_id'    => $d['orden_item_id'],
                    'despacho_item_id' => $item->id,
                    'cantidad'         => $d['cantidad'],
                    'motivo'           => $d['motivo'],
                    'foto_url'         => $fotoDevolucion,
                    'fecha'            => now()->toDateString(),
                    'reportado_por_id' => $usuario->id,
                    'estado'           => 'pendiente',
                ]);
            }
        });

        // Fuera de la transacción: avisar no puede tumbar una entrega ya hecha.
        foreach (Devolucion::where('despacho_item_id', $item->id)->with('orden.cliente', 'item.producto')->get() as $dev) {
            DevolucionController::avisarDevolucion($dev);
        }

        // El otro camino por el que se cobra una orden. Si el conductor cobró
        // con datáfono, la comisión también tiene que bajar 5,5%.
        ComisionController::sincronizarValorOrden($item->orden->fresh());

        $item->load('orden.cliente:id,nombre');
        $item->orden->total_pagado    = $item->orden->totalPagado();
        $item->orden->saldo_pendiente = $item->orden->saldoPendiente();

        // Una novedad en la entrega no puede quedarse solo en el acta: hay que
        // enterarse el mismo día, no cuando el cliente reclame.
        if ($conforme === false) {
            $cliente = $item->orden->cliente?->nombre ?? 'cliente';

            foreach (Usuario::where('activo', true)
                ->where('id', '!=', $usuario->id)
                ->where(fn($q) => $q->where('rol', 'supervisor')->orWhere('facturacion', true))
                ->get() as $d
            ) {
                NotificacionService::crear(
                    tipo:      'entrega_con_novedad',
                    titulo:    'Entrega recibida con novedad',
                    mensaje:   "Orden {$item->orden->referencia} de {$cliente}: "
                             . ($data['observaciones_entrega'] ?? 'el cliente reportó un problema')
                             . " (entregó {$usuario->nombre})",
                    datos:     ['orden_id' => $item->orden_id, 'despacho_item_id' => $item->id],
                    usuarioId: $d->id,
                );
            }
        }

        return response()->json($item);
    }

    /**
     * Lo que el conductor marcó como devuelto, ya comprobado.
     *
     * Llega como JSON dentro del multipart porque el formulario sube fotos.
     * Devuelve la lista limpia, o una respuesta de error si algo no cuadra —
     * un ítem que no es de esta orden, o más unidades de las que se llevaron.
     *
     * @return array<int, array{orden_item_id:int, cantidad:int, motivo:string}>|\Illuminate\Http\JsonResponse
     */
    private function leerDevoluciones(Request $request, DespachoItem $item)
    {
        $crudo = $request->input('devoluciones');

        if (! $crudo) {
            return [];
        }

        $lista = is_array($crudo) ? $crudo : json_decode($crudo, true);

        if (! is_array($lista)) {
            return response()->json(['message' => 'No se entendió lo que se devuelve.'], 422);
        }

        $itemsOrden = $item->orden->items->keyBy('id');
        $limpias    = [];

        foreach ($lista as $d) {
            $itemId   = (int) ($d['orden_item_id'] ?? 0);
            $cantidad = (int) ($d['cantidad'] ?? 0);
            $motivo   = trim((string) ($d['motivo'] ?? ''));

            if ($cantidad < 1) continue;

            $ordenItem = $itemsOrden->get($itemId);
            if (! $ordenItem) {
                return response()->json([
                    'message' => 'Se está devolviendo algo que no es de esta orden.',
                ], 422);
            }
            if ($cantidad > (int) $ordenItem->cantidad) {
                $nombre = $ordenItem->nombre_custom ?: ($ordenItem->producto?->nombre ?? 'ese producto');
                return response()->json([
                    'message' => "No se pueden devolver {$cantidad} de {$nombre}: se llevaron {$ordenItem->cantidad}.",
                ], 422);
            }
            // Sin motivo la devolución no sirve para decidir nada después.
            if (mb_strlen($motivo) < 3) {
                return response()->json([
                    'message' => 'Falta decir por qué se devuelve.',
                    'errors'  => ['devoluciones' => ['Escribe el motivo de la devolución.']],
                ], 422);
            }

            $limpias[] = ['orden_item_id' => $itemId, 'cantidad' => $cantidad, 'motivo' => $motivo];
        }

        return $limpias;
    }

    /** ¿Se regresó absolutamente todo lo que llevaba la orden? */
    private function devuelveTodaLaOrden(Orden $orden, array $devoluciones): bool
    {
        $porItem = collect($devoluciones)->groupBy('orden_item_id')
            ->map(fn ($g) => collect($g)->sum('cantidad'));

        foreach ($orden->items as $item) {
            if ((int) $item->cantidad > (int) ($porItem[$item->id] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GET /api/ordenes/{ordenId}/acta-entrega
     * PDF del acta de satisfacción firmada por quien recibió.
     */
    public function actaEntrega(Request $request, int $ordenId)
    {
        $usuario = $request->user();

        $item = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'orden.cliente:id,nombre,telefono,cedula',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre',
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
        ])
            ->where('orden_id', $ordenId)
            ->whereNotNull('firma_recibido_url')
            ->latest('entregado_at')
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Esta orden no tiene acta de entrega firmada.'], 404);
        }

        $orden = $item->orden;

        // El acta es un papel de la orden: quien puede verla, la imprime.
        if (! $orden->laPuedeVer($usuario) && ! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $firmaBase64 = $this->urlToBase64($item->firma_recibido_url);
        $logoBase64  = $this->avifToPngBase64(public_path('img/logo.avif'));

        $pdf = Pdf::loadView('pdf.acta_entrega', compact('orden', 'item', 'firmaBase64', 'logoBase64'));
        $pdf->setPaper('letter');

        return $pdf->download('acta-' . strtolower(str_replace('#', '', $orden->referencia)) . '.pdf');
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

        // Lo que se regresó en el camión, anotado un momento antes al subir el
        // acta. Esas unidades no se entregaron: ni salen del inventario ni
        // cierran su producción.
        $devueltoPorItem = Devolucion::where('despacho_item_id', $item->id)
            ->get()
            ->groupBy('orden_item_id')
            ->map(fn ($g) => (int) $g->sum('cantidad'));

        $hayDevolucion = $devueltoPorItem->isNotEmpty();

        DB::transaction(function () use ($item, $orden, $devueltoPorItem, $hayDevolucion) {
            $now = now();

            // Si volvió TODO, esta entrega no se hizo: se devolvió.
            $seDevolvioTodo = $hayDevolucion && $this->devuelveTodaLaOrden(
                $item->orden()->with('items')->first(),
                $devueltoPorItem->map(fn ($c, $id) => ['orden_item_id' => $id, 'cantidad' => $c])->values()->all(),
            );

            $item->update([
                'estado'       => $seDevolvioTodo ? 'devuelto' : 'entregado',
                'entregado_at' => $now,
            ]);

            // Con algo devuelto la orden no está entregada: queda esperando que
            // decidan si se arregla o se cancela.
            $orden->update(['estado' => $hayDevolucion ? 'devuelto' : 'entregado']);

            // Lo devuelto queda fuera: su produccion no se cierra porque esa
            // pieza no llego a la casa, y si se decide arreglarla hay que
            // poder reabrirla donde estaba.
            Produccion::whereIn('orden_item_id', function ($q) use ($item) {
                $q->select('id')
                    ->from('orden_items')
                    ->where('orden_id', $item->orden_id)
                    ->where('es_personalizado', true);
            })
                ->whereNotIn('orden_item_id', $devueltoPorItem->keys()->all() ?: [0])
                ->whereIn('estado', ['pendiente', 'en_proceso', 'listo', 'retrasado'])
                ->update([
                    'estado'     => 'entregado',
                    'fecha_real' => $now->toDateString(),
                ]);

            // Decrementar inventario de los ítems de stock (no personalizados)
            foreach ($orden->items as $ordenItem) {
                // Lo que volvio en el camion no salio del inventario: sigue
                // reservado para esta orden hasta que se decida que hacer.
                // Descontarlo aca lo daria por vendido y la pieza esta en la
                // bodega, rota.
                $devueltas = (int) ($devueltoPorItem[$ordenItem->id] ?? 0);
                $entregadas = (int) $ordenItem->cantidad - $devueltas;
                if ($entregadas <= 0) continue;

                $origenId = $ordenItem->tienda_origen_id ?? $orden->tienda_id;
                if ($ordenItem->variante_id) {
                    InventarioVariante::where('variante_id', $ordenItem->variante_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$entregadas}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$entregadas}"),
                        ]);
                    Inventario::where('producto_id', $ordenItem->producto_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$entregadas}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$entregadas}"),
                        ]);
                } else {
                    Inventario::where('producto_id', $ordenItem->producto_id)
                        ->where('tienda_id', $origenId)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$entregadas}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$entregadas}"),
                        ]);
                }
                // Bajó el stock base: el reparto por tela/medida tiene que
                // seguir cabiendo dentro de lo que quedó.
                StockVariantes::cuadrar(
                    (int) $ordenItem->producto_id, (int) $origenId, "Entrega orden #{$orden->id}"
                );
                InventarioMovimiento::create([
                    'producto_id' => $ordenItem->producto_id,
                    'tienda_id'   => $origenId,
                    'tipo'        => 'salida',
                    'cantidad'    => $entregadas,
                    'motivo'      => "Entrega orden #{$orden->id} — conductor",
                    'usuario_id'  => $item->despacho->conductor_id,
                ]);

                // Aquí es donde el producto sale de verdad del inventario. Este
                // camino —el del conductor— nunca avisaba, y es por el que pasa
                // casi toda la mercancía: el aviso de "se vendió el último" no
                // llegaba nunca.
                if (! $ordenItem->es_personalizado && $ordenItem->producto_id) {
                    OrdenController::notificarSiSeAcabo(
                        (int) $ordenItem->producto_id,
                        (int) $origenId,
                        $entregadas,
                    );
                }
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
            $item->orden->referencia,
        ));

        NotificacionService::crear(
            'entregado',
            'Orden entregada por conductor',
            "Orden {$item->orden->referencia} de {$item->orden->cliente->nombre} fue entregada por {$item->despacho->conductor->nombre}",
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
                "Orden {$item->orden->referencia} de {$item->orden->cliente->nombre} fue entregada — pendiente de facturación",
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
            $detalle = $response->json('error.message') ?? $response->body();
            abort(502, "Error Cloudinary: {$detalle}");
        }

        return $response->json('secure_url');
    }
}
