<?php

namespace App\Http\Controllers;

use App\Events\AnexoFirmado;
use App\Mail\AnexoParaFirmarMail;
use App\Models\AnexoGarantia;
use App\Models\Cliente;
use App\Models\Orden;
use App\Services\Cloudinary;
use App\Services\NotificacionService;
use App\Support\AnexoGarantiaTexto;
use App\Support\ConvierteImagenesPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * El anexo de garantías, leído y firmado dentro del sistema.
 *
 * Dos maneras, un solo camino:
 *  - presencial: el cliente lo lee y firma en el teléfono o la tableta del
 *    vendedor, en la tienda;
 *  - remoto: se le manda un enlace (WhatsApp, correo) y lo firma desde el
 *    suyo, viendo antes el resumen de la orden.
 * En los dos se firma por la misma ruta pública (con el token), así que no
 * hay dos lógicas que se puedan separar con el tiempo.
 *
 * La orden todavía no existe cuando se firma: el anexo queda suelto y se
 * pega a la orden al crearla (OrdenController::store, `anexo_id`).
 */
class AnexoGarantiaController extends Controller
{
    use ConvierteImagenesPdf;

    /** Días que vive un enlace sin firmar. */
    private const DIAS_VIGENCIA = 7;

    /**
     * POST /api/anexos  { cliente_id, modo, resumen? }
     *
     * `resumen` (solo remoto) es lo que el cliente verá de la orden:
     * { items: [{nombre, detalle, cantidad, precio}], subtotal, descuentos,
     *   total, anticipo, fecha_entrega, tienda, lineas: [...], }
     * `lineas` son las mismas que viajarán al crear la orden: con ellas se
     * saca la huella para saber si la orden cambió después de firmar.
     */
    public function crear(Request $request)
    {
        $data = $request->validate([
            'cliente_id'       => 'required|integer|exists:clientes,id',
            'modo'             => 'required|in:presencial,remoto',
            'resumen'          => 'nullable|array',
            'resumen.lineas'   => 'nullable|array',
            ...self::reglasResumen(),
        ]);

        $resumen = $data['modo'] === 'remoto' ? ($data['resumen'] ?? null) : null;
        $huella  = $resumen && isset($resumen['lineas'])
            ? AnexoGarantia::huella($resumen['lineas'], (float) ($resumen['descuentos'] ?? 0))
            : null;
        // La foto de cada producto sale del catálogo (las líneas van en el
        // mismo orden que lo que ve el cliente).
        if ($resumen && isset($resumen['items'], $resumen['lineas'])) {
            $resumen['items'] = self::ponerFotos($resumen['items'], $resumen['lineas']);
        }
        // Las líneas solo sirven para la huella: el cliente no las necesita.
        if ($resumen) unset($resumen['lineas']);

        // Los enlaces anteriores de este vendedor a este cliente que nadie ha
        // firmado ni usado dejan de servir. Si no, al "Enviar de nuevo" el
        // cliente podía abrir el WhatsApp viejo y firmar la versión anterior
        // del pedido, y en la pantalla del vendedor nunca aparecía la firma.
        AnexoGarantia::where('vendedor_id', $request->user()->id)
            ->where('cliente_id', $data['cliente_id'])
            ->where('estado', 'pendiente')
            ->whereNull('orden_id')
            ->update(['estado' => 'anulado', 'updated_at' => now()]);

        $anexo = AnexoGarantia::create([
            'token'        => Str::random(48),
            'cliente_id'   => $data['cliente_id'],
            'vendedor_id'  => $request->user()->id,
            'version'      => AnexoGarantiaTexto::VERSION,
            'modo'         => $data['modo'],
            'estado'       => 'pendiente',
            'resumen'      => $resumen,
            'resumen_hash' => $huella,
            'vence_at'     => now()->addDays(self::DIAS_VIGENCIA),
        ]);

        return response()->json($this->paraVendedor($anexo, conToken: true, request: $request), 201);
    }

