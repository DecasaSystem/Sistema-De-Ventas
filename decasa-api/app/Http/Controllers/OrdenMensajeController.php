<?php

namespace App\Http\Controllers;

use App\Events\OrdenMensajeEnviado;
use App\Models\Orden;
use App\Models\OrdenMensaje;
use App\Models\Usuario;
use App\Models\Notificacion;
use App\Services\NotificacionService;
use App\Support\TiposNotificacion;
use Illuminate\Http\Request;

/**
 * Chat de una orden: dudas entre el vendedor y los supervisores.
 */
class OrdenMensajeController extends Controller
{
    /**
     * El chat solo se cierra cuando la orden se cancela; ahí queda de consulta.
     *
     * Antes también se cerraba al quedar lista para entregar y al entregarse,
     * pero después de entregar sigue habiendo qué hablar: una garantía, un
     * cambio de producto, un saldo a favor, coordinar la entrega (usuario,
     * 2026-11-25: "no cerrarlo después de entregar").
     */
    private const ESTADOS_CERRADOS = ['cancelado'];

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
    public static function puedeParticipar(Usuario $u, Orden $orden): bool
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
        $idsOrden = array_map('intval', array_filter([$orden->vendedor_id, $orden->covendedor_id]));

        // La venta no es solo de quien la subió: también de la tienda con la
        // que se compartió (pedido del usuario, 2026-11-25). Un independiente
        // la comparte con El Edén: el supervisor arroba al independiente y a
        // los de El Edén; los de la tienda de la orden también salen.
        $tiendaOrden    = (int) $orden->tienda_id;
        $tiendaAbonada  = (int) $orden->tienda_abonada_id;
        $tiendas        = array_values(array_filter(array_unique([$tiendaOrden, $tiendaAbonada])));

        $nombresTienda = $tiendas
            ? \App\Models\Tienda::whereIn('id', $tiendas)->pluck('nombre', 'id')->all()
            : [];

