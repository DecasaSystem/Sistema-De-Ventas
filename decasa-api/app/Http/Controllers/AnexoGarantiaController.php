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
            'resumen.total'      => 'nullable|numeric',
            'resumen.descuentos' => 'nullable|numeric|min:0',
            'resumen.lineas'   => 'nullable|array',
            'resumen.items'    => 'nullable|array|max:100',
        ]);

        $resumen = $data['modo'] === 'remoto' ? ($data['resumen'] ?? null) : null;
        $huella  = $resumen && isset($resumen['lineas'])
            ? AnexoGarantia::huella($resumen['lineas'], (float) ($resumen['descuentos'] ?? 0))
            : null;
        // Las líneas solo sirven para la huella: el cliente no las necesita.
        if ($resumen) unset($resumen['lineas']);

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

    /** GET /api/anexos/{id} — el estado, para quien lo está esperando. */
    public function show(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);

        return response()->json($this->paraVendedor($anexo, conToken: true, request: $request));
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
        $anexo = AnexoGarantia::with('cliente:id,nombre,cedula', 'vendedor:id,nombre')
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
            'cliente'    => [
                'nombre' => $anexo->cliente?->nombre,
                'cedula' => $anexo->cliente?->cedula,
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
                    "{$cliente} leyó y firmó el anexo de garantías. Ya puedes crear la orden.",
                    ['anexo_id' => $anexo->id],
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
        return (int) $anexo->vendedor_id === (int) $u->id || $u->rol === 'supervisor';
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