    /**
     * Lo que puede traer el resumen que ve el cliente: el pedido completo
     * (productos con su tela, especificaciones, foto y bocetos; la plata; la
     * entrega). Las imágenes solo por https: nada que no venga de Cloudinary.
     * Es lo que se le MUESTRA; la plata de verdad la cuida la huella.
     */
    private static function reglasResumen(): array
    {
        $https = ['nullable', 'string', 'max:500', 'regex:#^https://#'];

        return [
            'resumen.total'              => 'nullable|numeric',
            'resumen.subtotal'           => 'nullable|numeric',
            'resumen.descuentos'         => 'nullable|numeric|min:0',
            'resumen.descuento_efectivo' => 'nullable|numeric|min:0',
            'resumen.anticipo'           => 'nullable|numeric|min:0',
            'resumen.pagado'             => 'nullable|numeric',
            'resumen.saldo'              => 'nullable|numeric',
            'resumen.fecha_entrega'      => 'nullable|date',
            'resumen.tienda'             => 'nullable|string|max:150',
            'resumen.referencia'         => 'nullable|string|max:60',
            'resumen.envio'              => 'nullable|array',
            'resumen.envio.departamento' => 'nullable|string|max:100',
            'resumen.envio.ciudad'       => 'nullable|string|max:100',
            'resumen.envio.direccion'    => 'nullable|string|max:300',
            'resumen.items'              => 'nullable|array|max:100',
            'resumen.items.*.nombre'     => 'nullable|string|max:200',
            'resumen.items.*.detalle'    => 'nullable|string|max:300',
            'resumen.items.*.cantidad'   => 'nullable|numeric',
            'resumen.items.*.precio'     => 'nullable|numeric',
            'resumen.items.*.foto'       => $https,
            'resumen.items.*.bocetos'    => 'nullable|array|max:10',
            'resumen.items.*.bocetos.*'  => $https,
            'resumen.items.*.specs'      => 'nullable|array|max:40',
            'resumen.items.*.specs.*.label' => 'nullable|string|max:80',
            'resumen.items.*.specs.*.value' => 'nullable|string|max:500',
        ];
    }

