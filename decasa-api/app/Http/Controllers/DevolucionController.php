<?php

namespace App\Http\Controllers;

use App\Models\CajaMovimiento;
use App\Models\Devolucion;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\OrdenMensaje;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se devuelve cuando el mueble llega golpeado a la casa.
 *
 * El conductor lo registra en el momento de la entrega —ahí es donde se ve el
 * golpe y donde está el cliente— y queda esperando decisión. Después alguien
 * de producción decide: casi siempre vuelve al taller a que la arreglen, y de
 * vez en cuando se cancela y se le devuelve la plata.
 *
 * Mientras no se decida, la orden se queda en estado `devuelto`. No es
 * "entregado" —el camión se regresó con la mercancía— ni "cancelado", porque
 * la venta sigue viva y casi siempre termina bien.
 */
class DevolucionController extends Controller
{
    /** Decidir qué se hace con lo devuelto es de quien maneja el taller. */
    private function puedeDecidir(?Usuario $u): bool
    {
        return $u && $u->gestiona_produccion;
    }

    private function comoJson(Devolucion $d): array
    {
        $item = $d->item;

        return [
            'id'             => $d->id,
            'orden_id'       => $d->orden_id,
            'orden_referencia' => $d->orden?->referencia,
            'cliente'        => $d->orden?->cliente?->nombre,
            'orden_item_id'  => $d->orden_item_id,
            'producto'       => $item?->nombre_custom ?: ($item?->producto?->nombre ?? 'Ítem eliminado'),
            'es_personalizado' => (bool) ($item?->es_personalizado),
            'cantidad'       => (int) $d->cantidad,
            'motivo'         => $d->motivo,
            'foto_url'       => $d->foto_url,
            'fecha'          => $d->fecha->toDateString(),
            'reportado_por'  => $d->reportadoPor?->nombre,
            'estado'         => $d->estado,
            'decidido_por'   => $d->decididoPor?->nombre,
            'decidido_at'    => $d->decidido_at?->toIso8601String(),
            'notas_decision' => $d->notas_decision,
            'monto_devuelto' => $d->monto_devuelto !== null ? (float) $d->monto_devuelto : null,
            'monto_sugerido' => $d->montoSugerido(),
            'registrado_en'  => $d->created_at->toIso8601String(),
        ];
    }

    /**
     * GET /api/devoluciones?estado=pendiente&orden_id=
     *
     * La bandeja de quien decide. Lo pendiente primero y lo más viejo arriba:
     * una devolución sin resolver es un mueble parado en la bodega y un cliente
     * esperando respuesta.
     */
    public function index(Request $request)
    {
        $q = Devolucion::with([
            'orden:id,referencia,cliente_id,tienda_id,estado',
            'orden.cliente:id,nombre',
            'item', 'item.producto:id,nombre',
            'reportadoPor:id,nombre', 'decididoPor:id,nombre',
        ]);

        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }
        if ($ordenId = $request->query('orden_id')) {
            $q->where('orden_id', $ordenId);
        }

        $q->orderByRaw("estado = 'pendiente' DESC")->orderBy('fecha');

