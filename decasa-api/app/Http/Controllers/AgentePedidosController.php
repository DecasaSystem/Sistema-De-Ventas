<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * "¿Cómo va mi pedido?" desde el agente de WhatsApp.
 *
 * El agente no sabía nada de las órdenes reales: solo de su carrito y sus citas.
 * Ahora puede preguntar aquí por el número desde el que escribe el cliente
 * (Twilio lo entrega verificado: es el teléfono de quien escribe, no uno que
 * él diga). Instagram no tiene teléfono y no usa esto.
 *
 * Decisión del dueño (2026-10-08): que lo pueda consultar "sin dar información
 * de más". Por eso sale SOLO lo que el cliente puede saber de lo suyo:
 * referencia, estado en palabras, fechas y qué productos. Nada de montos,
 * saldo, pagos, vendedor, tienda, dirección ni notas internas.
 */
class AgentePedidosController extends Controller
{
    /** Cómo se le dice al cliente cada estado. Lo que no esté aquí: "En proceso". */
    private const ESTADOS = [
        'pendiente_anticipo' => 'Pendiente del anticipo',
        'en_produccion'      => 'En fabricación',
        'listo_entrega'      => 'Lista para entregar',
        'en_camino'          => 'En camino',
        'entregado'          => 'Entregada',
        'cancelado'          => 'Cancelada',
    ];

    /** Las ya cerradas (entregadas o canceladas) solo se muestran si son recientes. */
    private const DIAS_CERRADAS = 60;

    /** GET /api/agentes/pedidos?telefono=+573001112233 — con token del agente */
    public function index(Request $request)
    {
        $digitos = preg_replace('/\D/', '', (string) $request->query('telefono', ''));
        // Los clientes se guardan sin +57, con espacios, guiones o con el
        // prefijo: se compara por los últimos 10 dígitos (un celular colombiano).
        $ultimos = substr($digitos, -10);
        if (strlen($ultimos) < 10) {
            return response()->json(['message' => 'Teléfono inválido.'], 422);
        }

        // Primero un filtro grueso en SQL por los últimos 4 dígitos (vienen
        // juntos aunque el número tenga separadores) y el fino en PHP sobre los
        // dígitos: en la base hay teléfonos escritos de muchas formas.
        $cola     = substr($ultimos, -4);
        $esSuyo   = fn (?string $tel) => $tel !== null && $tel !== ''
            && str_ends_with(preg_replace('/\D/', '', $tel), $ultimos);

        $clienteIds = Cliente::where('telefono', 'like', '%' . $cola . '%')
            ->limit(50)
            ->get(['id', 'telefono'])
            ->filter(fn ($c) => $esSuyo($c->telefono))
            ->pluck('id')
            ->all();

        $ordenes = Orden::query()
            ->with(['items:id,orden_id,producto_id,nombre_custom,cantidad,cantidad_entregada,fecha_entrega_prom,devuelto_en',
                    'items.producto:id,nombre'])
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->whereNull('numero_anulado')
            ->where('created_at', '>=', now()->subYear())
            ->where(function ($q) use ($clienteIds, $cola) {
                $q->whereIn('cliente_id', $clienteIds ?: [0])
                  ->orWhere('contacto_telefono', 'like', '%' . $cola . '%');
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->filter(fn (Orden $o) => in_array($o->cliente_id, $clienteIds, true) || $esSuyo($o->contacto_telefono))
            ->filter(fn (Orden $o) => ! in_array($o->estado, ['entregado', 'cancelado'], true)
                || $o->updated_at?->gte(now()->subDays(self::DIAS_CERRADAS)))
            ->take(5)
            ->values();

        return response()->json([
            'pedidos' => $ordenes->map(function (Orden $o) {
                $entrega  = $o->resumenEntrega();
                $estimada = $o->fechaEntregaEstimada();

                return [
                    'referencia'       => $o->referencia,
                    'estado'           => self::ESTADOS[$o->estado] ?? 'En proceso',
                    'fecha_compra'     => $o->created_at?->copy()->setTimezone('America/Bogota')->toDateString(),
                    'entrega_estimada' => in_array($o->estado, ['entregado', 'cancelado'], true) ? null : $estimada?->toDateString(),
                    'entregados'       => $entrega['entregados'],
                    'unidades'         => $entrega['total'],
                    'productos'        => $o->items->filter->estaVivo()->map(fn ($i) => trim(
                        ($i->nombre_custom ?: $i->producto?->nombre ?: 'Producto') . ((int) $i->cantidad > 1 ? ' ×' . (int) $i->cantidad : '')
                    ))->values(),
                ];
            }),
        ]);
    }
}