    /**
     * POST /api/ordenes/{id}/firma-remota  { resumen }
     *
     * Mandarle a firmar una orden que YA existe y quedó sin firma —la que se
     * creó esperando el precio del taller, o la de una venta virtual que se
     * confirmó sin el cliente al lado—. Es el mismo enlace de siempre (orden +
     * anexo, una sola firma); al firmar, la firma queda en la orden.
     *
     * La huella sale de la orden guardada, no de lo que mande la pantalla: si
     * alguien la edita antes de que el cliente firme, el enlace ya no deja
     * firmar.
     */
    public function enviarOrden(Request $request, int $id)
    {
        $orden = Orden::with('items')->findOrFail($id);
        $u     = $request->user();

        if (! $orden->laPuedeEditar($u)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if (in_array($orden->estado, ['cancelado', 'borrador', 'cotizacion'], true)) {
            return response()->json(['message' => 'Esta orden no se puede mandar a firmar en su estado actual.'], 422);
        }
        if ($orden->firma_url) {
            return response()->json(['message' => 'Esta orden ya tiene la firma del cliente.'], 422);
        }
        if ($orden->items->filter->estaVivo()->contains(fn ($i) => $i->esperaCotizacion())) {
            return response()->json(['message' => 'Todavía hay productos sin precio. Cuando el taller los cotice, se manda a firmar con el precio final.'], 422);
        }
        if (! $orden->cliente_id) {
            return response()->json(['message' => 'La orden no tiene cliente.'], 422);
        }

        $data = $request->validate(['resumen' => 'required|array', ...self::reglasResumen()]);
        $resumen = $data['resumen'];
        unset($resumen['lineas']);
        $resumen['orden_existente'] = true;
        $resumen['referencia']      = $orden->referencia;
        if (isset($resumen['items'])) {
            $resumen['items'] = self::ponerFotos($resumen['items'], $orden->items->filter->estaVivo()->values()
                ->map(fn ($i) => ['producto_id' => $i->producto_id, 'variante_id' => $i->variante_id])->all());
        }

        // Un solo enlace vivo por orden.
        AnexoGarantia::where('orden_id', $orden->id)->where('estado', 'pendiente')
            ->update(['estado' => 'anulado', 'updated_at' => now()]);

        $anexo = AnexoGarantia::create([
            'token'        => Str::random(48),
            'orden_id'     => $orden->id,
            'cliente_id'   => $orden->cliente_id,
            'vendedor_id'  => $u->id,
            'version'      => AnexoGarantiaTexto::VERSION,
            'modo'         => 'remoto',
            'estado'       => 'pendiente',
            'resumen'      => $resumen,
            'resumen_hash' => self::huellaDeOrden($orden),
            'vence_at'     => now()->addDays(self::DIAS_VIGENCIA),
        ]);

        return response()->json($this->paraVendedor($anexo, conToken: true, request: $request), 201);
    }

    /**
     * GET /api/ordenes/{id}/firma-remota — el último enlace de firma de la
     * orden (esperando, firmado o vencido), o null si nunca se mandó.
     */
    public function deOrden(Request $request, int $id)
    {
        $orden = Orden::findOrFail($id);
        if (! $orden->laPuedeVer($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $anexo = AnexoGarantia::with('cliente:id,nombre,email,telefono')
            ->where('orden_id', $orden->id)->where('modo', 'remoto')
            ->where('estado', '!=', 'anulado')
            ->latest('id')->first();

        return response()->json($anexo ? $this->paraVendedor($anexo, conToken: $orden->laPuedeEditar($request->user()), request: $request) : null);
    }

    /**
     * Le pone a cada producto del resumen su foto del catálogo (la de la tela
     * si la tiene, si no la del producto), cuando la pantalla no mandó una.
     * Solo fotos por https.
     */
    private static function ponerFotos(array $items, array $lineas): array
    {
        $prodIds = collect($lineas)->pluck('producto_id')->filter()->unique()->all();
        $varIds  = collect($lineas)->pluck('variante_id')->filter()->unique()->all();
        $fotosP  = $prodIds ? \App\Models\Producto::whereIn('id', $prodIds)->pluck('foto_url', 'id') : collect();
        $fotosV  = $varIds ? \App\Models\ProductoVariante::whereIn('id', $varIds)->pluck('foto_url', 'id') : collect();

        foreach ($items as $k => $it) {
            if (! is_array($it) || ! empty($it['foto'])) continue;
            $l    = $lineas[$k] ?? [];
            $foto = ($fotosV[$l['variante_id'] ?? 0] ?? null) ?: ($fotosP[$l['producto_id'] ?? 0] ?? null);
            if ($foto && str_starts_with($foto, 'https://')) $items[$k]['foto'] = $foto;
        }

        return $items;
    }

    /** La huella de una orden guardada, con la misma cuenta que la de Nueva orden. */
    private static function huellaDeOrden(Orden $orden): string
    {
        $lineas = $orden->items->filter->estaVivo()->map(fn ($i) => [
            'producto_id'     => $i->producto_id,
            'nombre_custom'   => $i->producto_id ? null : $i->nombre_custom,
            'variante_id'     => $i->variante_id,
            'combo_config_id' => $i->combo_config_id,
            'cantidad'        => $i->cantidad,
            'precio_unitario' => $i->precio_unitario,
        ])->values()->all();

        return AnexoGarantia::huella($lineas, (float) $orden->descuento_total + (float) $orden->descuento_condicionado);
    }

    /** GET /api/anexos/{id} — el estado, para quien lo está esperando. */
    public function show(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);

        return response()->json($this->paraVendedor($anexo, conToken: true, request: $request));
    }

    /**
     * POST /api/anexos/{id}/anular — "Cancelar envío": el enlace deja de
     * servir. Solo lo que no se ha firmado; lo firmado es un documento.
     */
    public function anular(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);
        if ($anexo->estado === 'pendiente' && ! $anexo->orden_id) {
            $anexo->update(['estado' => 'anulado']);
        }

        return response()->json($this->paraVendedor($anexo->fresh(), request: $request));
    }

    /** POST /api/anexos/{id}/enviar-email  { email? } */
    public function enviarEmail(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);
        $data  = $request->validate(['email' => 'nullable|email|max:150']);

        $email = $data['email'] ?? $anexo->cliente?->email;
        if (! $email) {
            return response()->json(['message' => 'El cliente no tiene correo. Escríbelo o mándalo por WhatsApp.'], 422);
        }
        if ($anexo->estado !== 'pendiente') {
            return response()->json(['message' => 'Este anexo ya se firmó.'], 422);
        }

        try {
            Mail::to($email)->send(new AnexoParaFirmarMail($anexo->id, $this->enlace($anexo, $request)));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo enviar el correo. Prueba por WhatsApp.'], 502);
        }

        return response()->json(['message' => "Enviado a {$email}."]);
    }

