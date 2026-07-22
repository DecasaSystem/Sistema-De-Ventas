<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\Pago;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoController extends Controller
{
    /**
     * GET /api/ordenes/{id}/pagos
     */
    public function index(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);

        if (in_array($usuario->rol, ['vendedor', 'ebanista']) && $orden->vendedor_id !== $usuario->id) {
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

        if (in_array($usuario->rol, ['vendedor', 'ebanista']) && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // 'entregado' se acepta para poder cobrar el saldo residual de una venta
        // directa (el cliente se llevó el producto pagando solo una parte). El guard
        // de sobrepago más abajo impide registrar pagos si ya no hay saldo.
        $estadosQueAceptanPago = ['pendiente_anticipo', 'en_produccion', 'listo_entrega', 'en_camino', 'entregado'];
        if (! in_array($orden->estado, $estadosQueAceptanPago)) {
            return response()->json(['message' => 'No se pueden registrar pagos en una orden con estado "' . $orden->estado . '".'], 422);
        }
        if ($orden->estado === 'entregado' && $orden->saldoPendiente() <= 0.01) {
            return response()->json(['message' => 'Esta orden ya está pagada por completo.'], 422);
        }

        $data = $request->validate([
            'monto'           => 'required|numeric|min:1',
            'metodo'          => 'required|in:efectivo,transferencia,tarjeta,otro',
            'referencia'      => 'nullable|string|max:100',
            'notas'           => 'nullable|string|max:500',
            'comprobante_url' => 'required|string|max:500',
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
            'vendedor_id'    => $usuario->id,
            'tipo'           => $tipoPago,
            'monto'          => $data['monto'],
            'metodo'         => $data['metodo'],
            'referencia'     => $data['referencia'] ?? null,
            'notas'          => $data['notas'] ?? null,
            'comprobante_url' => $data['comprobante_url'],
        ]);

        // Si saldo queda en cero y la orden está lista para entregar → entregado
        $nuevoSaldo = $orden->saldoPendiente();
        if ($nuevoSaldo <= 0 && $orden->estado === 'listo_entrega') {
            $orden->update(['estado' => 'entregado']);
        }

        // Notificar a todos los facturadores activos (cubren todas las tiendas)
        $facturadores = Usuario::where('facturacion', true)
            ->where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->get();

        if ($facturadores->isNotEmpty()) {
            $orden->loadMissing('cliente');
            $montoFormateado  = '$ ' . number_format($pago->monto, 0, ',', '.');
            $clienteNombre    = $orden->cliente?->nombre ?? 'cliente';
            $tipoPagoLabel    = $tipoPago === 'saldo_final' ? 'saldo final' : 'abono';

            foreach ($facturadores as $facturador) {
                NotificacionService::crear(
                    tipo:      'abono_registrado',
                    titulo:    "Pago registrado – Orden #{$orden->numero_orden}",
                    mensaje:   "{$usuario->nombre} registró un {$tipoPagoLabel} de {$montoFormateado} en la orden de {$clienteNombre}.",
                    datos:     ['orden_id' => $orden->id],
                    usuarioId: $facturador->id,
                );
            }
        }

        return response()->json([
            'pago'           => $pago,
            'total_pagado'   => $orden->totalPagado(),
            'saldo_pendiente'=> $orden->saldoPendiente(),
            'estado_orden'   => $orden->fresh()->estado,
        ], 201);
    }

    /**
     * PATCH /api/pagos/{id}
     * Corrige un pago ya registrado (monto/método/referencia), p. ej. cuando
     * el anticipo se digitó mal. Queda auditado en orden_ediciones.
     */
    public function update(Request $request, int $id)
    {
        $usuario = $request->user();
        $pago    = Pago::with('orden')->findOrFail($id);
        $orden   = $pago->orden;

        if (in_array($usuario->rol, ['vendedor', 'ebanista']) && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if (! in_array($orden->estado, ['borrador', 'pendiente_anticipo', 'en_produccion'])) {
            return response()->json([
                'message' => 'No se puede editar un pago de una orden en estado "' . $orden->estado . '".',
            ], 422);
        }

        $data = $request->validate([
            'monto'      => 'required|numeric|min:0.01',
            'metodo'     => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'referencia' => 'nullable|string|max:100',
        ]);

        $otrosPagos = $orden->pagos()->where('id', '!=', $pago->id)->sum('monto');
        if ($otrosPagos + $data['monto'] > (float) $orden->valor_total + 0.01) {
            return response()->json([
                'message' => "El monto ({$data['monto']}) sumado a los demás pagos supera el total de la orden (" . round((float) $orden->valor_total, 2) . ").",
                'errors'  => ['monto' => ['No puede superar el valor total de la orden.']],
            ], 422);
        }

        $tipoLabel = $pago->tipo === 'anticipo' ? 'Anticipo' : ucfirst($pago->tipo);
        $cambios   = [];

        if ((float) $data['monto'] !== (float) $pago->monto) {
            $cambios[] = ['campo' => "pago_{$pago->id}_monto", 'label' => "{$tipoLabel} — monto", 'antes' => (float) $pago->monto, 'despues' => (float) $data['monto']];
        }
        if (array_key_exists('metodo', $data) && $data['metodo'] !== $pago->metodo) {
            $cambios[] = ['campo' => "pago_{$pago->id}_metodo", 'label' => "{$tipoLabel} — método", 'antes' => $pago->metodo, 'despues' => $data['metodo']];
        }
        if (array_key_exists('referencia', $data) && $data['referencia'] !== $pago->referencia) {
            $cambios[] = ['campo' => "pago_{$pago->id}_referencia", 'label' => "{$tipoLabel} — referencia", 'antes' => $pago->referencia, 'despues' => $data['referencia']];
        }

        if (! empty($cambios)) {
            DB::transaction(function () use ($pago, $data, $orden, $usuario, $cambios) {
                $pago->update([
                    'monto'      => $data['monto'],
                    'metodo'     => $data['metodo'] ?? $pago->metodo,
                    'referencia' => array_key_exists('referencia', $data) ? $data['referencia'] : $pago->referencia,
                ]);

                \App\Models\OrdenEdicion::create([
                    'orden_id'   => $orden->id,
                    'usuario_id' => $usuario->id,
                    'cambios'    => $cambios,
                ]);
            });
        }

        return response()->json([
            'pago'            => $pago->fresh(),
            'total_pagado'    => $orden->totalPagado(),
            'saldo_pendiente' => $orden->saldoPendiente(),
        ]);
    }

    /**
     * POST /api/pagos/{id}/tomar-facturacion
     * Reclama atómicamente la facturación de un pago (el primero en clickear gana).
     */
    public function tomarFacturacion(Request $request, int $id)
    {
        $usuario = $request->user();

        if (! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // Actualización atómica: solo si nadie lo tomó todavía
        $updated = DB::table('pagos')
            ->where('id', $id)
            ->whereNull('facturacion_tomada_por')
            ->update(['facturacion_tomada_por' => $usuario->id]);

        $pago = Pago::with('facturacionTomadaPor:id,nombre')->findOrFail($id);

        return response()->json([
            'tomado' => (bool) $updated,
            'pago'   => $pago,
        ]);
    }

    /**
     * POST /api/pagos/{id}/marcar-facturada
     * Marca el pago como facturado (solo quien lo tomó puede hacerlo).
     */
    public function marcarFacturada(Request $request, int $id)
    {
        $usuario = $request->user();

        if (! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $pago = Pago::findOrFail($id);

        if ((int) $pago->facturacion_tomada_por !== (int) $usuario->id) {
            return response()->json(['message' => 'Solo quien tomó la facturación puede marcarla como hecha.'], 403);
        }

        $pago->facturacion_hecha_at = now();
        $pago->save();

        return response()->json([
            'pago' => $pago->fresh()->load('facturacionTomadaPor:id,nombre'),
        ]);
    }
}
