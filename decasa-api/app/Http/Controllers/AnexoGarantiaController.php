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
use App\Support\DeclaracionEspeciales;
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

    /**
     * Días que vive un enlace sin firmar. Eran 7 y el firmado se retomaba solo
     * 3 días (con el formulario de Nueva orden, que vence a las 48 h): un
     * cliente que se demoraba dejaba al vendedor sin con qué crear la orden
     * (queja del usuario, 2026-10-28). Ahora la orden viaja con el enlace
     * (OrdenDelAnexo) y se le da un mes; si en ese tiempo cambió el stock o el
     * precio, crear la orden lo dice como siempre.
     */
    private const DIAS_VIGENCIA = 30;

    /** Días que un anexo firmado y sin orden se retoma en Nueva orden (y se puede crear). */
    private const DIAS_FIRMADO_EN_CURSO = 30;

    /** Una orden de Nueva orden pesa pocos KB; esto frena lo que no es una orden. */
    private const MAX_BYTES_ORDEN = 300_000;

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
            // Los productos especiales que el cliente aprueba en la
            // declaración (en la tienda y a distancia). Ver DeclaracionEspeciales.
            'especiales'       => 'nullable|array|max:50',
            // Solo remoto: la orden completa, tal como se mandaría a POST
            // /ordenes, para crearla cuando el cliente firme (sola o a mano),
            // aunque el formulario de Nueva orden ya no esté. Ver OrdenDelAnexo.
            'orden'            => 'nullable|array',
            'orden.items'      => 'required_with:orden|array|min:1',
            'crear_al_firmar'  => 'nullable|boolean',
        ]);

        [$especiales, $error] = DeclaracionEspeciales::normalizarProductos($request->input('especiales'));
        if ($error) {
            return response()->json(['message' => $error, 'codigo' => 'ficha_incompleta'], 422);
        }

        $resumen = $data['modo'] === 'remoto' ? ($data['resumen'] ?? null) : null;
        $huella  = $resumen && isset($resumen['lineas'])
            ? AnexoGarantia::huella($resumen['lineas'], (float) ($resumen['descuentos'] ?? 0))
            : null;

        // La orden entera, no lo validado: validate() con la regla de
        // `orden.items` la recorta a solo los productos (y se perdían la
        // tienda, el canal, el anticipo...). La revisa store() al crearla.
        $data['orden'] = is_array($request->input('orden')) ? $request->input('orden') : null;
        [$orden, $error] = self::ordenParaGuardar($data, $resumen, $huella);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }
        $crearAlFirmar = $orden && $request->boolean('crear_al_firmar');
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
        // Lo mismo con uno ya firmado cuya orden no se ha creado: si se manda
        // otra versión, la vieja ya no se crea (saldría la orden equivocada).
        // La firma queda como documento.
        if ($orden) {
            AnexoGarantia::where('vendedor_id', $request->user()->id)
                ->where('cliente_id', $data['cliente_id'])
                ->where('estado', 'firmado')->whereNull('orden_id')->whereNotNull('orden_pendiente')
                ->update(['orden_auto_estado' => 'descartada', 'updated_at' => now()]);
        }

        $anexo = AnexoGarantia::create([
            'token'        => Str::random(48),
            'cliente_id'   => $data['cliente_id'],
            'vendedor_id'  => $request->user()->id,
            'version'      => AnexoGarantiaTexto::vigente()['version'],
            'modo'         => $data['modo'],
            'estado'       => 'pendiente',
            'resumen'      => $resumen,
            'resumen_hash' => $huella,
            'vence_at'     => now()->addDays(self::DIAS_VIGENCIA),
            ...self::conDeclaracion($especiales),
            ...($orden ? ['orden_pendiente' => $orden, 'crear_al_firmar' => $crearAlFirmar] : []),
        ]);

        return response()->json($this->paraVendedor($anexo, conToken: true, request: $request), 201);
    }

    /**
     * La orden que viaja con el enlace, revisada antes de guardarla: que sea
     * la misma que el cliente va a ver (la huella de sus productos y precios
     * cuadra con la del resumen) y que se pueda crear sin una persona al lado.
     * Lo demás (stock, cliente, pagos) lo revisa OrdenController::store al
     * crearla, como siempre.
     *
     * @return array{0: ?array, 1: ?string}  [orden, error]
     */
    private static function ordenParaGuardar(array $data, ?array $resumen, ?string $huella): array
    {
        $orden = $data['orden'] ?? null;
        if (! $orden) {
            return [null, ! empty($data['crear_al_firmar']) ? 'Para crear la orden sola al firmar hay que mandar la orden completa.' : null];
        }
        if ($data['modo'] !== 'remoto' || ! $resumen || ! AnexoGarantia::guardaOrden()) {
            return [null, null];
        }
        if (strlen((string) json_encode($orden)) > self::MAX_BYTES_ORDEN) {
            return [null, 'La orden es demasiado grande para mandarla con el enlace.'];
        }
        // Lo que se va a crear es lo que el cliente ve y firma: si no cuadran,
        // la pantalla armó una cosa y mostró otra.
        if ($huella && ! hash_equals($huella, AnexoGarantia::huella($orden['items'], (float) ($resumen['descuentos'] ?? 0)))) {
            return [null, 'La orden no cuadra con lo que verá el cliente. Recarga la página y vuelve a enviarla.'];
        }
        // Lo que espera el precio del taller necesita que alguien pida la
        // consulta de costo al crearla: eso lo hace la pantalla, no se crea sola.
        if (! empty($data['crear_al_firmar']) && ! empty($resumen['precio_pendiente'])) {
            return [null, 'Con productos de precio por consultar la orden no se crea sola: espera la firma y créala tú.'];
        }
        unset($orden['guardar_borrador'], $orden['firma_url'], $orden['anexo_id']);

        return [$orden, null];
    }

    /**
     * Las columnas de la declaración de diseños especiales para un anexo
     * nuevo: el texto vigente, los productos tal como el cliente los va a ver
     * (congelados) y su huella. Sin productos especiales, nada.
     */
    private static function conDeclaracion(array $especiales): array
    {
        if (! $especiales || ! DeclaracionEspeciales::hayColumnas()) return [];

        return [
            'declaracion_version' => DeclaracionEspeciales::vigente()['version'],
            'especiales'          => $especiales,
            'especiales_hash'     => DeclaracionEspeciales::huella($especiales),
        ];
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
            'resumen.items.*.precio_lista'  => 'nullable|numeric',
            'resumen.items.*.tipo'       => 'nullable|string|max:120',
            'resumen.items.*.juego'      => 'nullable|string|max:120',
            'resumen.items.*.fecha_entrega' => 'nullable|date',
            'resumen.items.*.se_lo_lleva' => 'nullable|boolean',
            'resumen.items.*.obsequio'   => 'nullable|boolean',
            'resumen.items.*.entregado'  => 'nullable|string|max:60',
            'resumen.precio_pendiente'   => 'nullable|boolean',
            // Lo demás de la orden: sus notas y cómo se paga el anticipo.
            'resumen.notas'              => 'nullable|string|max:1000',
            'resumen.abonos'             => 'nullable|array|max:5',
            'resumen.abonos.*.monto'     => 'nullable|numeric|min:0',
            'resumen.abonos.*.metodo'    => 'nullable|string|max:40',
            'resumen.items.*.foto'       => $https,
            'resumen.items.*.bocetos'    => 'nullable|array|max:10',
            'resumen.items.*.bocetos.*'  => $https,
            'resumen.items.*.specs'      => 'nullable|array|max:40',
            'resumen.items.*.specs.*.label' => 'nullable|string|max:80',
            // La orden guarda las especificaciones sin tope (las notas de un
            // personalizado o la descripción de un diseño especial pasan de
            // 500 letras): con 500 aquí, Nueva orden no dejaba mandar a firmar
            // una orden que sí se podía crear.
            'resumen.items.*.specs.*.value' => 'nullable|string|max:5000',
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
        // Firmada pero sin la declaración de sus productos especiales (se
        // firmó antes de llenar la ficha, o se cambió la ficha): se puede
        // mandar para que el cliente apruebe eso. La firma de la orden no se toca.
        if ($orden->firma_url && ! DeclaracionEspeciales::faltaEnOrden($orden)) {
            return response()->json(['message' => 'Esta orden ya tiene la firma del cliente.'], 422);
        }
        if ($orden->items->filter->estaVivo()->contains(fn ($i) => $i->esperaCotizacion())) {
            return response()->json(['message' => 'Todavía hay productos sin precio. Cuando el taller los cotice, se manda a firmar con el precio final.'], 422);
        }
        if (! $orden->cliente_id) {
            return response()->json(['message' => 'La orden no tiene cliente.'], 422);
        }

        // Los productos especiales salen de la orden guardada (no de la
        // pantalla): es lo que se va a fabricar. Sin la ficha completa no se
        // manda; el cliente no puede aprobar un mueble que no dice cómo va.
        [$especiales, $error] = DeclaracionEspeciales::productosDeOrden($orden);
        if ($error) {
            return response()->json(['message' => $error . ' Llénala en Editar orden.', 'codigo' => 'ficha_incompleta'], 422);
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
            'version'      => AnexoGarantiaTexto::vigente()['version'],
            'modo'         => 'remoto',
            'estado'       => 'pendiente',
            'resumen'      => $resumen,
            'resumen_hash' => self::huellaDeOrden($orden),
            'vence_at'     => now()->addDays(self::DIAS_VIGENCIA),
            ...self::conDeclaracion($especiales),
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

    /**
     * GET /api/anexos/en-curso?cliente_id= — el enlace de firma que este
     * vendedor le mandó a este cliente desde Nueva orden y que todavía no es
     * de ninguna orden: esperando la firma, o ya firmado hace poco.
     *
     * Por qué existe: la espera vivía solo en la pantalla (y en el respaldo
     * del teléfono). Si la página se recargaba, Android cerraba la app o se
     * seguía desde un borrador o desde otro aparato, Nueva orden volvía a
     * ofrecer "Enviar al cliente" como si nada; si el vendedor lo mandaba de
     * nuevo, el enlace que el cliente ya tenía dejaba de servir, y si el
     * cliente firmaba, la firma nunca aparecía. Con esto, al escoger el
     * cliente, la pantalla retoma la espera.
     *
     * El firmado se busca solo de los últimos días: uno más viejo es de otra
     * venta que no se cerró. Si la orden cambió, el servidor lo dice al crearla.
     */
    public function enCurso(Request $request)
    {
        $data = $request->validate(['cliente_id' => 'required|integer']);

        $anexo = AnexoGarantia::where('vendedor_id', $request->user()->id)
            ->where('cliente_id', $data['cliente_id'])
            ->where('modo', 'remoto')
            ->whereNull('orden_id')
            ->when(AnexoGarantia::guardaOrden(), fn ($q) => $q->where(fn ($d) => $d->whereNull('orden_auto_estado')->orWhere('orden_auto_estado', '!=', 'descartada')))
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('estado', 'pendiente')->where(fn ($v) => $v->whereNull('vence_at')->orWhere('vence_at', '>', now())))
                ->orWhere(fn ($f) => $f->where('estado', 'firmado')->where('firmado_at', '>=', now()->subDays(self::DIAS_FIRMADO_EN_CURSO))))
            ->latest('id')->first();

        return response()->json($anexo ? $this->paraVendedor($anexo, conToken: true, request: $request) : null);
    }

    /**
     * GET /api/anexos/sin-orden — los enlaces que este vendedor mandó desde
     * Nueva orden con la orden guardada y que todavía no tienen orden:
     * esperando la firma, o firmados y por crear (o que no se pudieron crear
     * solos). Nueva orden los muestra arriba aunque el formulario ya no esté:
     * desde ahí se crea la orden o se cancela el envío.
     */
    public function sinOrden(Request $request)
    {
        if (! AnexoGarantia::guardaOrden()) return response()->json([]);

        $anexos = AnexoGarantia::with('cliente:id,nombre,email,telefono')
            ->where('vendedor_id', $request->user()->id)
            ->where('modo', 'remoto')
            ->whereNull('orden_id')
            ->whereNotNull('orden_pendiente')
            ->where(fn ($q) => $q->whereNull('orden_auto_estado')->orWhere('orden_auto_estado', '!=', 'descartada'))
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('estado', 'pendiente')->where(fn ($v) => $v->whereNull('vence_at')->orWhere('vence_at', '>', now())))
                ->orWhere(fn ($f) => $f->where('estado', 'firmado')->where('firmado_at', '>=', now()->subDays(self::DIAS_FIRMADO_EN_CURSO))))
            ->latest('id')->limit(30)->get();

        return response()->json($anexos->map(fn ($a) => $this->paraVendedor($a, conToken: true, request: $request))->values());
    }

    /**
     * POST /api/anexos/{id}/crear-orden — "Crear la orden" con lo que se
     * guardó al mandar el enlace. Para cuando el vendedor escogió esperar la
     * firma, o cuando crearla sola no se pudo (el motivo queda a la vista).
     * Se crea a nombre de quien mandó el enlace, por la puerta de siempre.
     */
    public function crearOrden(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);
        if (! $anexo->orden_id && empty($anexo->orden_pendiente)) {
            return response()->json(['message' => 'Este enlace no trae la orden guardada: créala desde el formulario de Nueva orden.'], 422);
        }

        $r = \App\Services\OrdenDelAnexo::crear($anexo);
        if (! empty($r['ocupado'])) {
            return response()->json(['message' => $r['mensaje']], 409);
        }
        if (! $r['ok']) {
            return response()->json(['message' => $r['mensaje'], 'anexo' => $this->paraVendedor($anexo->fresh(), conToken: true, request: $request)], 422);
        }

        return response()->json(['orden_id' => $r['orden_id'], 'referencia' => $r['referencia'] ?? null], 201);
    }

    /**
     * PATCH /api/anexos/{id}/crear-al-firmar  { crear_al_firmar }
     * Cambiar de idea mientras el cliente no ha firmado: que se cree sola, o
     * esperar y crearla a mano.
     */
    public function cambiarCrearAlFirmar(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);
        $data  = $request->validate(['crear_al_firmar' => 'required|boolean']);

        if ($anexo->estado !== 'pendiente' || empty($anexo->orden_pendiente)) {
            return response()->json(['message' => 'Ya no se puede cambiar: el cliente ya firmó o el enlace no trae la orden.'], 422);
        }
        if ($data['crear_al_firmar'] && ! empty($anexo->resumen['precio_pendiente'])) {
            return response()->json(['message' => 'Con productos de precio por consultar la orden no se crea sola.'], 422);
        }
        $anexo->update(['crear_al_firmar' => $data['crear_al_firmar']]);

        return response()->json($this->paraVendedor($anexo->fresh(), conToken: true, request: $request));
    }

    /** GET /api/anexos/{id} — el estado, para quien lo está esperando. */
    public function show(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);

        return response()->json($this->paraVendedor($anexo, conToken: true, request: $request));
    }

    /**
     * POST /api/anexos/{id}/anular — "Cancelar envío": el enlace deja de
     * servir. Solo lo que no se ha firmado; lo firmado es un documento. Uno
     * firmado cuya orden no se va a crear (el cliente desistió) se quita de
     * la lista de Nueva orden, pero el documento queda.
     */
    public function anular(Request $request, int $id)
    {
        $anexo = $this->delVendedor($request, $id);
        if ($anexo->estado === 'pendiente' && ! $anexo->orden_id) {
            $anexo->update(['estado' => 'anulado']);
        } elseif ($anexo->estaFirmado() && ! $anexo->orden_id && AnexoGarantia::guardaOrden()) {
            $anexo->update(['orden_auto_estado' => 'descartada']);
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
        $anexo = $this->paraDocumento($request, $id);

        return $this->documentoAnexo($anexo);
    }

    /**
     * GET /api/anexos/{id}/declaracion/pdf — la declaración de diseños
     * especiales que se firmó con el anexo: cada producto como el cliente lo
     * aprobó (ficha y bocetos), la constancia y la firma.
     */
    public function pdfDeclaracion(Request $request, int $id)
    {
        $anexo = $this->paraDocumento($request, $id);
        abort_unless($anexo->tieneDeclaracion(), 404, 'Este anexo no lleva declaración de diseños especiales.');

        return $this->documentoDeclaracion($anexo);
    }

    /**
     * GET /api/public/anexos/{token}/pdf/{doc} — el cliente descarga lo que
     * firmó (anexo | declaracion) desde su mismo enlace. Solo ya firmado:
     * antes no hay documento que guardar.
     */
    public function pdfPublico(string $token, string $doc)
    {
        $anexo = AnexoGarantia::with('orden', 'cliente', 'vendedor:id,nombre')->where('token', $token)->first();
        if (! $anexo || ! $anexo->estaFirmado()) {
            return response()->json(['message' => 'Este documento todavía no está firmado.'], 404);
        }
        if ($doc === 'declaracion') {
            abort_unless($anexo->tieneDeclaracion(), 404);
            return $this->documentoDeclaracion($anexo);
        }

        return $this->documentoAnexo($anexo);
    }

    private function paraDocumento(Request $request, int $id): AnexoGarantia
    {
        $anexo = AnexoGarantia::with('orden', 'cliente', 'vendedor:id,nombre')->findOrFail($id);
        if ($anexo->orden && ! $anexo->orden->laPuedeVer($request->user())) {
            abort(403, 'No autorizado.');
        }
        if (! $anexo->orden && ! $this->esSuyo($request, $anexo)) {
            abort(403, 'No autorizado.');
        }

        return $anexo;
    }

    private function documentoAnexo(AnexoGarantia $anexo)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $firma = $this->urlToBase64($anexo->firma_url);
        $logo  = $this->avifToPngBase64(public_path('img/logo.avif'));
        $texto = AnexoGarantiaTexto::deVersion($anexo->version);

        $pdf = Pdf::loadView('pdf.anexo_garantia', compact('anexo', 'firma', 'logo', 'texto'))->setPaper('letter');

        $nombre = 'anexo-garantias-' . ($anexo->orden?->referencia ?? $anexo->id) . '.pdf';
        return $pdf->stream(Str::slug(pathinfo($nombre, PATHINFO_FILENAME)) . '.pdf');
    }

    private function documentoDeclaracion(AnexoGarantia $anexo)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $firma = $this->urlToBase64($anexo->firma_url);
        $logo  = $this->avifToPngBase64(public_path('img/logo.avif'));
        $texto = DeclaracionEspeciales::deVersion($anexo->declaracion_version);

        // Los bocetos, en JPG y de un tamaño que quepa en la hoja: Cloudinary
        // los convierte (DomPDF no lee WebP ni AVIF).
        $productos = collect($anexo->especiales ?? [])->map(fn ($p) => [
            ...$p,
            'grupos'  => DeclaracionEspeciales::grupos($p['ficha'] ?? null),
            'imagenes' => collect($p['bocetos'] ?? [])->map(fn ($u) => $this->urlToBase64(self::comoJpg($u)))->filter()->values()->all(),
        ])->all();

        $pdf = Pdf::loadView('pdf.declaracion_especiales', compact('anexo', 'firma', 'logo', 'texto', 'productos'))->setPaper('letter');

        $nombre = 'declaracion-disenos-especiales-' . ($anexo->orden?->referencia ?? $anexo->id);
        return $pdf->stream(Str::slug($nombre) . '.pdf');
    }

    /** Una imagen de Cloudinary pedida en JPG y a lo sumo 900 px de ancho. */
    private static function comoJpg(string $url): string
    {
        return str_contains($url, 'res.cloudinary.com') && str_contains($url, '/image/upload/')
            ? preg_replace('#/image/upload/#', '/image/upload/f_jpg,q_80,w_900,c_limit/', $url, 1)
            : $url;
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
            'contenido'  => AnexoGarantiaTexto::deVersion($anexo->version),
            // La declaración de diseños especiales, si el pedido trae
            // productos fuera del catálogo: el texto que va a firmar y cada
            // producto como lo escribió el vendedor, para aprobarlo uno por uno.
            'declaracion' => $anexo->tieneDeclaracion() ? [
                'contenido' => DeclaracionEspeciales::deVersion($anexo->declaracion_version),
                'productos' => collect($anexo->especiales)->map(fn ($p) => [
                    'nombre'   => $p['nombre'] ?? '',
                    'cantidad' => $p['cantidad'] ?? 1,
                    'tipo'     => $p['tipo'] ?? null,
                    'grupos'   => DeclaracionEspeciales::grupos($p['ficha'] ?? null),
                    'bocetos'  => $p['bocetos'] ?? [],
                ])->values(),
                'aprobados' => $anexo->respuestas['declaracion']['aprobados'] ?? [],
            ] : null,
            // Si ya le pidió un cambio al asesor: que lo vea y no lo repita.
            'cambio_pedido'    => $anexo->cambio_pedido,
            'cambio_pedido_at' => $anexo->cambio_pedido_at,
        ]);
    }

    /**
     * POST /api/public/anexos/{token}/cambio  { mensaje }
     *
     * El cliente revisó y algo no está como lo quiere (una medida, la tela,
     * el boceto…). No edita nada: se lo dice al asesor desde aquí, y al asesor
     * le llega al instante. El asesor corrige la orden y le manda el enlace
     * nuevo; este deja de servir al mandarlo.
     */
    public function pedirCambio(Request $request, string $token)
    {
        $anexo = AnexoGarantia::where('token', $token)->first();
        if (! $anexo || $anexo->estado === 'anulado') {
            return response()->json(['message' => 'Este enlace no existe o ya no está vigente.'], 404);
        }
        if ($anexo->estaFirmado()) {
            return response()->json(['message' => 'Este pedido ya se firmó. Escríbele a tu asesor.'], 422);
        }
        if ($anexo->vencido()) {
            return response()->json(['message' => 'Este enlace venció. Pídele a tu asesor que te envíe uno nuevo.'], 410);
        }
        if (! DeclaracionEspeciales::hayColumnas()) {
            return response()->json(['message' => 'Escríbele a tu asesor.'], 409);
        }

        $data = $request->validate(['mensaje' => 'required|string|max:1500'], [
            'mensaje.required' => 'Escribe qué quieres cambiar.',
        ]);
        $mensaje = trim($data['mensaje']);

        $anexo->update(['cambio_pedido' => $mensaje, 'cambio_pedido_at' => now()]);

        $cliente = Cliente::find($anexo->cliente_id)?->nombre ?? 'El cliente';
        try {
            if ($anexo->orden_id) {
                \App\Models\OrdenMensaje::create([
                    'orden_id'   => $anexo->orden_id,
                    'usuario_id' => $anexo->vendedor_id,
                    'mensaje'    => "✏️ {$cliente} revisó la orden antes de firmar y pidió un cambio: {$mensaje}",
                ]);
            }
            if ($anexo->vendedor_id) {
                NotificacionService::crear(
                    'anexo_cambio',
                    'El cliente pidió un cambio',
                    "{$cliente}: " . Str::limit($mensaje, 160),
                    ['anexo_id' => $anexo->id] + ($anexo->orden_id ? ['orden_id' => $anexo->orden_id] : []),
                    $anexo->vendedor_id,
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
        // La pantalla del vendedor que lo espera se refresca sola (el mismo
        // canal de la firma: solo dice "algo cambió, vuelve a preguntar").
        try { event(new AnexoFirmado($anexo->id)); } catch (\Throwable) {}

        return response()->json(['ok' => true]);
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

        $texto        = AnexoGarantiaTexto::deVersion($anexo->version);
        $idsSecciones = array_column($texto['secciones'], 'id');
        $idsChecklist = array_column($texto['checklist'], 'id');

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

        // El pedido que ve el cliente (lo remoto con resumen): tiene que decir
        // que lo revisó y que todo está bien —sus datos, los productos, el
        // valor, la entrega— antes de firmar. Con esa firma se confirma la
        // orden (y puede crearse sola), así que no basta con haberlo tenido en
        // la pantalla.
        $revisaPedido = ! empty($anexo->resumen);
        if ($revisaPedido && ! $request->boolean('orden_revisada')) {
            return response()->json(['message' => 'Marca que revisaste tu pedido y que todo está bien antes de firmar. Si algo no está bien, escríbele a tu asesor.'], 422);
        }

        $faltan = array_diff($idsSecciones, $data['secciones']);
        if ($faltan) {
            return response()->json(['message' => 'Falta marcar como leídas ' . count($faltan) . ' sección(es) del anexo.'], 422);
        }
        $sinResponder = array_diff($idsChecklist, array_keys($data['checklist']));
        if ($sinResponder) {
            return response()->json(['message' => 'Responde todas las preguntas del check list.'], 422);
        }

        // La declaración de diseños especiales: cada producto aprobado, uno
        // por uno (lo que se aprueba es ESE diseño, no "todo lo de arriba").
        $aprobados = [];
        if ($anexo->tieneDeclaracion()) {
            $aprobados = array_values(array_unique(array_map('intval', (array) $request->input('declaracion_aprobados', []))));
            $total     = count($anexo->especiales);
            if (count(array_intersect(range(0, $total - 1), $aprobados)) < $total) {
                return response()->json(['message' => 'Revisa y aprueba cada uno de los productos especiales antes de firmar.'], 422);
            }
            $aprobados = range(0, $total - 1);
        }

        // Una orden que ya existe: si la editaron después de mandar el enlace,
        // lo que el cliente está viendo ya no es lo que va. No se firma. Lo
        // mismo si cambió cómo va un mueble especial (su ficha).
        $orden = ($anexo->resumen['orden_existente'] ?? false) && $anexo->orden_id
            ? Orden::with('items')->find($anexo->orden_id) : null;
        if ($orden && ($orden->estado === 'cancelado'
            || ! hash_equals((string) $anexo->resumen_hash, self::huellaDeOrden($orden))
            || ($anexo->tieneDeclaracion() && ! hash_equals((string) $anexo->especiales_hash, (string) DeclaracionEspeciales::huellaDeOrden($orden))))) {
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
            'respuestas'         => json_encode([
                'secciones' => array_values(array_intersect($idsSecciones, $data['secciones'])),
                'checklist' => array_intersect_key($data['checklist'], array_flip($idsChecklist)),
                // Dijo que revisó el pedido y que estaba bien (lo remoto).
                ...($revisaPedido ? ['orden_revisada' => true] : []),
                // Los datos con que firmó la declaración (el papel pedía
                // dirección y teléfono): como estaban ese día, aunque cambien.
                ...($anexo->tieneDeclaracion() ? ['declaracion' => [
                    'aprobados' => $aprobados,
                    'direccion' => Cliente::find($anexo->cliente_id)?->direccion,
                    'telefono'  => Cliente::find($anexo->cliente_id)?->telefono,
                ]] : []),
            ], JSON_UNESCAPED_UNICODE),
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

        // El vendedor escogió que la orden se creara sola apenas firmara: va a
        // la cola (el cliente no espera) y el aviso al vendedor lo manda ella,
        // con la orden creada o con el motivo si no se pudo.
        $seCreaSola = ! $orden && $anexo->crear_al_firmar && $anexo->tieneOrdenPendiente();
        if ($seCreaSola) {
            try {
                \App\Jobs\CrearOrdenFirmada::dispatch($anexo->id);
            } catch (\Throwable $e) {
                // Sin cola: queda firmado y el vendedor la crea desde Nueva orden.
                report($e);
                $seCreaSola = false;
            }
        }

        // Al vendedor que lo está esperando: en la pantalla al instante y,
        // si la cerró, como notificación en el teléfono. Que el aviso falle
        // no deshace la firma.
        try { event(new AnexoFirmado($anexo->id)); } catch (\Throwable) {}
        if ($anexo->modo === 'remoto' && $anexo->vendedor_id && ! $seCreaSola) {
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
            'respuestas_no'      => collect(AnexoGarantiaTexto::deVersion($anexo->version)['checklist'])
                ->filter(fn ($q) => ($anexo->respuestas['checklist'][$q['id']] ?? null) === 'no')
                ->pluck('pregunta')->values(),
            // La declaración de diseños especiales que va con este anexo: de
            // qué productos, y su huella para saber si la orden ya no es esa.
            'declaracion'        => $anexo->tieneDeclaracion(),
            'especiales_hash'    => $anexo->especiales_hash,
            'productos_declarados' => collect($anexo->especiales ?? [])->pluck('nombre')->values(),
            // Lo que el cliente pidió cambiar en vez de firmar.
            'cambio_pedido'      => $anexo->cambio_pedido,
            'cambio_pedido_at'   => $anexo->cambio_pedido_at,
            'vence_at'           => $anexo->vence_at,
            'cliente_nombre'     => $anexo->cliente?->nombre,
            'total'              => $anexo->resumen['total'] ?? null,
            // La orden que viaja con el enlace (OrdenDelAnexo): si se guardó,
            // si se crea sola al firmar y cómo le fue al crearla.
            'orden_guardada'     => ! empty($anexo->orden_pendiente),
            'crear_al_firmar'    => (bool) $anexo->crear_al_firmar,
            'orden_auto_estado'  => $anexo->orden_auto_estado,
            'orden_auto_error'   => $anexo->orden_auto_error,
            'orden_revisada'     => (bool) ($anexo->respuestas['orden_revisada'] ?? false),
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
        return self::baseApp($request) . '/firmar/' . $anexo->token;
    }

    /** La app (Vercel) cuando no hay FRONTEND_URL ni el navegador dice desde dónde pide. */
    public const APP_POR_DEFECTO = 'https://sistema-de-ventas-olive.vercel.app';

    /**
     * La dirección de la app para los enlaces que se le mandan al cliente.
     *
     * Antes caía en `app.url` (la API en Render) y el cliente recibía un
     * enlace que no abría nada (visto 2026-10-21): los navegadores solo mandan
     * `Origin` en los POST, y la pantalla del vendedor pregunta el estado por
     * GET cada pocos segundos (y al retomar la espera tras recargar), así que el
     * enlace bueno se cambiaba por el de Render. Ahora: FRONTEND_URL, el Origin,
     * el Referer y, si nada, la app. Nunca la dirección de la API.
     */
    public static function baseApp(?Request $request): string
    {
        $candidatos = [
            config('app.frontend_url'),
            $request?->headers->get('origin'),
            ($ref = $request?->headers->get('referer'))
                ? (parse_url($ref, PHP_URL_SCHEME) . '://' . parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : ''))
                : null,
        ];
        $api = parse_url((string) config('app.url'), PHP_URL_HOST);
        foreach ($candidatos as $c) {
            $host = $c ? parse_url((string) $c, PHP_URL_HOST) : null;
            if ($host && $host !== $api) return rtrim((string) $c, '/');
        }

        return self::APP_POR_DEFECTO;
    }
}