    /** GET /api/anexos/{id}/pdf — el anexo firmado, como documento. */
    public function pdf(Request $request, int $id)
    {
        $anexo = AnexoGarantia::with('orden', 'cliente', 'vendedor:id,nombre')->findOrFail($id);
        if ($anexo->orden && ! $anexo->orden->laPuedeVer($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if (! $anexo->orden && ! $this->esSuyo($request, $anexo)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $firma = $this->urlToBase64($anexo->firma_url);
        $logo  = $this->avifToPngBase64(public_path('img/logo.avif'));
        $texto = AnexoGarantiaTexto::contenido();

        $pdf = Pdf::loadView('pdf.anexo_garantia', compact('anexo', 'firma', 'logo', 'texto'))->setPaper('letter');

        $nombre = 'anexo-garantias-' . ($anexo->orden?->referencia ?? $anexo->id) . '.pdf';
        return $pdf->stream(Str::slug(pathinfo($nombre, PATHINFO_FILENAME)) . '.pdf');
    }

    // ── Página pública (sin sesión) ──────────────────────────────────────────

    /** GET /api/public/anexos/{token} */
    public function publico(string $token)
    {
        $anexo = AnexoGarantia::with('cliente:id,nombre,cedula,telefono,email,direccion', 'vendedor:id,nombre')
            ->where('token', $token)->first();

        if (! $anexo || $anexo->estado === 'anulado') {
            return response()->json(['message' => 'Este enlace no existe o ya no está vigente.'], 404);
        }
        if ($anexo->vencido()) {
            return response()->json(['message' => 'Este enlace venció. Pídele a tu asesor que te envíe uno nuevo.'], 410);
        }

        return response()->json([
            'estado'     => $anexo->estado,
            'modo'       => $anexo->modo,
            'firmado_at' => $anexo->firmado_at,
            // Sus datos, para que vea que están bien antes de firmar (solo
            // quien tiene el enlace los ve).
            'cliente'    => [
                'nombre'    => $anexo->cliente?->nombre,
                'cedula'    => $anexo->cliente?->cedula,
                'telefono'  => $anexo->cliente?->telefono,
                'email'     => $anexo->cliente?->email,
                'direccion' => $anexo->cliente?->direccion,
            ],
            'vendedor'   => $anexo->vendedor?->nombre,
            'resumen'    => $anexo->resumen,
            'contenido'  => AnexoGarantiaTexto::contenido(),
        ]);
    }

    /**
     * POST /api/public/anexos/{token}/firmar
     * { secciones: [ids], checklist: {id: si|no}, nombre, documento, firma: dataURL png }
     */
    public function firmar(Request $request, string $token)
    {
        $anexo = AnexoGarantia::where('token', $token)->first();
        if (! $anexo || $anexo->estado === 'anulado') {
            return response()->json(['message' => 'Este enlace no existe o ya no está vigente.'], 404);
        }
        if ($anexo->estaFirmado()) {
            // Se le dio dos veces: ya quedó, no es un error para el cliente.
            return response()->json(['ok' => true, 'ya_firmado' => true]);
        }
        if ($anexo->vencido()) {
            return response()->json(['message' => 'Este enlace venció. Pídele a tu asesor que te envíe uno nuevo.'], 410);
        }

        $idsSecciones = array_column(AnexoGarantiaTexto::secciones(), 'id');
        $idsChecklist = array_column(AnexoGarantiaTexto::checklist(), 'id');

        $data = $request->validate([
            'secciones'   => 'required|array',
            'secciones.*' => 'string',
            'checklist'   => 'required|array',
            'checklist.*' => 'in:si,no',
            'nombre'      => 'required|string|max:150',
            'documento'   => 'required|string|max:30',
            'firma'       => 'required|string|max:1500000',
        ], [
            'nombre.required'    => 'Escribe el nombre de quien firma.',
            'documento.required' => 'Escribe el número de documento de quien firma.',
            'firma.required'     => 'Falta la firma.',
        ]);

        $faltan = array_diff($idsSecciones, $data['secciones']);
        if ($faltan) {
            return response()->json(['message' => 'Falta marcar como leídas ' . count($faltan) . ' sección(es) del anexo.'], 422);
        }
        $sinResponder = array_diff($idsChecklist, array_keys($data['checklist']));
        if ($sinResponder) {
            return response()->json(['message' => 'Responde todas las preguntas del check list.'], 422);
        }

        // Una orden que ya existe: si la editaron después de mandar el enlace,
        // lo que el cliente está viendo ya no es lo que va. No se firma.
        $orden = ($anexo->resumen['orden_existente'] ?? false) && $anexo->orden_id
            ? Orden::with('items')->find($anexo->orden_id) : null;
        if ($orden && ($orden->estado === 'cancelado' || ! hash_equals((string) $anexo->resumen_hash, self::huellaDeOrden($orden)))) {
            return response()->json(['message' => 'Tu pedido cambió después de que te enviaron este enlace. Pídele a tu asesor que te mande el nuevo.'], 409);
        }

        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data['firma'], $m)) {
            return response()->json(['message' => 'La firma no llegó bien. Vuelve a firmar.'], 422);
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) < 200 || ! str_starts_with($png, "\x89PNG")) {
            return response()->json(['message' => 'La firma no llegó bien. Vuelve a firmar.'], 422);
        }

        $url = Cloudinary::subirContenido($png, "anexo-{$anexo->id}.png", 'firmas');
        if (! $url) {
            return response()->json(['message' => 'No se pudo guardar la firma. Inténtalo otra vez en un momento.'], 502);
        }

        // Solo una firma gana: si dos pestañas firman a la vez, la segunda
        // no pisa a la primera.
        $actualizadas = AnexoGarantia::whereKey($anexo->id)->where('estado', 'pendiente')->update([
            'estado'             => 'firmado',
            'respuestas'         => [
                'secciones' => array_values(array_intersect($idsSecciones, $data['secciones'])),
                'checklist' => array_intersect_key($data['checklist'], array_flip($idsChecklist)),
            ],
            'nombre_firmante'    => trim($data['nombre']),
            'documento_firmante' => trim($data['documento']),
            'firma_url'          => $url,
            'firmado_at'         => now(),
            'ip'                 => $request->ip(),
            'user_agent'         => mb_substr((string) $request->userAgent(), 0, 300),
            'updated_at'         => now(),
        ]);
        if (! $actualizadas) {
            return response()->json(['ok' => true, 'ya_firmado' => true]);
        }

        // La orden que ya existía queda firmada: es la firma del cliente.
        if ($orden) {
            try {
                if (! $orden->firma_url) $orden->update(['firma_url' => $url]);
                \App\Models\OrdenMensaje::create([
                    'orden_id'   => $orden->id,
                    'usuario_id' => $anexo->vendedor_id,
                    'mensaje'    => "✍️ El cliente revisó la orden y la firmó a distancia (" . trim($data['nombre']) . ", doc. " . trim($data['documento']) . ").",
                    'imagen_url' => $url,
                ]);
                if ($orden->vendedor_id && (int) $orden->vendedor_id !== (int) $anexo->vendedor_id) {
                    NotificacionService::crear('anexo_firmado', 'El cliente firmó la orden',
                        "La orden {$orden->referencia} ya tiene la firma del cliente.", ['orden_id' => $orden->id], (int) $orden->vendedor_id);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Al vendedor que lo está esperando: en la pantalla al instante y,
        // si la cerró, como notificación en el teléfono. Que el aviso falle
        // no deshace la firma.
        try { event(new AnexoFirmado($anexo->id)); } catch (\Throwable) {}
        if ($anexo->modo === 'remoto' && $anexo->vendedor_id) {
            try {
                $cliente = Cliente::find($anexo->cliente_id)?->nombre ?? 'El cliente';
                NotificacionService::crear(
                    'anexo_firmado',
                    'El cliente firmó',
                    $orden
                        ? "{$cliente} revisó y firmó la orden {$orden->referencia}."
                        : "{$cliente} revisó su pedido y firmó. Ya puedes crear la orden.",
                    ['anexo_id' => $anexo->id] + ($orden ? ['orden_id' => $orden->id] : []),
                    $anexo->vendedor_id,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true]);
    }

    // ── Apoyo ─────────────────────────────────────────────────────────────────

    private function esSuyo(Request $request, AnexoGarantia $anexo): bool
    {
        $u = $request->user();
        if ((int) $anexo->vendedor_id === (int) $u->id || $u->rol === 'supervisor') return true;

        // El de una orden lo sigue cualquiera que pueda editarla: la venta de
        // la tienda es de la tienda.
        return $anexo->orden_id && ($o = Orden::find($anexo->orden_id)) && $o->laPuedeEditar($u);
    }

    private function delVendedor(Request $request, int $id): AnexoGarantia
    {
        $anexo = AnexoGarantia::with('cliente:id,nombre,email,telefono')->findOrFail($id);
        abort_unless($this->esSuyo($request, $anexo), 403, 'No autorizado.');

        return $anexo;
    }

    private function paraVendedor(AnexoGarantia $anexo, bool $conToken = false, ?Request $request = null): array
    {
        return [
            'id'                 => $anexo->id,
            'modo'               => $anexo->modo,
            'estado'             => $anexo->vencido() ? 'vencido' : $anexo->estado,
            'resumen_hash'       => $anexo->resumen_hash,
            'firma_url'          => $anexo->firma_url,
            'nombre_firmante'    => $anexo->nombre_firmante,
            'documento_firmante' => $anexo->documento_firmante,
            'firmado_at'         => $anexo->firmado_at,
            'orden_id'           => $anexo->orden_id,
            // Las preguntas del check list que el cliente respondió "No" (no
            // le quedó claro el proceso, no le explicaron la espuma...). Firmar
            // se puede igual, pero el vendedor tiene que saberlo para
            // explicarle antes de cerrar la venta.
            'respuestas_no'      => collect(AnexoGarantiaTexto::checklist())
                ->filter(fn ($q) => ($anexo->respuestas['checklist'][$q['id']] ?? null) === 'no')
                ->pluck('pregunta')->values(),
            ...($conToken ? [
                'token' => $anexo->token,
                'url'   => $this->enlace($anexo, $request),
            ] : []),
        ];
    }

    /**
     * El enlace para el cliente. La dirección de la app se toma de la
     * configuración y, si no está, de la página desde donde se pidió.
     */
    private function enlace(AnexoGarantia $anexo, ?Request $request): string
    {
        $base = config('app.frontend_url')
            ?: ($request?->headers->get('origin') ?: config('app.url'));

        return rtrim((string) $base, '/') . '/firmar/' . $anexo->token;
    }
}
