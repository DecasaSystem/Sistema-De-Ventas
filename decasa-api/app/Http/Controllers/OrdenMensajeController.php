<?php

namespace App\Http\Controllers;

use App\Events\OrdenMensajeEnviado;
use App\Models\Orden;
use App\Models\OrdenMensaje;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;

/**
 * Chat de una orden: dudas entre el vendedor y los supervisores.
 */
class OrdenMensajeController extends Controller
{
    /**
     * El chat se cierra cuando la orden ya no tiene nada que resolver: cuando
     * el mueble está listo para entregar, ya se entregó, o se canceló. Después
     * de eso la conversación queda de consulta, pero no se escribe más.
     */
    private const ESTADOS_CERRADOS = ['listo_entrega', 'entregado', 'cancelado'];

    /**
     * Quién puede leer y escribir: el vendedor de la orden (y su covendedor) y
     * cualquier supervisor. Los supervisores que no fueron mencionados ven el
     * hilo igual y responden si quieren — no se les notifica, pero pueden
     * intervenir.
     */
    private function puedeParticipar(Usuario $u, Orden $orden): bool
    {
        return $u->rol === 'supervisor'
            || $u->id === $orden->vendedor_id
            || $u->id === $orden->covendedor_id;
    }

    /**
     * A quién se le puede preguntar en esta orden.
     *
     * Los supervisores, más el vendedor que la hizo (y su covendedor): la
     * conversación también la puede arrancar un supervisor, y a quien le tiene
     * que preguntar es justamente a quien hizo la venta. Se excluye uno mismo,
     * que mencionarse para notificarse solo no tiene sentido.
     */
    private function destinatarios(Usuario $usuario, Orden $orden)
    {
        $idsOrden = array_filter([$orden->vendedor_id, $orden->covendedor_id]);

        return Usuario::where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->where(fn ($q) => $q->where('rol', 'supervisor')->orWhereIn('id', $idsOrden ?: [0]))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'rol'])
            ->map(fn ($u) => [
                'id'     => $u->id,
                'nombre' => $u->nombre,
                // Para que el supervisor distinga de un vistazo a quién le
                // está preguntando entre siete nombres iguales
                'es_de_la_orden' => in_array($u->id, $idsOrden, true),
            ]);
    }

    /** GET /api/ordenes/{id}/mensajes */
    public function index(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);

        if (! $this->puedeParticipar($usuario, $orden)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $mensajes = OrdenMensaje::with('usuario:id,nombre,rol')
            ->where('orden_id', $id)
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => $this->formato($m));

        return response()->json([
            'mensajes' => $mensajes,
            'abierto'  => ! in_array($orden->estado, self::ESTADOS_CERRADOS, true),
            'estado'   => $orden->estado,
            'destinatarios' => $this->destinatarios($usuario, $orden),
        ]);
    }

    /** POST /api/ordenes/{id}/mensajes */
    public function store(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::with('cliente:id,nombre')->findOrFail($id);

        if (! $this->puedeParticipar($usuario, $orden)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if (in_array($orden->estado, self::ESTADOS_CERRADOS, true)) {
            return response()->json([
                'message' => 'El chat de esta orden ya se cerró.',
            ], 422);
        }

        // Se puede mandar solo una foto, sin escribir nada: a veces la duda se
        // resuelve mostrando y no hay más que decir.
        $data = $request->validate([
            'mensaje'         => 'required_without:imagen_url|nullable|string|max:2000',
            'imagen_url'      => 'nullable|string|max:500',
            'mencionados'     => 'nullable|array|max:5',
            'mencionados.*'   => 'integer|exists:usuarios,id',
        ]);

        // Uno mismo no cuenta como mencionado aunque venga en la lista
        $mencionados = collect($data['mencionados'] ?? [])
            ->unique()->reject(fn ($uid) => (int) $uid === $usuario->id)
            ->values()->all();

        $msg = OrdenMensaje::create([
            'orden_id'    => $id,
            'usuario_id'  => $usuario->id,
            'mensaje'     => trim($data['mensaje'] ?? ''),
            'imagen_url'  => $data['imagen_url'] ?? null,
            'mencionados' => $mencionados ?: null,
        ]);

        $msg->load('usuario:id,nombre,rol');
        $payload = $this->formato($msg);

        // Tiempo real para quien tenga la orden abierta
        try {
            event(new OrdenMensajeEnviado($payload, $id));
        } catch (\Throwable) {}

        // Notificación SOLO a los mencionados: los demás ven el hilo si entran,
        // pero no se les interrumpe por una conversación que no les toca.
        $ref = $orden->referencia;
        foreach ($mencionados as $uid) {
            NotificacionService::crear(
                'orden_mensaje',
                "Te preguntaron en la orden {$ref}",
                "{$usuario->nombre}: " . ($msg->mensaje !== ''
                    ? \Illuminate\Support\Str::limit($msg->mensaje, 120)
                    : 'te mandó una foto'),
                ['orden_id' => $id],
                (int) $uid,
            );
        }

        return response()->json($payload, 201);
    }

    private function formato(OrdenMensaje $m): array
    {
        return [
            'id'          => $m->id,
            'mensaje'     => $m->mensaje,
            'imagen_url'  => $m->imagen_url,
            'mencionados' => $m->mencionados ?? [],
            'created_at'  => $m->created_at,
            'usuario'     => [
                'id'     => $m->usuario?->id,
                'nombre' => $m->usuario?->nombre,
                'rol'    => $m->usuario?->rol,
            ],
        ];
    }
}
