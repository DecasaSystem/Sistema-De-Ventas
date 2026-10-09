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
     * Quién puede leer y escribir: quien puede editar la orden (el vendedor, su
     * covendedor, los compañeros de la tienda —la venta de la tienda es de la
     * tienda— y la tienda a la que se le abona), cualquier supervisor y la gente
     * de Producción (quien la gestiona o ve el tablero). Los que no fueron mencionados ven el hilo igual y
     * responden si quieren — no se les notifica, pero pueden intervenir.
     *
     * Antes la gente de Producción que no era supervisora no podía abrirlo (la
     * pantalla escondía el chat sin decir nada), y la comparación de ids era
     * estricta (int contra lo que viniera de la base): a unos les salía y a
     * otros no.
     */
    private function puedeParticipar(Usuario $u, Orden $orden): bool
    {
        return $u->rol === 'supervisor'
            || (bool) $u->gestiona_produccion
            || (bool) $u->acceso_produccion
            || (int) $u->id === (int) $orden->vendedor_id
            || (int) $u->id === (int) $orden->covendedor_id
            // Entre vendedores, la regla de siempre. Solo a ellos: para un
            // conductor o alguien del taller `laPuedeEditar` dice que sí a todo.
            || ($u->rol === 'vendedor' && $orden->laPuedeEditar($u));
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
            // Y quien gestiona Producción: es a quien se le pregunta por el taller.
            ->where(fn ($q) => $q->where('rol', 'supervisor')->orWhere('gestiona_produccion', true)
                ->orWhereIn('id', $idsOrden ?: [0]))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'rol'])
            ->map(fn ($u) => [
                'id'     => $u->id,
                'nombre' => $u->nombre,
                // Para que el supervisor distinga de un vistazo a quién le
                // está preguntando entre siete nombres iguales
                'es_de_la_orden' => in_array((int) $u->id, array_map('intval', $idsOrden), true),
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

        // Se pueden mandar solo fotos, sin escribir nada: a veces la duda se
        // resuelve mostrando y no hay más que decir. Varias en un mismo
        // mensaje (la tela, la veta y el golpe), solo por https.
        $data = $request->validate([
            'mensaje'         => 'required_without_all:imagen_url,imagenes|nullable|string|max:2000',
            'imagen_url'      => ['nullable', 'string', 'max:500', 'regex:#^https://#'],
            'imagenes'        => 'nullable|array|max:6',
            'imagenes.*'      => ['string', 'max:500', 'regex:#^https://#'],
            'mencionados'     => 'nullable|array|max:5',
            'mencionados.*'   => 'integer|exists:usuarios,id',
        ]);

        // Uno mismo no cuenta como mencionado aunque venga en la lista
        $mencionados = collect($data['mencionados'] ?? [])
            ->unique()->reject(fn ($uid) => (int) $uid === $usuario->id)
            ->values()->all();

        // La lista completa; la primera también en `imagen_url` (lo de antes).
        $imagenes = array_values(array_unique(array_filter($data['imagenes'] ?? [])));
        if (! $imagenes && ! empty($data['imagen_url'])) $imagenes = [$data['imagen_url']];

        $msg = OrdenMensaje::create([
            'orden_id'    => $id,
            'usuario_id'  => $usuario->id,
            'mensaje'     => trim($data['mensaje'] ?? ''),
            'imagen_url'  => $imagenes[0] ?? null,
            'mencionados' => $mencionados ?: null,
        ] + (count($imagenes) > 1 && self::hayColumnaImagenes() ? ['imagenes' => $imagenes] : []));

        $msg->load('usuario:id,nombre,rol');
        $payload = $this->formato($msg);

        // Tiempo real para quien tenga la orden abierta
        try {
            event(new OrdenMensajeEnviado($payload, $id));
        } catch (\Throwable) {}

        $this->notificar($orden, $usuario, $msg, $mencionados);

        return response()->json($payload, 201);
    }

    /**
     * A quién se le avisa de un mensaje nuevo.
     *
     * No basta con los mencionados. Un chat donde solo se entera el que fue
     * arrobado no funciona: el vendedor preguntaba @Admin, Admin respondía sin
     * arrobar a nadie, y el vendedor no se enteraba nunca de la respuesta.
     *
     * Entonces se avisa a:
     *   - los mencionados en ESTE mensaje (aviso directo: "te preguntaron"),
     *   - quien ya escribió antes en el hilo — se metió en la conversación,
     *   - el vendedor de la orden y su covendedor, que son los dueños del tema
     *     aunque todavía no hayan escrito.
     *
     * Menos el que escribe, claro. Y los supervisores que nunca han entrado
     * siguen sin ser molestados: ven el hilo si abren la orden.
     */
    private function notificar(Orden $orden, Usuario $autor, OrdenMensaje $msg, array $mencionados): void
    {
        $yaEscribieron = OrdenMensaje::where('orden_id', $orden->id)
            ->where('id', '!=', $msg->id)
            ->pluck('usuario_id')->all();

        $deLaOrden = array_filter([$orden->vendedor_id, $orden->covendedor_id]);

        $destinos = collect($mencionados)
            ->merge($yaEscribieron)
            ->merge($deLaOrden)
            ->map(fn ($x) => (int) $x)
            ->unique()
            ->reject(fn ($uid) => $uid === $autor->id)
            ->values();

        $ref     = $orden->referencia;
        $resumen = $msg->mensaje !== ''
            ? \Illuminate\Support\Str::limit($msg->mensaje, 120)
            : (count($msg->imagenes ?? []) > 1 ? 'te mandó ' . count($msg->imagenes) . ' fotos' : 'te mandó una foto');

        foreach ($destinos as $uid) {
            $preguntado = in_array($uid, $mencionados, true);

            NotificacionService::crear(
                'orden_mensaje',
                $preguntado
                    ? "Te preguntaron en la orden {$ref}"
                    : "Mensaje nuevo en la orden {$ref}",
                "{$autor->nombre}: {$resumen}",
                ['orden_id' => $orden->id],
                $uid,
            );
        }
    }

    private static ?bool $hayImagenes = null;

    /** Las pruebas montan el esquema a mano y casi ninguna tiene la columna. */
    private static function hayColumnaImagenes(): bool
    {
        return self::$hayImagenes ??= \Illuminate\Support\Facades\Schema::hasColumn('orden_mensajes', 'imagenes');
    }

    /** Ver CachesDePeticion. */
    public static function olvidarCache(): void
    {
        self::$hayImagenes = null;
    }

    private function formato(OrdenMensaje $m): array
    {
        return [
            'id'          => $m->id,
            'mensaje'     => $m->mensaje,
            'imagen_url'  => $m->imagen_url,
            // Todas las fotos del mensaje; los viejos traen solo `imagen_url`.
            'imagenes'    => $m->imagenes ?: ($m->imagen_url ? [$m->imagen_url] : []),
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
