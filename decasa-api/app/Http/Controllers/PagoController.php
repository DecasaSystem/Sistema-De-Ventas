<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\Pago;
use App\Models\Usuario;
use App\Services\DescuentoCondicionadoService;
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

        if (! $orden->laPuedeVer($usuario)) {
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

        // Quien comparte la venta tambien la cobra: si el cliente llega a la
        // tienda con la que se compartió, ahí tienen que poder recibirle.
        if (! $orden->laPuedeCobrar($usuario)) {
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
            // Tienda donde se recibe el dinero: puede no ser la de la orden y es
            // la que determina a qué caja entra el efectivo.
            'tienda_id'       => 'nullable|exists:tiendas,id',
            // El vendedor confirma que ya le avisó al cliente que sube el total
            'aceptar_perdida_descuento' => 'nullable|boolean',
        ]);

        // ── Descuento condicionado al medio de pago ──────────────────────────
        // Si el cliente saca la tarjeta, el descuento por pagar en efectivo o
        // transferencia se pierde completo y el total sube. Hay que avisar ANTES
        // de cobrar, y revertir ANTES de validar el monto contra el saldo: si no,
        // se rechazaría un pago que con el total nuevo sí es válido.
        $pierdeDescuento = $orden->tieneDescuentoCondicionadoVivo()
            && Orden::metodoPierdeDescuento($data['metodo']);

        if ($pierdeDescuento && ! $request->boolean('aceptar_perdida_descuento')) {
            $valorNuevo = $orden->valorSinDescuentoCondicionado();

            return response()->json([
                'message' => 'Esta orden tiene un descuento por pago en efectivo o transferencia. Al pagar con '
                    . $data['metodo'] . ' el descuento se pierde y el total sube.',
                'descuento_en_riesgo' => [
                    'descuento'        => (float) $orden->descuento_condicionado,
                    'pct'              => (float) $orden->descuento_condicionado_pct,
                    'valor_actual'     => (float) $orden->valor_total,
                    'valor_sin_descuento' => $valorNuevo,
                    'saldo_actual'     => round($orden->saldoPendiente(), 2),
                    'saldo_sin_descuento' => round($valorNuevo - $orden->totalPagado(), 2),
                ],
            ], 409);
        }

        if ($pierdeDescuento) {
            DescuentoCondicionadoService::quitar($orden, $usuario, $data['metodo']);
            $orden->refresh();
        }

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
            'tienda_id'      => $data['tienda_id'] ?? $orden->tienda_id,
            'tipo'           => $tipoPago,
            'monto'          => $data['monto'],
            'metodo'         => $data['metodo'],
            'referencia'     => $data['referencia'] ?? null,
            'notas'          => $data['notas'] ?? null,
            'comprobante_url' => $data['comprobante_url'],
        ]);

        // La comisión se calcula sobre lo que de verdad le entra a la empresa,
        // y eso solo se sabe al cobrar: si este pago fue con datáfono, la base
        // baja 5,5%. Sin esto la comisión se quedaría con la foto del día de
        // la venta, cuando todavía no se sabía cómo iba a pagar el cliente.
        ComisionController::sincronizarValorOrden($orden->fresh());

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
                    titulo:    "Pago registrado — Orden {$orden->referencia}",
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
            'descuento_revertido' => $pierdeDescuento,
        ], 201);
    }

    /**
     * POST /api/ordenes/{id}/verificar-pago
     * Consulta previa: dice si cobrar con ese método hace perder el descuento
     * y con qué cifras queda la orden. No modifica nada.
     */
    public function verificarPago(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);

        if (! $orden->laPuedeCobrar($usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'metodo' => 'required|in:efectivo,transferencia,tarjeta,otro',
        ]);

        $pierde = $orden->tieneDescuentoCondicionadoVivo()
            && Orden::metodoPierdeDescuento($data['metodo']);

        $valorNuevo = $orden->valorSinDescuentoCondicionado();

        return response()->json([
            'pierde_descuento'    => $pierde,
            'descuento'           => (float) $orden->descuento_condicionado,
            'pct'                 => (float) $orden->descuento_condicionado_pct,
            'valor_actual'        => (float) $orden->valor_total,
            'valor_sin_descuento' => $valorNuevo,
            'saldo_actual'        => round($orden->saldoPendiente(), 2),
            'saldo_sin_descuento' => round($valorNuevo - $orden->totalPagado(), 2),
        ]);
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

        if (! $orden->laPuedeCobrar($usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // Corregir el MEDIO de pago se permite siempre: si un pago quedó marcado
        // como efectivo cuando en realidad fue transferencia, hay que poder
        // arreglarlo aunque la orden ya se haya entregado. Bloquearlo obligaba a
        // sacar la plata de la caja con un egreso inventado, y ahí es donde se
        // descuadra por unos pesos.
        //
        // El MONTO sí sigue restringido: cambiarlo en una orden entregada le
        // dejaría un saldo pendiente a algo que ya se cerró.
        $puedeCambiarMonto = in_array($orden->estado, ['borrador', 'pendiente_anticipo', 'en_produccion'], true);

        $data = $request->validate([
            'monto'      => 'required|numeric|min:0.01',
            'metodo'     => 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'referencia' => 'nullable|string|max:100',
        ]);

        $montoCambia = (float) $data['monto'] !== (float) $pago->monto;

        if ($montoCambia && ! $puedeCambiarMonto) {
            return response()->json([
                'message' => 'En una orden "' . $orden->estado . '" solo se puede corregir el medio de pago, no el monto.',
                'errors'  => ['monto' => ['El monto no se puede cambiar en este estado.']],
            ], 422);
        }

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

            // Corregir el medio de pago mueve la comisión: si el pago pasa a
            // tarjeta hay que descontarle el 5,5% del datáfono, y si sale de
            // tarjeta hay que devolvérselo. Sin esto, arreglar un método mal
            // marcado dejaba la comisión calculada sobre la cifra equivocada.
            ComisionController::sincronizarValorOrden($orden->fresh());

            // Facturación tiene que enterarse ya: corregir un anticipo o el medio
            // de pago le cambia lo que tiene que cuadrar y lo que va a la caja.
            \App\Services\AvisoFacturacion::cambioDeDinero(
                $orden->fresh(),
                $usuario,
                $cambios,
                'corrección de un pago ya registrado',
            );
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
