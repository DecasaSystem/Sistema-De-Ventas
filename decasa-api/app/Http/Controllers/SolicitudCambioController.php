<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\Pago;
use App\Models\SolicitudCambio;
use App\Models\Usuario;
use App\Services\CambiosDePlata;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los cambios de plata que un vendedor pide y un supervisor aprueba.
 *
 * El vendedor edita la orden como siempre; lo de plata (precios, cantidades,
 * productos, descuentos, abonos) no se guarda: se vuelve una solicitud con el
 * motivo y al menos una foto de soporte, y les llega a los supervisores. Al
 * aprobarla, el cambio se aplica por el mismo camino que una edición normal
 * —PATCH /ordenes/{id} y PATCH /pagos/{id}—, así que mueve inventario,
 * comisiones y avisa a facturación igual que siempre.
 */
class SolicitudCambioController extends Controller
{
    /**
     * POST /api/ordenes/{id}/solicitudes-cambio/revisar
     * Qué de todo esto es plata (y necesita aprobación). La pantalla lo usa
     * al guardar para saber si pedir el motivo y el soporte.
     */
    public function revisar(Request $request, int $id): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        if (! $orden->laPuedeEditar($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        [$cambiosOrden, $cambioPago, $pago] = $this->leerPedido($request, $orden);

        return response()->json([
            'requiere_aprobacion' => CambiosDePlata::requiereAprobacion($request->user(), $orden),
            'cambios'             => $this->resumen($orden, $cambiosOrden, $pago, $cambioPago),
        ]);
    }

    /** GET /api/ordenes/{id}/solicitudes-cambio */
    public function index(Request $request, int $id): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        if (! $orden->laPuedeVer($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json(
            SolicitudCambio::with(['solicitante:id,nombre', 'revisadoPor:id,nombre'])
                ->where('orden_id', $orden->id)
                ->orderByDesc('id')
                ->get()
        );
    }

    /** POST /api/ordenes/{id}/solicitudes-cambio */
    public function store(Request $request, int $id): JsonResponse
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);
        if (! $orden->laPuedeEditar($usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'motivo'     => 'required|string|min:5|max:1000',
            'soportes'   => 'required|array|min:1|max:6',
            'soportes.*' => 'required|string|max:500',
        ], [
            'motivo.required'   => 'Explica por qué se necesita el cambio.',
            'motivo.min'        => 'Explica un poco más por qué se necesita el cambio.',
            'soportes.required' => 'Adjunta al menos una foto de soporte.',
            'soportes.min'      => 'Adjunta al menos una foto de soporte.',
        ]);

        // Una a la vez por orden: dos solicitudes sobre los mismos precios se
        // pisarían al aprobarlas, y el supervisor no sabría cuál vale.
        if (SolicitudCambio::where('orden_id', $orden->id)->where('estado', SolicitudCambio::PENDIENTE)->exists()) {
            return response()->json([
                'message' => 'Esta orden ya tiene un cambio esperando aprobación. Espera la respuesta o retíralo para pedir otro.',
            ], 422);
        }

        [$cambiosOrden, $cambioPago, $pago] = $this->leerPedido($request, $orden);
        $resumen = $this->resumen($orden, $cambiosOrden, $pago, $cambioPago);
        if (! $resumen) {
            return response()->json(['message' => 'No hay cambios de dinero que aprobar.'], 422);
        }

        $solicitud = SolicitudCambio::create([
            'orden_id'       => $orden->id,
            'solicitante_id' => $usuario->id,
            'estado'         => SolicitudCambio::PENDIENTE,
            'cambios_orden'  => $cambiosOrden ?: null,
            'cambio_pago'    => $cambioPago ?: null,
            'resumen'        => $resumen,
            'motivo'         => trim($data['motivo']),
            'soportes'       => array_values($data['soportes']),
        ]);

        $ref = $orden->referencia;
        $cuantos = count($resumen);
        // Urgente: es plata, y el vendedor queda esperando con el cliente.
        NotificacionService::crear(
            'solicitud_cambio',
            "Aprobar cambio de dinero en {$ref}",
            "{$usuario->nombre} pide {$cuantos} cambio" . ($cuantos === 1 ? '' : 's') . " de dinero: " . mb_strimwidth(trim($data['motivo']), 0, 120, '…'),
            ['orden_id' => $orden->id, 'solicitud_id' => $solicitud->id],
            null,
            true,
        );

        return response()->json($solicitud->load('solicitante:id,nombre'), 201);
    }

    /** POST /api/solicitudes-cambio/{id}/aprobar (supervisor) */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        $supervisor = $request->user();
        $solicitud  = SolicitudCambio::with('orden', 'solicitante:id,nombre')->findOrFail($id);

