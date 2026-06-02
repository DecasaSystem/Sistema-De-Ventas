<?php

namespace App\Http\Controllers;

use App\Models\ConsultaCosto;
use App\Models\ConsultaCostoDesglose;
use App\Models\ConsultaCostoItem;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsultaCostoController extends Controller
{
    /**
     * GET /api/consultas-costo/receptores
     * Lista de supervisores y ebanistas activos que pueden recibir consultas.
     */
    public function receptores(Request $request)
    {
        $usuarios = Usuario::whereIn('rol', ['supervisor', 'ebanista'])
            ->where('activo', true)
            ->orderBy('rol')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'rol']);

        return response()->json($usuarios);
    }

    /**
     * GET /api/consultas-costo
     * Vendedor: sus consultas. Supervisor/ebanista: las asignadas a él.
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = ConsultaCosto::with([
            'orden.cliente:id,nombre,telefono',
            'orden.tienda:id,nombre',
            'asignadoA:id,nombre,rol',
            'solicitadoPor:id,nombre',
            'items.ordenItem',
            'items.desglose',
        ]);

        if ($usuario->rol === 'vendedor') {
            $query->where('solicitado_por_id', $usuario->id);
        } elseif (in_array($usuario->rol, ['supervisor', 'ebanista'])) {
            $query->where(function ($q) use ($usuario) {
                $q->where('asignado_a_id', $usuario->id)
                  ->orWhere('solicitado_por_id', $usuario->id);
            });
        } else {
            return response()->json([]);
        }

        $consultas = $query->orderByRaw("FIELD(estado, 'pendiente', 'respondida')")
            ->orderByDesc('created_at')
            ->get();

        return response()->json($consultas);
    }

    /**
     * POST /api/consultas-costo
     * Crea una consulta de costo para los ítems personalizados de una orden.
     */
    public function store(Request $request)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'orden_id'          => 'required|integer|exists:ordenes,id',
            'asignado_a_id'     => 'required|integer|exists:usuarios,id',
            'notas_adicionales' => 'nullable|string|max:1000',
        ]);

        // Verificar que el receptor es supervisor o ebanista
        $receptor = Usuario::findOrFail($data['asignado_a_id']);
        if (! in_array($receptor->rol, ['supervisor', 'ebanista'])) {
            return response()->json(['message' => 'El receptor debe ser supervisor o ebanista.'], 422);
        }

        // Verificar que la orden tiene ítems personalizados
        $itemsPersonalizados = OrdenItem::where('orden_id', $data['orden_id'])
            ->where('es_personalizado', true)
            ->get();

        if ($itemsPersonalizados->isEmpty()) {
            return response()->json(['message' => 'La orden no tiene ítems personalizados.'], 422);
        }

        // Verificar que no haya ya una consulta pendiente para esta orden
        $existente = ConsultaCosto::where('orden_id', $data['orden_id'])
            ->where('estado', 'pendiente')
            ->first();

        if ($existente) {
            return response()->json(['message' => 'Ya hay una consulta pendiente para esta orden.'], 422);
        }

        $consulta = DB::transaction(function () use ($data, $usuario, $itemsPersonalizados) {
            $consulta = ConsultaCosto::create([
                'orden_id'          => $data['orden_id'],
                'asignado_a_id'     => $data['asignado_a_id'],
                'solicitado_por_id' => $usuario->id,
                'estado'            => 'pendiente',
                'notas_adicionales' => $data['notas_adicionales'] ?? null,
            ]);

            foreach ($itemsPersonalizados as $item) {
                ConsultaCostoItem::create([
                    'consulta_id'  => $consulta->id,
                    'orden_item_id' => $item->id,
                    'estado'        => 'pendiente',
                ]);
            }

            return $consulta;
        });

        // Notificar al receptor
        $consulta->load('orden.cliente:id,nombre');
        $clienteNombre = $consulta->orden->cliente->nombre ?? 'Cliente';

        NotificacionService::crear(
            'consulta_costo_nueva',
            'Nueva consulta de costo',
            "Orden #{$data['orden_id']} — {$clienteNombre}: {$itemsPersonalizados->count()} ítem(s) por cotizar",
            ['consulta_id' => $consulta->id, 'orden_id' => $data['orden_id']],
            $data['asignado_a_id'],
        );

        return response()->json(['message' => 'Consulta enviada.', 'consulta_id' => $consulta->id], 201);
    }

    /**
     * GET /api/consultas-costo/{id}
     * Detalle completo con info de la orden.
     */
    public function show(Request $request, int $id)
    {
        $usuario  = $request->user();
        $consulta = ConsultaCosto::with([
            'orden.cliente:id,nombre,telefono',
            'orden.vendedor:id,nombre',
            'orden.tienda:id,nombre',
            'asignadoA:id,nombre,rol',
            'solicitadoPor:id,nombre',
            'items.ordenItem.producto:id,nombre,categoria,foto_url',
            'items.desglose',
        ])->findOrFail($id);

        // Solo puede verla el receptor o el solicitante
        if ($consulta->asignado_a_id !== $usuario->id && $consulta->solicitado_por_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json($consulta);
    }

    /**
     * PUT /api/consultas-costo/{id}/items/{itemId}
     * Receptor guarda el desglose y margen de un ítem.
     */
    public function guardarItem(Request $request, int $id, int $itemId)
    {
        $usuario      = $request->user();
        $consulta     = ConsultaCosto::findOrFail($id);
        $consultaItem = ConsultaCostoItem::where('consulta_id', $id)->findOrFail($itemId);

        if ($consulta->asignado_a_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($consulta->estado === 'respondida') {
            return response()->json(['message' => 'Esta consulta ya fue respondida.'], 422);
        }

        $data = $request->validate([
            'margen_ganancia_pct' => 'required|integer|min:0|max:500',
            'desglose'            => 'required|array|min:1',
            'desglose.*.tipo'     => 'required|in:material,carpintero,tapicero,laquero',
            'desglose.*.nombre'   => 'required|string|max:200',
            'desglose.*.cantidad' => 'required|numeric|min:0.001',
            'desglose.*.precio_unitario' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $consultaItem) {
            // Eliminar desglose anterior
            $consultaItem->desglose()->delete();

            $precioBase = 0;
            foreach ($data['desglose'] as $fila) {
                $subtotal = round($fila['cantidad'] * $fila['precio_unitario'], 2);
                $precioBase += $subtotal;
                ConsultaCostoDesglose::create([
                    'consulta_item_id' => $consultaItem->id,
                    'tipo'             => $fila['tipo'],
                    'nombre'           => $fila['nombre'],
                    'cantidad'         => $fila['cantidad'],
                    'precio_unitario'  => $fila['precio_unitario'],
                    'subtotal'         => $subtotal,
                ]);
            }

            $margen      = $data['margen_ganancia_pct'];
            $precioFinal = round($precioBase * (1 + $margen / 100), 2);

            $consultaItem->update([
                'precio_base'         => $precioBase,
                'margen_ganancia_pct' => $margen,
                'precio_final'        => $precioFinal,
                'estado'              => 'calculado',
            ]);
        });

        $consultaItem->load('desglose');

        return response()->json($consultaItem);
    }

    /**
     * POST /api/consultas-costo/{id}/enviar
     * Receptor envía todos los precios al vendedor.
     * Actualiza precio_unitario de cada orden_item con el precio calculado.
     */
    public function enviar(Request $request, int $id)
    {
        $usuario  = $request->user();
        $consulta = ConsultaCosto::with(['items.ordenItem', 'orden.cliente:id,nombre', 'orden'])->findOrFail($id);

        if ($consulta->asignado_a_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($consulta->estado === 'respondida') {
            return response()->json(['message' => 'Esta consulta ya fue respondida.'], 422);
        }

        // Verificar que todos los ítems estén calculados
        $pendientes = $consulta->items->where('estado', 'pendiente')->count();
        if ($pendientes > 0) {
            return response()->json([
                'message' => "Faltan {$pendientes} ítem(s) por calcular antes de enviar.",
            ], 422);
        }

        DB::transaction(function () use ($consulta) {
            // Actualizar precio_unitario de cada orden_item
            foreach ($consulta->items as $item) {
                $item->ordenItem->update(['precio_unitario' => $item->precio_final]);
            }

            // Recalcular valor_total de la orden
            $orden = $consulta->orden;
            $orden->loadMissing('items');
            $nuevoTotal = $orden->items->sum(fn($i) => $i->cantidad * $i->precio_unitario);
            $orden->update(['valor_total' => $nuevoTotal]);

            $consulta->update([
                'estado'        => 'respondida',
                'respondido_at' => now(),
            ]);
        });

        // Notificar al vendedor
        $clienteNombre = $consulta->orden->cliente->nombre ?? '';
        $totalItems    = $consulta->items->count();

        NotificacionService::crear(
            'consulta_costo_respondida',
            'Precio de cotización listo',
            "Orden #{$consulta->orden_id} — {$clienteNombre}: precio calculado para {$totalItems} ítem(s)",
            ['consulta_id' => $consulta->id, 'orden_id' => $consulta->orden_id],
            $consulta->solicitado_por_id,
        );

        return response()->json(['message' => 'Precios enviados al vendedor.']);
    }
}
