<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use Illuminate\Http\Request;

class PagoController extends Controller
{
    /**
     * GET /api/ordenes/{id}/pagos
     */
    public function index(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $pagos = $orden->pagos()->orderBy('created_at')->get();

        return response()->json([
            'orden_id'       => $orden->id,
            'valor_total'    => $orden->valor_total,
            'total_pagado'   => $orden->totalPagado(),
            'saldo_pendiente'=> $orden->saldoPendiente(),
            'pagos'          => $pagos,
        ]);
    }

    /**
     * POST /api/ordenes/{id}/pagos
     *
     * Registra un abono o saldo final. Si con este pago se cubre
     * el total y todos los items fueron entregados, cierra la orden.
     */
    public function store(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::with('items')->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($orden->estado === 'cancelado') {
            return response()->json(['message' => 'No se pueden registrar pagos en una orden cancelada.'], 422);
        }

        $data = $request->validate([
            'monto'      => 'required|numeric|min:1',
            'metodo'     => 'required|in:efectivo,transferencia,tarjeta,otro',
            'referencia' => 'nullable|string|max:100',
            'notas'      => 'nullable|string|max:500',
        ]);

        $saldoPendiente = $orden->saldoPendiente();

        if ($data['monto'] > $saldoPendiente + 0.01) {
            return response()->json([
                'message' => "El monto ({$data['monto']}) supera el saldo pendiente (" . round($saldoPendiente, 2) . ").",
                'errors'  => ['monto' => ['No puede superar el saldo pendiente.']],
            ], 422);
        }

        // Determinar tipo de pago
        $tipoPago = abs($data['monto'] - $saldoPendiente) < 0.01 ? 'saldo_final' : 'abono';

        $pago = $orden->pagos()->create([
            'vendedor_id' => $usuario->id,
            'tipo'        => $tipoPago,
            'monto'       => $data['monto'],
            'metodo'      => $data['metodo'],
            'referencia'  => $data['referencia'] ?? null,
            'notas'       => $data['notas'] ?? null,
        ]);

        // Si saldo queda en cero y la orden está lista para entregar → entregado
        $nuevoSaldo = $orden->saldoPendiente();
        if ($nuevoSaldo <= 0 && $orden->estado === 'listo_entrega') {
            $orden->update(['estado' => 'entregado']);
        }

        return response()->json([
            'pago'           => $pago,
            'total_pagado'   => $orden->totalPagado(),
            'saldo_pendiente'=> $orden->saldoPendiente(),
            'estado_orden'   => $orden->fresh()->estado,
        ], 201);
    }
}