        return response()->json($q->get()->map(fn (Devolucion $d) => $this->comoJson($d)));
    }

    /**
     * POST /api/devoluciones
     *
     * Registrarla a mano, para cuando no pasó por el camión —el cliente la
     * trajo a la tienda— o cuando el conductor no alcanzó a marcarla. El
     * camino normal es el de la entrega, en DespachoController.
     */
    public function store(Request $request)
    {
        $usuario = $request->user();

        if (! $this->puedeDecidir($usuario) && ! $usuario->acceso_despacho) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'orden_item_id' => 'required|exists:orden_items,id',
            'cantidad'      => 'required|integer|min:1',
            'motivo'        => 'required|string|min:3|max:1000',
            'foto_url'      => 'nullable|string|max:500',
            'fecha'         => 'required|date',
        ], [
            'motivo.required' => 'Escribe por qué se devolvió.',
        ]);

        $item = \App\Models\OrdenItem::with('orden')->findOrFail($data['orden_item_id']);

        if ($data['cantidad'] > $item->cantidad) {
            return response()->json([
                'message' => "No se pueden devolver {$data['cantidad']}: la orden lleva {$item->cantidad}.",
            ], 422);
        }

        $devolucion = DB::transaction(function () use ($data, $item, $usuario) {
            $d = Devolucion::create($data + [
                'orden_id'         => $item->orden_id,
                'reportado_por_id' => $usuario->id,
                'estado'           => 'pendiente',
            ]);

            self::marcarOrdenDevuelta($item->orden, $d, $usuario);

            return $d;
        });

        self::avisarDevolucion($devolucion->load('orden.cliente', 'item'));

        return response()->json($this->comoJson($devolucion->fresh(['orden.cliente', 'item', 'reportadoPor'])), 201);
    }

    /**
     * POST /api/devoluciones/{id}/decidir
     *
     * Los dos caminos: vuelve al taller a que la arreglen, o se cancela y se
     * le devuelve la plata.
     */
    public function decidir(Request $request, int $id)
    {
        $usuario = $request->user();

        if (! $this->puedeDecidir($usuario)) {
            return response()->json([
                'message' => 'Esto lo decide quien gestiona Producción.',
            ], 403);
        }

        $devolucion = Devolucion::with('orden', 'item')->findOrFail($id);

        if (! $devolucion->estaPendiente()) {
            return response()->json(['message' => 'Esta devolución ya se resolvió.'], 422);
        }

        $data = $request->validate([
            'decision' => 'required|in:a_produccion,reembolso',
            'notas'    => 'nullable|string|max:1000',
            // Solo para el reembolso. Se sugiere lo que pagó por esas unidades,
            // pero manda lo que se escriba: a veces se devuelve de más por el
            // disgusto, o de menos porque se cobra un arreglo.
            'monto'    => 'required_if:decision,reembolso|nullable|numeric|min:0',
        ], [
            'monto.required_if' => 'Falta cuánto se le devuelve al cliente.',
        ]);

        DB::transaction(function () use ($devolucion, $data, $usuario) {
            $devolucion->fill([
                'decidido_por_id' => $usuario->id,
                'decidido_at'     => now(),
                'notas_decision'  => $data['notas'] ?? null,
            ]);

            if ($data['decision'] === 'a_produccion') {
                $this->mandarAProduccion($devolucion, $usuario);
            } else {
                $this->reembolsar($devolucion, (float) $data['monto'], $usuario);
            }

            $devolucion->save();
        });

        return response()->json($this->comoJson($devolucion->fresh([
            'orden.cliente', 'item', 'reportadoPor', 'decididoPor',
        ])));
    }

    // ── Los dos caminos ──────────────────────────────────────────────────────

    /**
     * Vuelve al taller. Es lo que pasa casi siempre.
     *
     * Se reabre la producción de esa pieza en vez de crear otra: así el mueble
     * conserva su historia —quién lo hizo, cuánto tardó, qué pasos llevó— y el
     * arreglo queda pegado a eso, que es justo lo que hay que mirar cuando la
     * misma pieza vuelve dos veces.
     */
    private function mandarAProduccion(Devolucion $devolucion, Usuario $usuario): void
    {
        $produccion = Produccion::where('orden_item_id', $devolucion->orden_item_id)->first();

        if ($produccion) {
            $produccion->update([
                'estado'         => 'pendiente',
                'fecha_real'     => null,
                'motivo_retraso' => 'Devuelto: ' . $devolucion->motivo,
            ]);
        } else {
            // Un producto de catálogo no tenía producción: nunca se fabricó.
            // Se le crea una para el arreglo, que es lo que lo hace aparecer en
            // el tablero del taller.
            Produccion::create([
                'orden_item_id'    => $devolucion->orden_item_id,
                'fecha_inicio'     => now()->toDateString(),
                'fecha_compromiso' => null,
                'estado'           => 'pendiente',
                'motivo_retraso'   => 'Devuelto: ' . $devolucion->motivo,
            ]);
        }

        $devolucion->estado = 'a_produccion';

        $devolucion->orden->update(['estado' => 'en_produccion']);

        $this->anotarEnLaOrden(
            $devolucion,
            $usuario,
            'vuelve al taller para arreglo',
        );
    }

    /**
     * Se cancela esa pieza y se le devuelve la plata.
     *
     * La salida queda en la caja de la tienda que vendió: si no, el día que se
     * cuadre la caja va a faltar esa plata sin nada que lo explique.
     */
    private function reembolsar(Devolucion $devolucion, float $monto, Usuario $usuario): void
    {
        $orden = $devolucion->orden;

        if ($monto > 0) {
            $movimiento = CajaMovimiento::create([
                'tienda_id'   => $orden->tienda_id,
                'usuario_id'  => $usuario->id,
                'tipo'        => 'egreso',
                'monto'       => $monto,
                'concepto'    => 'Devolución orden ' . $orden->referencia,
                'descripcion' => trim(($devolucion->item?->nombre_custom ?: ($devolucion->item?->producto?->nombre ?? 'Producto')) . ' — ' . $devolucion->motivo),
            ]);
            $devolucion->caja_movimiento_id = $movimiento->id;
        }

        $devolucion->monto_devuelto = $monto;
        $devolucion->estado         = 'reembolsada';

        // El producto que volvió está roto: sale del inventario. Devolverlo a
        // disponible lo pondría a la venta otra vez, y lo que hay en la bodega
        // es justo la pieza que el cliente no quiso.
        $this->sacarDeInventario($devolucion, $usuario);

        // Si ya no queda nada vivo por entregar, la orden se acabó. Si el
        // cliente se quedó con lo demás, la orden se cierra como entregada:
        // esa parte sí llegó.
        $orden->update(['estado' => $this->quedaAlgoPorEntregar($orden, $devolucion) ? 'entregado' : 'cancelado']);

        $this->anotarEnLaOrden(
            $devolucion,
            $usuario,
            'se cancela y se le devuelven $' . number_format($monto, 0, ',', '.'),
        );
    }

    /**
     * ¿Le queda al cliente algo de esta orden?
     *
     * Se mira ítem por ítem cuánto se devolvió y se reembolsó: si de todo lo
     * que llevaba la orden no quedó nada en la casa, la venta no existió.
     *
     * La devolución que se está resolviendo se cuenta aparte porque todavía no
     * está guardada: leer solo la base la dejaría fuera, y la última devolución
     * —justo la que vacía la orden— nunca alcanzaría a cancelarla.
     */
    private function quedaAlgoPorEntregar(Orden $orden, Devolucion $enCurso): bool
    {
        $devueltas = Devolucion::where('orden_id', $orden->id)
            ->where('estado', 'reembolsada')
            ->where('id', '!=', $enCurso->id)
            ->get()
            ->groupBy('orden_item_id')
            ->map(fn ($g) => $g->sum('cantidad'));

        $devueltas[$enCurso->orden_item_id] =
            ($devueltas[$enCurso->orden_item_id] ?? 0) + (int) $enCurso->cantidad;

        foreach ($orden->items as $item) {
            if ((int) $item->cantidad > (int) ($devueltas[$item->id] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /** Lo devuelto de catálogo sale del stock: está roto, no se puede revender. */
    private function sacarDeInventario(Devolucion $devolucion, Usuario $usuario): void
    {
        $item = $devolucion->item;

        if (! $item || $item->es_personalizado || ! $item->producto_id) {
            return;
        }

        $tiendaId = $item->tienda_origen_id ?? $devolucion->orden->tienda_id;
        $cant     = (int) $devolucion->cantidad;

        // Se resta leyendo primero en vez de con un UPDATE calculado: hay que
        // frenar en cero. Si la reserva ya se había soltado por otro lado, un
        // "menos dos" a secas dejaría el inventario en negativo, y de ahí en
        // adelante todo lo que se muestre de ese producto está mal.
        $restar = function ($fila) use ($cant) {
            if (! $fila) return;
            $fila->update([
                'cantidad_disponible' => max((int) $fila->cantidad_disponible - $cant, 0),
                'cantidad_reservada'  => max((int) $fila->cantidad_reservada - $cant, 0),
            ]);
        };

        if ($item->variante_id) {
            $restar(InventarioVariante::where('variante_id', $item->variante_id)
                ->where('tienda_id', $tiendaId)->first());
        }

        $restar(Inventario::where('producto_id', $item->producto_id)
            ->where('tienda_id', $tiendaId)->first());

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id,
            'tienda_id'   => $tiendaId,
            'tipo'        => 'salida',
            'cantidad'    => $cant,
            'motivo'      => "Devolución dañada — orden {$devolucion->orden->referencia}",
            'usuario_id'  => $usuario->id,
        ]);
    }

    // ── Rastro ───────────────────────────────────────────────────────────────

    /**
     * La orden pasa a `devuelto` y queda escrito qué volvió y por qué.
     *
     * El rastro va en el hilo de la orden, que es donde la gente ya mira. En
     * el PDF no: esa hoja se la lleva el cliente y es el comprobante de lo que
     * compró, no el expediente de lo que salió mal.
     */
    public static function marcarOrdenDevuelta(Orden $orden, Devolucion $devolucion, Usuario $usuario): void
    {
        $orden->update(['estado' => 'devuelto']);

        $nombre = $devolucion->item?->nombre_custom
            ?: ($devolucion->item?->producto?->nombre ?? 'Un producto');

        OrdenMensaje::create([
            'orden_id'   => $orden->id,
            'usuario_id' => $usuario->id,
            'mensaje'    => "🔄 Devuelto el {$devolucion->fecha->format('d/m/Y')}: {$devolucion->cantidad} × {$nombre}. "
                          . "Motivo: {$devolucion->motivo}",
            'imagen_url' => $devolucion->foto_url,
        ]);
    }

    private function anotarEnLaOrden(Devolucion $devolucion, Usuario $usuario, string $queSeHizo): void
    {
        OrdenMensaje::create([
            'orden_id'   => $devolucion->orden_id,
            'usuario_id' => $usuario->id,
            'mensaje'    => "Sobre la devolución del {$devolucion->fecha->format('d/m/Y')}: {$queSeHizo}."
                          . ($devolucion->notas_decision ? " {$devolucion->notas_decision}" : ''),
        ]);
    }

    /**
     * Se avisa a quien decide, marcado como urgente: mientras nadie resuelva,
     * hay un mueble parado en la bodega y un cliente esperando respuesta.
     */
    public static function avisarDevolucion(Devolucion $devolucion): void
    {
        $nombre  = $devolucion->item?->nombre_custom ?: ($devolucion->item?->producto?->nombre ?? 'Un producto');
        $cliente = $devolucion->orden?->cliente?->nombre ?? 'el cliente';

        $destinatarios = Usuario::where('activo', true)->where('gestiona_produccion', true)->get();

        if ($destinatarios->isEmpty()) {
            $destinatarios = Usuario::where('activo', true)->where('rol', 'supervisor')->get();
        }

        foreach ($destinatarios as $d) {
            NotificacionService::crear(
                'devolucion',
                'Se devolvió un producto',
                "{$nombre} de {$cliente} volvió en el camión: {$devolucion->motivo}. Hay que decidir si se arregla o se cancela.",
                ['orden_id' => $devolucion->orden_id, 'devolucion_id' => $devolucion->id],
                $d->id,
                urgente: true,
            );
        }
    }
}
