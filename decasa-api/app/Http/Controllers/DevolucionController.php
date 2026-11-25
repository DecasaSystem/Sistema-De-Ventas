<?php

namespace App\Http\Controllers;

use App\Models\Devolucion;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\OrdenMensaje;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Services\CambioDeProducto;
use App\Services\NotificacionService;
use App\Support\Esquema;
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
            // Para proponer la caja de donde sale el efectivo al devolver la plata.
            'orden_tienda_id' => $d->orden?->tienda_id,
            'cliente'        => $d->orden?->cliente?->nombre,
            'orden_item_id'  => $d->orden_item_id,
            'producto'       => $item?->nombre_custom ?: ($item?->producto?->nombre ?? 'Ítem eliminado'),
            'es_personalizado' => (bool) ($item?->es_personalizado),
            'cantidad'       => (int) $d->cantidad,
            'motivo'         => $d->motivo,
            'foto_url'       => $d->foto_url,
            // Lo que el vendedor le ofreció al cliente y este prefirió. Es
            // una sugerencia para quien decide, no la decisión.
            'preferencia_cliente' => $d->preferencia_cliente,
            'orden_estado'   => $d->orden?->estado,
            'fecha'          => $d->fecha->toDateString(),
            'reportado_por'  => $d->reportadoPor?->nombre,
            'estado'         => $d->estado,
            'decidido_por'   => $d->decididoPor?->nombre,
            'decidido_at'    => $d->decidido_at?->toIso8601String(),
            'notas_decision' => $d->notas_decision,
            'monto_devuelto' => $d->monto_devuelto !== null ? (float) $d->monto_devuelto : null,
            'monto_sugerido' => $d->montoSugerido(),
            'registrado_en'  => $d->created_at->toIso8601String(),
            // Cambio de producto: a dónde va lo devuelto y si falta recogerlo.
            'destino'             => $d->destino,
            'tienda_destino_id'   => $d->tienda_destino_id,
            'tienda_destino'      => $d->tienda_destino_id ? $d->tiendaDestino?->nombre : null,
            'recoger_el'          => $d->recoger_el?->toDateString(),
            'recoger_con_entrega' => (bool) $d->recoger_con_entrega,
            'recibido_at'         => $d->recibido_at?->toIso8601String(),
            'recibido_por'        => $d->recibido_por_id ? $d->recibidoPor?->nombre : null,
            'por_recoger'         => $d->porRecoger(),
        ];
    }

    /** Recoge y recibe lo devuelto quien despacha, quien maneja el taller o un supervisor. */
    private function puedeRecoger(?Usuario $u): bool
    {
        return $u && ($u->rol === 'supervisor' || (bool) $u->acceso_despacho || (bool) $u->gestiona_produccion);
    }

    /**
     * GET /api/devoluciones/destinos
     *
     * A dónde puede ir lo devuelto: las tiendas abiertas y la fábrica/bodega
     * (que `/tiendas` deja por fuera porque no vende).
     */
    public function destinos(Request $request)
    {
        if (! $this->puedeRecoger($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json(\App\Models\Tienda::query()
            ->where(fn ($q) => $q->whereNull('activa')->orWhere('activa', true))
            ->where(fn ($q) => $q->whereNull('es_independientes')->orWhere('es_independientes', false))
            ->orderByDesc('es_fabrica')->orderBy('nombre')
            ->get(['id', 'nombre', 'es_fabrica']));
    }

    /**
     * GET /api/devoluciones/por-recoger
     *
     * Lo que hay que traer de la casa de un cliente que cambió un producto.
     * Lo más urgente arriba: lo programado por fecha y después lo que se
     * recoge con la entrega del nuevo. Con dirección y teléfono: es para ir.
     */
    public function porRecoger(Request $request)
    {
        if (! $this->puedeRecoger($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if (! Esquema::columna('devoluciones', 'destino')) {
            return response()->json([]);
        }

        $lista = Devolucion::faltaRecoger()
            ->with(['orden',
                    'orden.cliente:id,nombre,telefono,direccion', 'item', 'item.producto:id,nombre',
                    'tiendaDestino:id,nombre', 'reportadoPor:id,nombre'])
            ->orderByRaw('recoger_el IS NULL')->orderBy('recoger_el')->orderBy('id')
            ->get()
            ->map(fn (Devolucion $d) => $this->comoJson($d) + [
                'telefono'  => $d->orden?->cliente?->telefono,
                'direccion' => $d->orden?->direccion_envio ?: $d->orden?->cliente?->direccion,
            ]);

        return response()->json($lista);
    }

    /**
     * POST /api/devoluciones/{id}/recibir  { destino?, tienda_id? }
     *
     * Llegó: entra al inventario de donde se dijo. Se puede cambiar el
     * destino al recibirlo (llegó rayado → merma; mejor a la fábrica).
     */
    public function recibir(Request $request, int $id)
    {
        if (! $this->puedeRecoger($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'destino'   => 'nullable|in:tienda,merma',
            'tienda_id' => 'nullable|integer|exists:tiendas,id',
        ]);

        $d = CambioDeProducto::recibir(Devolucion::findOrFail($id), $data, $request->user());

        return response()->json($this->comoJson($d->load(['orden.cliente', 'item', 'tiendaDestino', 'recibidoPor'])));
    }

    /** PATCH /api/devoluciones/{id}/recogida  { recogida: programada|con_entrega, fecha_recogida? } */
    public function reprogramar(Request $request, int $id)
    {
        if (! $this->puedeRecoger($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'recogida'       => 'required|in:programada,con_entrega',
            'fecha_recogida' => 'required_if:recogida,programada|nullable|date',
        ], ['fecha_recogida.required_if' => 'Escoge qué día se recoge.']);

        $d = CambioDeProducto::reprogramar(Devolucion::findOrFail($id), $data, $request->user());

        return response()->json($this->comoJson($d->load(['orden.cliente', 'item', 'tiendaDestino'])));
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
            // La orden entera: `referencia` no es columna (se arma con serie, número
            // y estado). Pedirla en el select tumbaba la lista en MySQL y la orden
            // #1231 quedó sin que nadie viera su devolución. SQLite no lo notaba.
            'orden',
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

        $data = $request->validate([
            'orden_item_id' => 'required|exists:orden_items,id',
            'cantidad'      => 'required|integer|min:1',
            'motivo'        => 'required|string|min:3|max:1000',
            'foto_url'      => 'nullable|string|max:500',
            'fecha'         => 'required|date',
            // Qué prefiere el cliente: arreglarlo, otra unidad igual, u otro
            // producto. El vendedor lo anota; el supervisor decide.
            'preferencia_cliente' => 'nullable|in:arreglar,cambiar_mismo,cambiar_otro',
        ], [
            'motivo.required' => 'Escribe por qué se devolvió.',
        ]);

        $item = \App\Models\OrdenItem::with('orden')->findOrFail($data['orden_item_id']);

        // Lo registra quien decide, quien despacha, o quien puede ver la
        // orden: el vendedor que vio el mueble rayado en la tienda antes de
        // entregarlo también tiene que poder reportarlo.
        if (! $this->puedeDecidir($usuario) && ! $usuario->acceso_despacho
            && ! $item->orden->laPuedeVer($usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($item->orden->estado === 'cancelado' || ! $item->estaVivo()) {
            return response()->json(['message' => 'Ese producto ya no está vivo en la orden.'], 422);
        }

        if ($data['cantidad'] > $item->cantidad) {
            return response()->json([
                'message' => "No se pueden devolver {$data['cantidad']}: la orden lleva {$item->cantidad}.",
            ], 422);
        }

        // Aquí va lo que todavía no le llega al cliente (volvió en el camión, o
        // se vio dañado antes de entregarlo). Todas las decisiones de abajo
        // suponen que nunca salió del inventario: con algo que ya estaba en la
        // casa, "Otra igual" bajaba otra unidad del stock y la nueva nunca
        // quedaba por entregar (revisión 2026-11-17). La pantalla ya lo
        // frenaba; ahora también el servidor.
        $falta = $item->pendienteEntregar();
        if ($data['cantidad'] > $falta) {
            return response()->json([
                'message' => $falta > 0
                    ? "De ese producto solo quedan {$falta} por entregar. Lo que el cliente ya tiene en la casa va por Garantías (si se dañó) o por Cambiar producto (si no le sirvió)."
                    : 'Ese producto ya se le entregó al cliente: va por Garantías (si se dañó) o por Cambiar producto (si no le sirvió).',
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
            // reentregar: estaba bien, se le vuelve a llevar el mismo.
            // quitar: el cliente se queda sin eso; deja de cobrarse.
            'decision' => 'required|in:a_produccion,reembolso,cambio_mismo,cambio_otro,reentregar,quitar',
            // Para quitar y para devolver la plata: ¿lo devuelto está para
            // vender? Si no, merma. (Al devolver la plata, sin el dato, merma.)
            'vuelve_al_stock' => 'boolean',
            'notas'    => 'nullable|string|max:1000',
            // Solo para el reembolso. Se sugiere lo que pagó por esas unidades,
            // pero manda lo que se escriba: a veces se devuelve de más por el
            // disgusto, o de menos porque se cobra un arreglo.
            'monto'    => 'required_if:decision,reembolso|nullable|numeric|min:0',
            // Cómo se le devolvió: en efectivo baja la caja de la tienda; por
            // transferencia, no. Sin el dato (pantallas viejas) se asume
            // efectivo, que es lo que se hacía antes (todo salía de la caja).
            'metodo'     => 'nullable|in:efectivo,transferencia,otro',
            'referencia' => 'nullable|string|max:100',
            // De qué caja salió el efectivo. Sin el dato, la de la tienda de la orden.
            'caja_tienda_id' => 'nullable|integer|exists:tiendas,id',
        ], [
            'monto.required_if' => 'Falta cuánto se le devuelve al cliente.',
        ]);

        // Si la orden queda cancelada, los avisos van después de guardar.
        $referenciaCancelada = null;
        DB::transaction(function () use ($devolucion, $data, $usuario, &$referenciaCancelada) {
            $devolucion->fill([
                'decidido_por_id' => $usuario->id,
                'decidido_at'     => now(),
                'notas_decision'  => $data['notas'] ?? null,
            ]);

            match ($data['decision']) {
                'a_produccion' => $this->mandarAProduccion($devolucion, $usuario),
                'cambio_mismo' => $this->cambiarPorElMismo($devolucion, $usuario),
                'cambio_otro'  => $this->cambiarPorOtro($devolucion, $usuario),
                'reentregar'   => $this->reentregar($devolucion, $usuario),
                'quitar'       => $this->quitarDeLaOrden($devolucion, (bool) ($data['vuelve_al_stock'] ?? true), $usuario),
                default        => $referenciaCancelada = $this->reembolsar(
                    $devolucion, (float) $data['monto'], $data['metodo'] ?? 'efectivo', $data['referencia'] ?? null,
                    (bool) ($data['vuelve_al_stock'] ?? false), $usuario,
                    isset($data['caja_tienda_id']) ? (int) $data['caja_tienda_id'] : null,
                ),
            };

            $devolucion->save();
        });

        if ($data['decision'] === 'reembolso') {
            $orden = Orden::with('cliente')->find($devolucion->orden_id);
            if ($orden) {
                \App\Services\AvisoFacturacion::reembolso($orden, (float) $data['monto'], $usuario, 'Devolución');
                if ($referenciaCancelada !== null) {
                    \App\Services\CancelacionDeOrden::avisar($orden, $usuario, $referenciaCancelada);
                } elseif ($orden->vendedor_id && (int) $orden->vendedor_id !== (int) $usuario->id) {
                    NotificacionService::crear(
                        'orden_editada',
                        'Se le devolvió plata a un cliente',
                        "Orden {$orden->referencia} — " . ($orden->cliente?->nombre ?? 'cliente') . ': se devolvieron $'
                            . number_format((float) $data['monto'], 0, ',', '.') . " por un producto que volvió ({$usuario->nombre}). Ya no se cobra.",
                        ['orden_id' => $orden->id],
                        $orden->vendedor_id,
                    );
                }
            }
        }

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
                'fecha_inicio'     => \App\Services\RangoFechas::hoyTexto(),
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
     * Se cambia por otra unidad de lo mismo. La orden no cambia de valor.
     *
     * De catálogo: la dañada sale del inventario como merma y se aparta otra
     * igual para volverla a entregar. Si no hay otra en la tienda, no se
     * puede por aquí: se cambia por otro producto o se manda a fabricar.
     * Fabricado: se hace de nuevo. Es la misma producción vuelta a empezar,
     * para que el mueble conserve su historia.
     */
    private function cambiarPorElMismo(Devolucion $devolucion, Usuario $usuario): void
    {
        $item  = $devolucion->item;
        $orden = $devolucion->orden;

        if ($item->vaAlTaller()) {
            $produccion = Produccion::where('orden_item_id', $item->id)->first();
            if ($produccion) {
                $produccion->update([
                    'estado'         => 'pendiente',
                    'fecha_real'     => null,
                    'motivo_retraso' => 'Se hace de nuevo: ' . $devolucion->motivo,
                ]);
            } else {
                Produccion::create([
                    'orden_item_id'    => $item->id,
                    'fecha_inicio'     => \App\Services\RangoFechas::hoyTexto(),
                    'fecha_compromiso' => null,
                    'estado'           => 'pendiente',
                    'motivo_retraso'   => 'Se hace de nuevo: ' . $devolucion->motivo,
                ]);
            }
            $orden->update(['estado' => 'en_produccion']);
        } else {
            $tiendaId = $item->tienda_origen_id ?? $orden->tienda_id;
            $cant     = (int) $devolucion->cantidad;

            // ¿Hay otra igual? Lo apartado por esta misma orden no cuenta
            // como libre.
            $inv   = Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $tiendaId)->first();
            $libre = $inv ? (int) $inv->cantidad_disponible - (int) $inv->cantidad_reservada : 0;
            if ($libre < $cant) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => 'No hay otra unidad igual en la tienda. Cámbialo por otro producto o regístralo para fabricar.',
                ], 422));
            }

            // La dañada sale como merma...
            $this->sacarDeInventario($devolucion, $usuario);
            // ...y se aparta otra para esta orden.
            Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $tiendaId)
                ->increment('cantidad_reservada', $cant);
            if ($item->variante_id) {
                InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)
                    ->increment('cantidad_reservada', $cant);
            }
            InventarioMovimiento::create([
                'producto_id' => $item->producto_id,
                'tienda_id'   => $tiendaId,
                'tipo'        => 'reserva',
                'cantidad'    => $cant,
                'motivo'      => "Cambio por otra unidad — orden {$orden->referencia}",
                'usuario_id'  => $usuario->id,
            ]);

            $orden->refresh()->load('items.produccion');
            $orden->update(['estado' => $orden->estadoTrasEntrega()]);
        }

        $devolucion->estado = 'cambio_mismo';

        $this->anotarEnLaOrden($devolucion, $usuario, 'se cambia por otra unidad igual');
    }

    /**
     * Se cambia por otro producto, que puede valer más, menos o lo mismo.
     *
     * Lo devuelto deja de cobrarse —si era todo el renglón se marca devuelto,
     * si era parte se descuenta—, la orden vuelve a estar abierta y el
     * vendedor agrega el producto nuevo desde Editar. El saldo se recalcula
     * contra lo ya pagado: si el nuevo vale más, paga la diferencia; si vale
     * menos, queda a favor. Es la misma cuenta que un cambio después de
     * entregada.
     */
    private function cambiarPorOtro(Devolucion $devolucion, Usuario $usuario): void
    {
        $item  = $devolucion->item;
        $orden = $devolucion->orden;
        $cant  = (int) $devolucion->cantidad;

        // La dañada no vuelve a la venta.
        $this->sacarDeInventario($devolucion, $usuario);

        if ($item->vaAlTaller()) {
            Produccion::where('orden_item_id', $item->id)
                ->whereNotIn('estado', ['cancelado', 'entregado'])
                ->update(['estado' => 'cancelado']);
        }

        if ($cant >= (int) $item->cantidad) {
            $item->update([
                'devuelto_en'       => $devolucion->fecha,
                'motivo_devolucion' => $devolucion->motivo,
            ]);
        } else {
            // Vuelve solo parte: esas unidades salen del renglón. Lo que ya
            // se le entregó de este mismo renglón se queda como está.
            $item->update([
                'cantidad'           => (int) $item->cantidad - $cant,
                'cantidad_entregada' => min((int) $item->cantidad_entregada, (int) $item->cantidad - $cant),
            ]);
        }

        $orden->refresh();
        $orden->recalcularTotal();

        // Abierta para el reemplazo. Si queda algo en el taller sigue "en
        // producción" (antes pasaba a espera aunque hubiera una cama a medio
        // hacer): las dos dejan agregar el producto nuevo desde Editar.
        $orden->refresh()->load('items.produccion');
        $orden->update(['estado' => $orden->estadoTrasEntrega() === 'en_produccion' ? 'en_produccion' : 'pendiente_anticipo']);

        // El valor cambió: la comisión lo sigue.
        \App\Http\Controllers\ComisionController::sincronizarValorOrden($orden->fresh());

        $devolucion->estado = 'cambio';

        $this->anotarEnLaOrden(
            $devolucion, $usuario,
            'se cambia por otro producto. La orden queda abierta: agrégale el nuevo desde Editar y el saldo se ajusta solo',
        );
    }

    /**
     * Estaba bien: se le vuelve a llevar el mismo producto.
     *
     * Caso real, orden #1231: en la entrega los tomacorrientes volvieron en el
     * camión ("no eran los que iban") y la orden quedó en `devuelto` sin
     * salida. "Otra igual" pedía OTRAS dos unidades libres en la tienda, y las
     * únicas dos eran justamente esas, todavía apartadas para la orden. Lo que
     * vuelve en el camión nunca salió del inventario: sigue apartado y sigue
     * "por entregar". No hay que mover nada; solo destrabar la orden.
     */
    private function reentregar(Devolucion $devolucion, Usuario $usuario): void
    {
        $item = $devolucion->item;

        if (! $item || ! $item->estaVivo() || $item->pendienteEntregar() < (int) $devolucion->cantidad) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Esas unidades ya se le habían entregado al cliente: no hay qué volver a llevar. Usa "Otra igual" o "Al taller".',
            ], 422));
        }

        $devolucion->estado = 'reentrega';
        $this->ponerOrdenAlDia($devolucion);

        $this->anotarEnLaOrden($devolucion, $usuario, 'estaba bien: se le vuelve a llevar. Queda por entregar en Despacho');
    }

    /**
     * El cliente se queda sin eso: deja de cobrarse y la orden sigue con lo
     * demás. Lo que ya pagó queda a su favor (devolverle la plata es
     * "Devolver la plata", aparte).
     *
     * Lo devuelto vuelve a la venta o sale como merma. Si volvió en el camión
     * nunca salió del inventario: basta con soltarlo (o, si es merma, sacarlo).
     * Si ya se le había entregado y lo trajo después, vuelve a entrar.
     */
    private function quitarDeLaOrden(Devolucion $devolucion, bool $vuelveAlStock, Usuario $usuario): void
    {
        $item  = $devolucion->item;
        $orden = $devolucion->orden;
        $n     = (int) $devolucion->cantidad;

        if (! $item || ! $item->estaVivo()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Ese producto ya no está en la orden.',
            ], 422));
        }

        $nuncaSalio = $this->resolverInventario($devolucion, $vuelveAlStock, $usuario);

        if ($n >= (int) $item->cantidad) {
            $item->update(['devuelto_en' => $devolucion->fecha, 'motivo_devolucion' => $devolucion->motivo]);
        } else {
            $item->update([
                'cantidad'           => (int) $item->cantidad - $n,
                'cantidad_entregada' => $nuncaSalio ? (int) $item->cantidad_entregada : max(0, (int) $item->cantidad_entregada - $n),
            ]);
        }

        $orden->refresh();
        $orden->recalcularTotal();
        \App\Http\Controllers\ComisionController::sincronizarValorOrden($orden->fresh());

        $devolucion->estado = 'quitada';
        $this->ponerOrdenAlDia($devolucion);

        $saldo = $orden->fresh()->saldoPendiente();
        $this->anotarEnLaOrden(
            $devolucion, $usuario,
            'el cliente se queda sin eso: deja de cobrarse ($' . number_format($n * (float) $item->precio_unitario, 0, ',', '.') . ')'
            . ($vuelveAlStock && $item->producto_id && ! $item->es_personalizado ? ' y vuelve al inventario' : ' y sale como merma')
            . ($saldo < -0.5 ? '. Le quedan $' . number_format(-$saldo, 0, ',', '.') . ' a favor' : ''),
        );
    }

    /**
     * A dónde va lo devuelto: a la venta otra vez o merma.
     *
     * Si volvió en el camión nunca salió del inventario: basta con soltarlo
     * (o, si es merma, sacarlo). Si ya lo tenía el cliente y lo trajo, vuelve
     * a entrar (o, si es merma, no se toca: ya había salido).
     *
     * @return bool si nunca salió (volvió en el camión)
     */
    private function resolverInventario(Devolucion $devolucion, bool $vuelveAlStock, Usuario $usuario): bool
    {
        $item  = $devolucion->item;
        $orden = $devolucion->orden;
        $n     = (int) $devolucion->cantidad;

        $nuncaSalio = $item->pendienteEntregar() >= $n;

        if ($item->es_personalizado || ! $item->producto_id) return $nuncaSalio;

        if ($nuncaSalio) {
            $vuelveAlStock
                ? $this->soltarReserva($devolucion, $usuario)
                : $this->sacarDeInventario($devolucion, $usuario);
        } elseif ($vuelveAlStock) {
            $tiendaId = $item->tienda_origen_id ?? $orden->tienda_id;
            Inventario::firstOrCreate(['producto_id' => $item->producto_id, 'tienda_id' => $tiendaId],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0])->increment('cantidad_disponible', $n);
            // Al que se le cambió la tela no se le devuelve a su variante: ya no es de esa tela.
            if ($item->variante_id && ! $item->retapizar) {
                InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)
                    ->increment('cantidad_disponible', $n);
                if ($item->combo_config_id) {
                    \App\Models\InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                        ->where('config_id', $item->combo_config_id)->where('tienda_id', $tiendaId)
                        ->increment('cantidad_disponible', $n);
                }
            }
            if (! $item->retapizar) \App\Support\StockMedida::mover($item->combo_config_id, (int) $tiendaId, $n);
            InventarioMovimiento::create([
                'producto_id' => $item->producto_id, 'tienda_id' => $tiendaId, 'tipo' => 'entrada',
                'cantidad' => $n, 'motivo' => "Devuelto, se quita de la orden {$orden->referencia}",
                'usuario_id' => $usuario->id,
            ]);
        }

        return $nuncaSalio;
    }

    /** Lo que volvió en el camión sigue apartado: se suelta para que otro lo compre. */
    private function soltarReserva(Devolucion $devolucion, Usuario $usuario): void
    {
        $item     = $devolucion->item;
        $tiendaId = $item->tienda_origen_id ?? $devolucion->orden->tienda_id;
        $n        = (int) $devolucion->cantidad;

        $soltar = function ($fila) use ($n) {
            if ($fila) $fila->update(['cantidad_reservada' => max((int) $fila->cantidad_reservada - $n, 0)]);
        };
        // Lo mismo que apartó la venta: variante y combinación (tela × medida).
        // La medida no lleva contador de apartado (ver StockMedida).
        if ($item->variante_id) {
            $soltar(InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)->first());
            if ($item->combo_config_id) {
                $soltar(\App\Models\InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                    ->where('config_id', $item->combo_config_id)->where('tienda_id', $tiendaId)->first());
            }
        }
        $soltar(Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $tiendaId)->first());

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id, 'tienda_id' => $tiendaId, 'tipo' => 'liberacion',
            'cantidad' => $n, 'motivo' => "Devuelto en la entrega, se quita de la orden {$devolucion->orden->referencia}",
            'usuario_id' => $usuario->id,
        ]);
    }

    /**
     * La orden sale de `devuelto` al estado que le toca. Si queda otra
     * devolución sin decidir, sigue en `devuelto`. Si ya no le falta nada,
     * queda entregada; si no le queda ningún producto, abierta (con lo pagado
     * a favor) para que se le agregue algo o se resuelva la plata.
     */
    private function ponerOrdenAlDia(Devolucion $devolucion): void
    {
        $orden = $devolucion->orden->refresh()->load('items.produccion');

        $otraPendiente = Devolucion::where('orden_id', $orden->id)->where('estado', 'pendiente')
            ->where('id', '!=', $devolucion->id)->exists();
        if ($otraPendiente) return;

        if ($orden->items->filter->estaVivo()->isEmpty()) {
            $orden->update(['estado' => 'pendiente_anticipo']);
            return;
        }

        $estado = $orden->estadoTrasEntrega();
        // Sale de `devuelto`: si no le falta nada del taller, está lista para
        // llevarse (estadoTrasEntrega ya lo dice así desde `devuelto`).
        $cambios = ['estado' => $estado];
        if ($estado === 'listo_entrega' && ! $orden->listo_entrega_at && Esquema::columna('ordenes', 'listo_entrega_at')) $cambios['listo_entrega_at'] = now();
        $orden->update($cambios);
    }

    /**
     * Se cancela esa pieza y se le devuelve la plata.
     *
     * Igual que el reembolso de una garantía (GarantiaService::recibirDevolucion):
     *  - Lo devuelto deja de cobrarse (entero queda como rastro, `devuelto_en`;
     *    si era parte, esas unidades salen del renglón), el total baja y la
     *    comisión y la meta lo siguen. Una comisión ya pagada no se toca.
     *  - La plata sale como un pago NEGATIVO `tipo = reembolso`: en efectivo baja
     *    la caja de la tienda por donde entró; por transferencia, no. El saldo,
     *    la cartera y el 50 % de la comisión lo cuentan solos.
     *
     * Antes solo se anotaba un egreso en la caja: la orden seguía valiendo lo
     * mismo, el producto seguía en ella y la comisión y la meta seguían
     * contando la plata devuelta (revisión del 2026-11-17: se devolvían $2 M
     * de una cama y la orden quedaba entregada por $2,6 M).
     *
     * Si no le queda nada vivo, la orden se cancela por la puerta de siempre
     * (CancelacionDeOrden). Si le queda algo, queda en el estado que le toca
     * por lo que falta entregar (antes se cerraba como entregada aunque hubiera
     * algo en el taller).
     *
     * @return string|null la referencia de la orden si quedó cancelada
     */
    private function reembolsar(Devolucion $devolucion, float $monto, string $metodo, ?string $referencia, bool $vuelveAlStock, Usuario $usuario, ?int $cajaTiendaId = null): ?string
    {
        $orden = Orden::lockForUpdate()->findOrFail($devolucion->orden_id);
        $item  = \App\Models\OrdenItem::lockForUpdate()->find($devolucion->orden_item_id);
        $n     = (int) $devolucion->cantidad;

        if (! $item || ! $item->estaVivo()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Ese producto ya no está en la orden.',
            ], 422));
        }

        // No se devuelve plata que no entró: lo que el cliente ha pagado.
        $pagado = $orden->totalPagado();
        if ($monto > $pagado + 0.01) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'No se puede devolver más de lo que el cliente ha pagado ($' . number_format($pagado, 0, ',', '.') . ').',
            ], 422));
        }

        // Lo devuelto: a la venta otra vez o merma. Se pregunta (2026-11-17):
        // antes siempre era merma, también el sofá sano que el cliente no quiso
        // en la puerta. Sin el dato (pantallas viejas), merma como antes.
        $this->resolverInventario($devolucion, $vuelveAlStock, $usuario);

        // Si se fabricaba, el taller ya no lo hace.
        if ($n >= (int) $item->cantidad && $item->vaAlTaller()) {
            $ids = Produccion::where('orden_item_id', $item->id)
                ->whereNotIn('estado', ['cancelado', 'entregado'])->pluck('id');
            Produccion::whereIn('id', $ids)->update(['estado' => 'cancelado']);
            \App\Models\ProduccionPaso::whereIn('produccion_id', $ids)
                ->whereIn('estado', ['pendiente', 'en_proceso'])->update(['estado' => 'cancelado']);
        }

        // Deja de cobrarse.
        if ($n >= (int) $item->cantidad) {
            $item->update(['devuelto_en' => $devolucion->fecha, 'motivo_devolucion' => $devolucion->motivo]);
        } else {
            $item->update([
                'cantidad'           => (int) $item->cantidad - $n,
                'cantidad_entregada' => min((int) $item->cantidad_entregada, (int) $item->cantidad - $n),
            ]);
        }

        $orden->refresh();
        $orden->recalcularTotal();
        \App\Http\Controllers\ComisionController::sincronizarValorOrden($orden->fresh());

        // La plata.
        if ($monto > 0) {
            \App\Models\Pago::create([
                'orden_id'    => $orden->id,
                ...\App\Models\Pago::salidaDeCaja($orden, $usuario, $cajaTiendaId),
                'tipo'        => 'reembolso',
                'monto'       => -$monto,
                'metodo'      => $metodo,
                'referencia'  => $referencia,
                'notas'       => "Reembolso por la devolución #{$devolucion->id} — lo registró {$usuario->nombre}",
            ]);
        }

        $devolucion->monto_devuelto = $monto;
        $devolucion->estado         = 'reembolsada';

        $this->anotarEnLaOrden(
            $devolucion,
            $usuario,
            'se cancela y se le devuelven $' . number_format($monto, 0, ',', '.') . " ({$metodo}). Deja de cobrarse"
            . ($item->producto_id && ! $item->es_personalizado ? ($vuelveAlStock ? ' y vuelve al inventario' : ' y sale como merma') : ''),
        );

        $orden->refresh()->load('items.produccion');
        if ($orden->items->filter->estaVivo()->isEmpty()) {
            // La venta no existió: por la misma puerta que el botón Cancelar.
            $ref = $orden->referencia;
            \App\Services\CancelacionDeOrden::alCancelar($orden, $usuario);
            $orden->update(['estado' => 'cancelado']);

            return $ref;
        }

        $this->ponerOrdenAlDia($devolucion);

        return null;
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

        // Lo mismo que baja una entrega (EntregaService::sacarDelInventario):
        // variante, combinación (tela × medida) y la medida vendida.
        if ($item->variante_id) {
            $restar(InventarioVariante::where('variante_id', $item->variante_id)
                ->where('tienda_id', $tiendaId)->first());
            if ($item->combo_config_id) {
                $restar(\App\Models\InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                    ->where('config_id', $item->combo_config_id)->where('tienda_id', $tiendaId)->first());
            }
        }

        $restar(Inventario::where('producto_id', $item->producto_id)
            ->where('tienda_id', $tiendaId)->first());
        \App\Support\StockMedida::mover($item->combo_config_id, (int) $tiendaId, -$cant);

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

        OrdenMensaje::delSistema([
            'orden_id'   => $orden->id,
            'usuario_id' => $usuario->id,
            'mensaje'    => "🔄 Devuelto el {$devolucion->fecha->format('d/m/Y')}: {$devolucion->cantidad} × {$nombre}. "
                          . "Motivo: {$devolucion->motivo}",
            'imagen_url' => $devolucion->foto_url,
        ]);
    }

    private function anotarEnLaOrden(Devolucion $devolucion, Usuario $usuario, string $queSeHizo): void
    {
        OrdenMensaje::delSistema([
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
                "{$nombre} de {$cliente} " . ($devolucion->despacho_item_id ? 'volvió en el camión' : 'se reportó dañado antes de entregarlo')
                    . ": {$devolucion->motivo}. Hay que decidir si se arregla o se cancela.",
                ['orden_id' => $devolucion->orden_id, 'devolucion_id' => $devolucion->id],
                $d->id,
                urgente: true,
            );
        }
    }
}
