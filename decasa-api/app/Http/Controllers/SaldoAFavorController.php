<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenMensaje;
use App\Models\Pago;
use App\Services\AvisoFacturacion;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La plata que el cliente tiene pagada de más (usuario, 2026-11-17).
 *
 * Varios caminos dejan plata a favor: cancelar una orden con anticipo,
 * quitar un producto (Devoluciones, Producción), cambiar por uno más barato,
 * cancelar lo que faltaba. Hasta ahora no había dónde registrar que se le
 * devolvió: si se le pagaba por fuera, la caja no cuadraba y la orden seguía
 * diciendo "a favor" para siempre.
 *
 * Decisión del usuario: lo hace un supervisor, por máximo el saldo a favor, y
 * queda como pago negativo `tipo = reembolso` con su medio, igual que el
 * reembolso de una garantía (en efectivo baja la caja de la tienda; por
 * transferencia no). Y una lista de las órdenes con saldo a favor.
 */
class SaldoAFavorController extends Controller
{
    /** Ven la lista quien maneja la plata: supervisores y facturación. */
    private function puedeVer($u): bool
    {
        return $u && ($u->rol === 'supervisor' || (bool) ($u->facturacion ?? false));
    }

    /**
     * GET /api/saldos-a-favor?tienda_id=
     * Las órdenes con plata del cliente por devolver, la más vieja primero.
     */
    public function index(Request $request)
    {
        if (! $this->puedeVer($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $sql = Orden::sqlSaldoAFavor('ordenes');

        $ordenes = Orden::query()
            ->with(['cliente:id,nombre', 'tienda:id,nombre', 'vendedor:id,nombre'])
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->whereRaw("{$sql} > 0.5")
            ->when($request->integer('tienda_id'), fn ($q, $t) => $q->where('tienda_id', $t))
            ->select('ordenes.*')
            ->selectRaw("{$sql} AS a_favor")
            ->orderBy('updated_at')
            ->limit(300)
            ->get();

        return response()->json($ordenes->map(fn (Orden $o) => [
            'id'         => $o->id,
            'referencia' => $o->referencia,
            'estado'     => $o->estado,
            'cliente'    => $o->cliente?->nombre,
            'tienda'     => $o->tienda?->nombre,
            'vendedor'   => $o->vendedor?->nombre,
            'a_favor'    => round((float) $o->a_favor),
            'desde'      => $o->updated_at?->toIso8601String(),
        ])->values());
    }

    /** POST /api/ordenes/{id}/devolver-plata */
    public function devolver(Request $request, int $id)
    {
        $usuario = $request->user();
        if ($usuario->rol !== 'supervisor') {
            return response()->json(['message' => 'Devolverle la plata al cliente lo registra un supervisor.'], 403);
        }

        $data = $request->validate([
            'monto'      => 'required|numeric|min:1',
            'metodo'     => 'required|in:efectivo,transferencia,otro',
            'referencia' => 'nullable|string|max:100',
            'motivo'     => 'required|string|min:3|max:500',
        ], [
            'metodo.required' => 'Escoge cómo se le devolvió la plata.',
            'motivo.required' => 'Escribe por qué se le devuelve.',
        ]);

        $orden = DB::transaction(function () use ($id, $data, $usuario) {
            $orden = Orden::lockForUpdate()->findOrFail($id);
            $favor = $orden->saldoAFavor();

            if ($favor <= 0.5) {
                abort(response()->json(['message' => 'Esta orden no tiene plata a favor del cliente.'], 422));
            }
            if ((float) $data['monto'] > $favor + 0.01) {
                abort(response()->json([
                    'message' => 'Solo se le puede devolver lo que tiene a favor: $' . number_format($favor, 0, ',', '.') . '.',
                ], 422));
            }

            Pago::create([
                'orden_id'    => $orden->id,
                'vendedor_id' => $orden->vendedor_id,
                'tienda_id'   => $orden->tienda_id,
                'tipo'        => 'reembolso',
                'monto'       => -round((float) $data['monto'], 2),
                'metodo'      => $data['metodo'],
                'referencia'  => $data['referencia'] ?? null,
                'notas'       => "Devolución del saldo a favor: {$data['motivo']} — lo registró {$usuario->nombre}",
            ]);

            OrdenMensaje::create([
                'orden_id'   => $orden->id,
                'usuario_id' => $usuario->id,
                'mensaje'    => '💸 Se le devolvieron $' . number_format((float) $data['monto'], 0, ',', '.')
                              . " al cliente ({$data['metodo']}) de lo que tenía a favor. Motivo: {$data['motivo']}",
            ]);

            return $orden;
        });

        $orden = $orden->fresh(['cliente:id,nombre']);
        AvisoFacturacion::reembolso($orden, (float) $data['monto'], $usuario, 'Saldo a favor');
        if ($orden->vendedor_id && (int) $orden->vendedor_id !== (int) $usuario->id) {
            NotificacionService::crear(
                'orden_editada',
                'Se le devolvió plata a un cliente',
                "Orden {$orden->referencia} — " . ($orden->cliente?->nombre ?? 'cliente') . ': '
                    . "{$usuario->nombre} le devolvió $" . number_format((float) $data['monto'], 0, ',', '.') . ' de lo que tenía a favor.',
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        return response()->json([
            'message'       => 'Listo: quedó el reembolso en los pagos de la orden'
                . ($data['metodo'] === 'efectivo' ? ' y salió de la caja de la tienda.' : '.'),
            'saldo_a_favor' => $orden->saldoAFavor(),
            'total_pagado'  => $orden->totalPagado(),
        ]);
    }
}