        if ($solicitud->estado !== SolicitudCambio::PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya se respondió.'], 422);
        }

        try {
            DB::transaction(function () use ($solicitud, $supervisor) {
                // Lo que registren las ediciones al aplicar el cambio se junta
                // abajo en una sola entrada del historial: sin esto aparecerían
                // a nombre del supervisor como si él lo hubiera editado solo.
                $ultimaEdicion = (int) OrdenEdicion::where('orden_id', $solicitud->orden_id)->max('id');

                // El pago primero: si el cambio de la orden baja el total por
                // debajo de lo ya abonado, el orden importa, y el del pago es el
                // que se revisa contra el total.
                if ($solicitud->cambio_pago) {
                    $p = $solicitud->cambio_pago;
                    $this->aplicar(
                        fn (Request $r) => app(PagoController::class)->update($r, (int) $p['pago_id']),
                        "/api/pagos/{$p['pago_id']}",
                        array_intersect_key($p, array_flip(['monto', 'metodo', 'referencia'])),
                        $supervisor,
                    );
                }
                if ($solicitud->cambios_orden) {
                    $this->aplicar(
                        fn (Request $r) => app(OrdenController::class)->update($r, (int) $solicitud->orden_id),
                        "/api/ordenes/{$solicitud->orden_id}",
                        $solicitud->cambios_orden,
                        $supervisor,
                    );
                }

                // En el historial de la orden, una sola entrada: quién lo aprobó,
                // quién lo pidió y cuándo, el motivo, el soporte y lo que cambió
                // de verdad (lo que registró la edición al aplicarlo).
                $aplicadas = OrdenEdicion::where('orden_id', $solicitud->orden_id)
                    ->where('id', '>', $ultimaEdicion)->orderBy('id')->get();
                $cambiosAplicados = $aplicadas->flatMap(fn ($e) => $e->cambios ?? [])->values()->all();
                OrdenEdicion::whereIn('id', $aplicadas->pluck('id'))->delete();

                OrdenEdicion::create([
                    'orden_id'   => $solicitud->orden_id,
                    'usuario_id' => $supervisor->id,
                    'cambios'    => [$this->entradaHistorial($solicitud, 'aprobacion_dinero', $supervisor, [
                        'cambios' => $cambiosAplicados ?: $solicitud->resumen,
                    ])],
                ]);

                $solicitud->update([
                    'estado'          => SolicitudCambio::APROBADA,
                    'revisado_por_id' => $supervisor->id,
                    'revisado_at'     => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            // No se pudo aplicar (la orden cambió de estado, un producto ya no
            // tiene stock…): no queda nada a medias y la solicitud sigue
            // pendiente, para rechazarla con esa razón o reintentar.
            return response()->json(['message' => 'No se pudo aplicar: ' . $e->getMessage()], 422);
        }

        NotificacionService::crear(
            'solicitud_cambio_respuesta',
            'Cambio de dinero aprobado',
            "{$supervisor->nombre} aprobó tu cambio en {$solicitud->orden->referencia}. Ya quedó aplicado.",
            ['orden_id' => $solicitud->orden_id, 'solicitud_id' => $solicitud->id],
            $solicitud->solicitante_id,
        );

        return response()->json($solicitud->fresh(['solicitante:id,nombre', 'revisadoPor:id,nombre']));
    }

    /** POST /api/solicitudes-cambio/{id}/rechazar (supervisor) */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $supervisor = $request->user();
        $solicitud  = SolicitudCambio::with('orden')->findOrFail($id);

        $data = $request->validate(
            ['respuesta' => 'required|string|min:3|max:1000'],
            ['respuesta.required' => 'Dile al vendedor por qué no se aprueba.']
        );

        if ($solicitud->estado !== SolicitudCambio::PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya se respondió.'], 422);
        }

        $solicitud->update([
            'estado'          => SolicitudCambio::RECHAZADA,
            'revisado_por_id' => $supervisor->id,
            'revisado_at'     => now(),
            'respuesta'       => trim($data['respuesta']),
        ]);

        // También queda en el historial: lo que se pidió y no se hizo, y por qué.
        OrdenEdicion::create([
            'orden_id'   => $solicitud->orden_id,
            'usuario_id' => $supervisor->id,
            'cambios'    => [$this->entradaHistorial($solicitud->load('solicitante:id,nombre'), 'rechazo_dinero', $supervisor, [
                'cambios'   => $solicitud->resumen,
                'respuesta' => $solicitud->respuesta,
            ])],
        ]);

        NotificacionService::crear(
            'solicitud_cambio_respuesta',
            'Cambio de dinero rechazado',
            "{$supervisor->nombre} no aprobó tu cambio en {$solicitud->orden->referencia}: " . mb_strimwidth(trim($data['respuesta']), 0, 140, '…'),
            ['orden_id' => $solicitud->orden_id, 'solicitud_id' => $solicitud->id],
            $solicitud->solicitante_id,
        );

        return response()->json($solicitud->fresh(['solicitante:id,nombre', 'revisadoPor:id,nombre']));
    }

    /** POST /api/solicitudes-cambio/{id}/cancelar — la retira quien la pidió. */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $solicitud = SolicitudCambio::findOrFail($id);

        if ((int) $solicitud->solicitante_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Solo quien la pidió puede retirarla.'], 403);
        }
        if ($solicitud->estado !== SolicitudCambio::PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya se respondió.'], 422);
        }

        $solicitud->update(['estado' => SolicitudCambio::CANCELADA]);

        return response()->json($solicitud->fresh());
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * La entrada del historial de ediciones de una solicitud respondida. El
     * detalle de la orden la pinta aparte ("Cambio de dinero aprobado por…")
     * por su `tipo`.
     */
    private function entradaHistorial(SolicitudCambio $s, string $tipo, Usuario $supervisor, array $extra): array
    {
        $aprobada = $tipo === 'aprobacion_dinero';

        return [
            'campo'          => "solicitud_{$s->id}",
            'tipo'           => $tipo,
            'label'          => $aprobada ? 'Cambio de dinero aprobado' : 'Cambio de dinero rechazado',
            'revisado_por'   => $supervisor->nombre,
            'solicitado_por' => $s->solicitante?->nombre ?? 'Vendedor',
            'solicitado_at'  => $s->created_at?->toIso8601String(),
            'motivo'         => $s->motivo,
            'soportes'       => $s->soportes ?? [],
            'antes'          => null,
            'despues'        => null,
        ] + $extra;
    }

    /**
     * Lo que se pide cambiar: la parte de plata de la edición de la orden y,
     * si viene, la corrección de un abono.
     *
     * @return array{0: array, 1: array, 2: ?Pago}
     */
    private function leerPedido(Request $request, Orden $orden): array
    {
        $request->validate([
            'cambios_orden'      => 'nullable|array',
            'pago'               => 'nullable|array',
            'pago.id'            => 'required_with:pago|integer',
            'pago.monto'         => 'required_with:pago|numeric|min:0.01',
            'pago.metodo'        => 'nullable|in:efectivo,transferencia,tarjeta,addi,otro',
            'pago.referencia'    => 'nullable|string|max:100',
        ]);

        // Solo lo de plata: lo demás no necesita a nadie.
        $cambiosOrden = CambiosDePlata::soloPlata((array) $request->input('cambios_orden', []));

        $pago = null;
        $cambioPago = [];
        if ($request->filled('pago.id')) {
            $pago = Pago::where('orden_id', $orden->id)->findOrFail((int) $request->input('pago.id'));
            $cambioPago = [
                'pago_id'    => $pago->id,
                'monto'      => (float) $request->input('pago.monto'),
                'metodo'     => $request->input('pago.metodo') ?: $pago->metodo,
                'referencia' => $request->input('pago.referencia', $pago->referencia),
            ];
        }

        return [$cambiosOrden, $cambioPago, $pago];
    }

    private function resumen(Orden $orden, array $cambiosOrden, ?Pago $pago, array $cambioPago): array
    {
        return array_merge(
            $pago ? CambiosDePlata::dePago($pago, $cambioPago) : [],
            $cambiosOrden ? CambiosDePlata::deOrden($orden, $cambiosOrden) : [],
        );
    }

    /**
     * Aplica un cambio llamando al mismo método que usaría la pantalla, como
     * el supervisor que lo aprobó. Si responde con error, se lanza para que la
     * transacción se deshaga entera.
     */
    private function aplicar(callable $accion, string $uri, array $datos, Usuario $supervisor): void
    {
        $sub = Request::create($uri, 'PATCH', $datos);
        $sub->headers->set('Accept', 'application/json');
        $sub->setUserResolver(fn () => $supervisor);

        try {
            $respuesta = $accion($sub);
        } catch (ValidationException $e) {
            throw new \RuntimeException(collect($e->errors())->flatten()->first() ?? $e->getMessage());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Lo que la edición frena con abort() (sin stock, estado que no deja).
            throw new \RuntimeException($e->getMessage() ?: 'la orden no aceptó el cambio.');
        }

        if ($respuesta->getStatusCode() >= 400) {
            $cuerpo = json_decode($respuesta->getContent(), true);
            throw new \RuntimeException($cuerpo['message'] ?? 'la orden no aceptó el cambio.');
        }
    }
}