        return Usuario::where('activo', true)
            ->where('id', '!=', $usuario->id)
            // Y quien gestiona Producción: es a quien se le pregunta por el taller.
            ->where(function ($q) use ($idsOrden, $tiendas) {
                $q->where('rol', 'supervisor')->orWhere('gestiona_produccion', true)
                    ->orWhereIn('id', $idsOrden ?: [0]);
                if ($tiendas) {
                    $q->orWhere(fn ($v) => $v->where('rol', 'vendedor')->whereIn('tienda_default_id', $tiendas));
                }
            })
            ->orderBy('nombre')
            ->get()
            // Solo a quien de verdad puede abrir el chat hoy: si no, le llega
            // el aviso con el texto y al tocarlo no puede entrar.
            ->filter(fn ($u) => self::puedeParticipar($u, $orden))
            ->map(function ($u) use ($orden, $idsOrden, $tiendaOrden, $tiendaAbonada, $nombresTienda) {
                $esDeLaOrden = in_array((int) $u->id, $idsOrden, true);
                $tienda = (int) $u->tienda_default_id;
                // De qué tienda sale en la lista (los de la orden y los
                // supervisores, de ninguna: van aparte).
                $grupo = null;
                if (! $esDeLaOrden && $u->rol === 'vendedor') {
                    if ($tiendaAbonada && $tienda === $tiendaAbonada && $tiendaAbonada !== $tiendaOrden) $grupo = 'compartida';
                    elseif ($tiendaOrden && $tienda === $tiendaOrden) $grupo = 'tienda';
                }

                return [
                    'id'     => $u->id,
                    'nombre' => $u->nombre,
                    // Para que el supervisor distinga de un vistazo a quién le
                    // está preguntando entre siete nombres iguales
                    'es_de_la_orden' => $esDeLaOrden,
                    'es_covendedor'  => (int) $u->id === (int) $orden->covendedor_id,
                    'grupo'          => $grupo,
                    'tienda'         => $grupo ? ($nombresTienda[$tienda] ?? null) : null,
                ];
            })
            ->values();
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
        $orden   = Orden::findOrFail($id);

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
            // Hasta 15: con "toda la tienda" + el independiente + un supervisor
            // se pasaba de los 5 de antes.
            'mencionados'     => 'nullable|array|max:15',
            'mencionados.*'   => 'integer',
            'clave'           => 'nullable|string|max:64',
        ]);

        // El mismo envío otra vez (la señal se cortó después de guardarlo y la
        // pantalla reintentó): se devuelve el que ya está, sin avisar de nuevo.
        $clave = $data['clave'] ?? null;
        if ($clave && OrdenMensaje::hayColumnasEdicion()) {
            $ya = OrdenMensaje::with('usuario:id,nombre,rol')
                ->where('orden_id', $id)->where('usuario_id', $usuario->id)
                ->where('clave_envio', $clave)->first();
            if ($ya) return response()->json($this->formato($ya), 200);
        }

        // Uno mismo no cuenta como mencionado aunque venga en la lista
        // En enteros: si llegan como texto ("5"), el aviso decía "Mensaje nuevo"
        // en vez de "Te preguntaron" (la comparación de abajo es estricta).
        // Y solo los de la lista de la pantalla: antes la API dejaba arrobar a
        // cualquier usuario del sistema y le llegaba el texto del mensaje.
        $mencionados = collect($data['mencionados'] ?? [])
            ->map(fn ($uid) => (int) $uid)
            ->unique()->reject(fn ($uid) => $uid === (int) $usuario->id)
            ->values()->all();
        if ($mencionados) {
            $permitidos  = $this->destinatarios($usuario, $orden)->pluck('id')->map(fn ($x) => (int) $x)->all();
            $mencionados = array_values(array_intersect($mencionados, $permitidos));
        }

        // La lista completa; la primera también en `imagen_url` (lo de antes).
        $imagenes = array_values(array_unique(array_filter($data['imagenes'] ?? [])));
        if (! $imagenes && ! empty($data['imagen_url'])) $imagenes = [$data['imagen_url']];

        $msg = OrdenMensaje::create([
            'orden_id'    => $id,
            'usuario_id'  => $usuario->id,
            'mensaje'     => trim($data['mensaje'] ?? ''),
            'imagen_url'  => $imagenes[0] ?? null,
            'mencionados' => $mencionados ?: null,
        ] + (count($imagenes) > 1 && self::hayColumnaImagenes() ? ['imagenes' => $imagenes] : [])
          + ($clave && OrdenMensaje::hayColumnasEdicion() ? ['clave_envio' => $clave] : []));

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
     * PATCH /api/ordenes/{id}/mensajes/{mensajeId}
     *
     * El autor corrige el texto de su mensaje (usuario, 2026-11-25). Queda
     * marcado "editado" con la fecha y la hora para que se sepa, y lo que decía
     * antes se guarda en `ediciones` (el rastro no se pierde). No se vuelve a
     * avisar a nadie: el aviso que ya salió se queda como estaba.
     */
    public function update(Request $request, int $id, int $mensajeId)
    {
        [$orden, $msg, $error] = $this->suyoParaCambiar($request, $id, $mensajeId);
        if ($error) return $error;

        $data  = $request->validate(['mensaje' => 'nullable|string|max:2000']);
        $nuevo = trim($data['mensaje'] ?? '');

        // Sin texto solo vale si tiene fotos; si no, es borrarlo (otro botón).
        $tieneFotos = (bool) ($msg->imagenes ?: $msg->imagen_url);
        if ($nuevo === '' && ! $tieneFotos) {
            return response()->json(['message' => 'El mensaje no puede quedar vacío. Si quieres quitarlo, elimínalo.'], 422);
        }
        if ($nuevo === $msg->mensaje) return response()->json($this->formato($msg));

        $msg->update([
            'ediciones'  => [...($msg->ediciones ?? []), ['mensaje' => $msg->mensaje, 'hasta' => now()->toIso8601String()]],
            'mensaje'    => $nuevo,
            'editado_at' => now(),
        ]);

        return $this->avisarCambio($msg, $id);
    }

    /**
     * DELETE /api/ordenes/{id}/mensajes/{mensajeId}
     *
     * El autor borra su mensaje. No desaparece: queda "Mensaje eliminado" con
     * la fecha y la hora, y lo que decía (texto y fotos) se guarda en
     * `ediciones`. Los avisos que ya salieron no se borran (los avisos solo
     * los borra la persona que los recibió).
     */
    public function destroy(Request $request, int $id, int $mensajeId)
    {
        [$orden, $msg, $error] = $this->suyoParaCambiar($request, $id, $mensajeId);
        if ($error) return $error;

        $msg->update([
            'ediciones'    => [...($msg->ediciones ?? []), [
                'mensaje'  => $msg->mensaje,
                'imagenes' => $msg->imagenes ?: ($msg->imagen_url ? [$msg->imagen_url] : []),
                'hasta'    => now()->toIso8601String(),
                'eliminado' => true,
            ]],
            'mensaje'      => '',
            'imagen_url'   => null,
            'eliminado_at' => now(),
        ] + (self::hayColumnaImagenes() ? ['imagenes' => null] : []));

        return $this->avisarCambio($msg, $id);
    }

    /**
     * Las reglas para corregir o borrar: solo el autor, solo lo que escribió
     * una persona (las notas del sistema no), con el chat abierto y si todavía
     * puede participar. Lo ya borrado no se vuelve a tocar.
     *
     * @return array{0: ?Orden, 1: ?OrdenMensaje, 2: ?\Illuminate\Http\JsonResponse}
     */
    private function suyoParaCambiar(Request $request, int $id, int $mensajeId): array
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);
        $msg     = OrdenMensaje::where('orden_id', $id)->findOrFail($mensajeId);
        $no      = fn (string $m, int $s = 422) => [null, null, response()->json(['message' => $m], $s)];

        if (! OrdenMensaje::hayColumnasEdicion())            return $no('Todavía no se pueden editar mensajes.');
        if (! $this->puedeParticipar($usuario, $orden))     return $no('No autorizado.', 403);
        if ((int) $msg->usuario_id !== (int) $usuario->id)  return $no('Solo quien lo escribió puede cambiarlo.', 403);
        if ($msg->es_sistema)                                return $no('Los avisos del sistema no se cambian.', 403);
        if ($msg->eliminado_at)                              return $no('Este mensaje ya se eliminó.');
        if (in_array($orden->estado, self::ESTADOS_CERRADOS, true)) return $no('El chat de esta orden ya se cerró.');

        return [$orden, $msg, null];
    }

    private function avisarCambio(OrdenMensaje $msg, int $ordenId)
    {
        $msg->load('usuario:id,nombre,rol');
        $payload = $this->formato($msg);
        try {
            event(new OrdenMensajeEnviado($payload, $ordenId, editado: true));
        } catch (\Throwable) {}

        return response()->json($payload);
    }

    /**
     * GET /api/mensajes-de-ordenes/sin-leer
     *
     * Cuántos mensajes del chat tiene sin leer cada orden, para marcarlas en la
     * lista de Órdenes: { "123": { "total": 2, "mencion": true } }. Sale de sus
     * propios avisos sin leer (los mismos que la campana y que "visto" marca),
     * así las dos cosas siempre dicen lo mismo. Solo lee.
     */
    public function sinLeer(Request $request)
    {
        $porOrden = [];
        Notificacion::where('usuario_id', $request->user()->id)
            ->where('tipo', 'orden_mensaje')
            ->where('leida', false)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['datos'])
            ->each(function ($n) use (&$porOrden) {
                $oid = (int) ($n->datos['orden_id'] ?? 0);
                if (! $oid) return;
                $porOrden[$oid] ??= ['total' => 0, 'mencion' => false];
                $porOrden[$oid]['total']++;
                if (! empty($n->datos['mencion'])) $porOrden[$oid]['mencion'] = true;
            });

        return response()->json(['ordenes' => (object) $porOrden]);
    }

    /**
     * POST /api/ordenes/{id}/mensajes/visto
     *
     * La persona tiene el chat de la orden a la vista: sus avisos de este chat
     * quedan leídos. Así la urgente ("te preguntaron") sale de arriba de la
     * campana cuando de verdad la vio, sin tener que abrirla también desde ahí.
     * Solo marca leídos; no borra nada (los avisos solo los borra la persona).
     */
    public function visto(Request $request, int $id)
    {
        $usuario = $request->user();
        $orden   = Orden::findOrFail($id);

        if (! $this->puedeParticipar($usuario, $orden)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $ids = Notificacion::where('usuario_id', $usuario->id)
            ->where('tipo', 'orden_mensaje')
            ->where('leida', false)
            ->get(['id', 'datos'])
            ->filter(fn ($n) => (int) ($n->datos['orden_id'] ?? 0) === $id)
            ->pluck('id')->all();

        if ($ids) Notificacion::whereIn('id', $ids)->update(['leida' => true]);

        return response()->json(['leidas' => $ids]);
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
     *   - a quien ya le preguntaron antes en el hilo, aunque no haya contestado:
     *     el vendedor arrobaba a Admin, Admin no respondía, el vendedor volvía a
     *     escribir "¿?" sin arrobar y a Admin ya no le llegaba nada,
     *   - el vendedor de la orden y su covendedor, que son los dueños del tema
     *     aunque todavía no hayan escrito.
     *
     * Menos el que escribe, claro, y las cuentas desactivadas. Los supervisores
     * que nunca han entrado siguen sin ser molestados: ven el hilo si abren la orden.
     */
    private function notificar(Orden $orden, Usuario $autor, OrdenMensaje $msg, array $mencionados): void
    {
        // Las notas automáticas no cuentan: quien canceló un producto o resolvió
        // una garantía no se metió en la conversación por eso.
        $anteriores = OrdenMensaje::where('orden_id', $orden->id)
            ->where('id', '!=', $msg->id)
            ->when(OrdenMensaje::hayColumnaSistema(), fn ($q) => $q->where('es_sistema', false))
            ->get(['usuario_id', 'mencionados']);

        $yaEscribieron     = $anteriores->pluck('usuario_id')->all();
        $yaLesPreguntaron  = $anteriores->pluck('mencionados')->flatten()->filter()->all();

        $deLaOrden = array_filter([$orden->vendedor_id, $orden->covendedor_id]);

        $destinos = collect($mencionados)
            ->merge($yaEscribieron)
            ->merge($yaLesPreguntaron)
            ->merge($deLaOrden)
            ->map(fn ($x) => (int) $x)
            ->unique()
            ->reject(fn ($uid) => $uid === (int) $autor->id)
            ->values();

        // Una sola consulta para saber quién sigue activo (cada una son ~200 ms)
        // y quién todavía puede abrir el chat: el vendedor que se cambió de
        // tienda, o a quien le quitaron Producción, ya no recibe el texto.
        $activos = $destinos->isEmpty() ? [] : Usuario::whereIn('id', $destinos)
            ->where('activo', true)->get()
            ->filter(fn ($u) => self::puedeParticipar($u, $orden))
            ->pluck('id')->map(fn ($x) => (int) $x)->all();
        $destinos = $destinos->filter(fn ($uid) => in_array($uid, $activos, true))->values();

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
                ['orden_id' => $orden->id, 'mensaje_id' => $msg->id] + ($preguntado ? ['mencion' => true] : []),
                $uid,
                // Que lo arroben es urgente (pedido del usuario, 2026-11-17): rojo
                // y fijado en la campana hasta que lo ve. Cada uno lo puede quitar
                // desde su perfil. Si escogió todos los chats como urgentes, eso
                // lo decide NotificacionService con el grupo.
                urgente: $preguntado && TiposNotificacion::mencionEsUrgente($uid),
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
        return $m->aPantalla();
    }
}
