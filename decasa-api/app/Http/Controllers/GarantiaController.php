<?php

namespace App\Http\Controllers;

use App\Models\Garantia;
use App\Models\Inventario;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\ProductoVarianteConfig;
use App\Models\Tienda;
use App\Models\TipoProceso;
use App\Services\GarantiaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Garantías: lo que se daña después de entregado.
 *
 * Aquí solo se valida y se mira quién puede qué; lo que pasa con la orden, el
 * taller y el inventario está en `GarantiaService`.
 *
 *  - Reporta quien puede ver la orden (el vendedor de la tienda, que es a
 *    quien el cliente llama), quien despacha o quien decide.
 *  - Decide quien gestiona el taller o un supervisor. Cambiar por otro
 *    producto, que mueve plata, solo un supervisor.
 */
class GarantiaController extends Controller
{
    private function comoJson(Garantia $g): array
    {
        $item  = $g->item;
        $nuevo = $g->itemNuevo;

        return [
            'id'               => $g->id,
            'orden_id'         => $g->orden_id,
            'orden_referencia' => $g->orden?->referencia,
            'orden_estado'     => $g->orden?->estado,
            'cliente'          => $g->orden?->cliente?->nombre,
            'cliente_telefono' => $g->orden?->cliente?->telefono,
            'orden_item_id'    => $g->orden_item_id,
            'producto'         => GarantiaService::nombre($item),
            'producto_id'      => $item?->producto_id,
            'variante_texto'   => $item?->variante_texto,
            'variante_id'      => $item?->variante_id,
            'combo_config_id'  => $item?->combo_config_id,
            'es_personalizado' => (bool) $item?->es_personalizado,
            'va_al_taller'     => (bool) $item?->vaAlTaller(),
            'precio_unitario'  => $item ? (float) $item->precio_unitario : null,
            'cantidad'         => (int) $g->cantidad,
            'tipo_dano'        => $g->tipo_dano,
            'linea'            => $g->linea,
            'motivo'           => $g->motivo,
            'fotos'            => $g->fotos ?? [],
            'donde_esta'       => $g->donde_esta,
            'preferencia_cliente' => $g->preferencia_cliente,
            'fecha_reporte'    => $g->fecha_reporte?->toDateString(),
            'fecha_entrega'    => $g->fecha_entrega?->toDateString(),
            'vence_el'         => $g->vence_el?->toDateString(),
            'dentro_de_garantia' => $g->dentroDeGarantia(),
            'responder_antes_de' => $g->responder_antes_de?->toDateString(),
            'reportado_por'    => $g->reportadoPor?->nombre,
            'estado'           => $g->estado,
            'decision'         => $g->decision,
            'decidido_por'     => $g->decididoPor?->nombre,
            'decidido_at'      => $g->decidido_at?->toIso8601String(),
            'notas_decision'   => $g->notas_decision,
            'causal_no_procede' => $g->causal_no_procede,
            'causal_texto'     => $g->causal_no_procede ? (Garantia::CAUSALES[$g->causal_no_procede] ?? $g->causal_no_procede) : null,
            'procesos_reparacion' => $g->procesos_reparacion ?? [],
            'produccion_id'    => $g->produccion_id,
            'recibido_en_taller_at' => $g->recibido_en_taller_at?->toIso8601String(),
            'devolver_antes_de' => $g->devolverAntesDe()?->toDateString(),
            'visita_por_id'    => $g->visita_por_id,
            'visita_por'       => $g->visitaPor?->nombre,
            'visita_fecha'     => $g->visita_fecha?->toDateString(),
            'visita_notas'     => $g->visita_notas,
            'visita_fotos'     => $g->visita_fotos ?? [],
            'orden_item_nuevo_id' => $g->orden_item_nuevo_id,
            'producto_nuevo'   => $nuevo ? GarantiaService::nombre($nuevo) : null,
            'diferencia_valor' => $g->diferencia_valor !== null ? (float) $g->diferencia_valor : null,
            'monto_reembolso'  => $g->monto_reembolso !== null ? (float) $g->monto_reembolso : null,
            // Solo mientras se puede decidir: después la orden ya cambió.
            'monto_sugerido'   => in_array($g->estado, ['pendiente', 'a_domicilio', 'por_devolver'], true) ? $g->montoSugerido() : null,
            'destino_devuelto' => $g->destino_devuelto,
            'tienda_devuelto'  => $g->tienda_devuelto_id ? Tienda::find($g->tienda_devuelto_id)?->nombre : null,
            'resuelta_at'      => $g->resuelta_at?->toIso8601String(),
            'resuelta_por'     => $g->resueltaPor?->nombre,
            'registrada_en'    => $g->created_at?->toIso8601String(),
        ];
    }

    private const RELACIONES = [
        'orden', 'orden.cliente',
        'item.producto:id,nombre', 'itemNuevo.producto:id,nombre',
        'reportadoPor:id,nombre', 'decididoPor:id,nombre', 'visitaPor:id,nombre', 'resueltaPor:id,nombre',
    ];

    /**
     * GET /api/garantias?estado=abiertas|<estado>&orden_id=
     *
     * Con `orden_id`, las de esa orden (para quien la puede ver). Sin él, la
     * bandeja: quien decide las ve todas; los demás, solo las visitas que les
     * tocan. Lo que vence primero, arriba: hay un plazo legal corriendo.
     */
    public function index(Request $request)
    {
        $u = $request->user();
        $q = Garantia::with(self::RELACIONES);

        if ($ordenId = $request->query('orden_id')) {
            $orden = \App\Models\Orden::findOrFail($ordenId);
            if (! GarantiaService::puedeDecidir($u) && ! $u->acceso_despacho && ! $orden->laPuedeVer($u)) {
                return response()->json(['message' => 'No autorizado.'], 403);
            }
            $q->where('orden_id', $ordenId);
        } elseif (! GarantiaService::puedeDecidir($u) && ! $u->acceso_despacho) {
            $q->where('visita_por_id', $u->id);
        }

        $estado = $request->query('estado');
        if ($estado === 'abiertas') {
            $q->abiertas();
        } elseif ($estado) {
            $q->where('estado', $estado);
        }

        $q->orderByRaw("estado = 'pendiente' DESC")->orderBy('responder_antes_de')->orderBy('id');

        return response()->json($q->limit(300)->get()->map(fn (Garantia $g) => $this->comoJson($g)));
    }

    /** POST /api/garantias — reportar. */
    public function store(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'orden_item_id'       => 'required|integer|exists:orden_items,id',
            'cantidad'            => 'required|integer|min:1',
            'tipo_dano'           => 'required|in:madera,tela_espuma,otro',
            'linea'               => 'nullable|in:elite_promocional,economica',
            'motivo'              => 'required|string|min:3|max:1000',
            'fotos'               => 'nullable|array|max:6',
            'fotos.*'             => 'string|max:500',
            'donde_esta'          => 'nullable|in:casa_cliente,tienda',
            'preferencia_cliente' => 'nullable|in:arreglar,cambiar_mismo,cambiar_otro,reembolso',
        ], [
            'motivo.required'    => 'Escribe qué se dañó.',
            'tipo_dano.required' => 'Escoge de qué es el daño: madera, tela/espuma u otro.',
        ]);

        $item = OrdenItem::with('orden', 'producto:id,nombre')->findOrFail($data['orden_item_id']);

        if (! GarantiaService::puedeDecidir($u) && ! $u->acceso_despacho && ! $item->orden->laPuedeVer($u)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $g = GarantiaService::registrar($item, $data, $u);

        return response()->json($this->comoJson($g->fresh(self::RELACIONES)), 201);
    }

    /** POST /api/garantias/{id}/decidir — el dictamen. */
    public function decidir(Request $request, int $id)
    {
        $u = $request->user();
        if (! GarantiaService::puedeDecidir($u)) {
            return response()->json(['message' => 'Las garantías las decide quien gestiona Producción o un supervisor.'], 403);
        }

        $g = Garantia::with('orden', 'item')->findOrFail($id);

        $data = $request->validate([
            'decision'        => 'required|in:taller,domicilio,cambio_mismo,cambio_otro,reembolso,no_procede',
            'notas'           => 'nullable|string|max:1000',
            // Taller (y lo fabricado que se hace de nuevo): qué procesos.
            'procesos'        => 'nullable|array|max:12',
            'procesos.*'      => 'string|max:60',
            // Domicilio.
            'visita_por_id'   => 'required_if:decision,domicilio|nullable|integer|exists:usuarios,id',
            'visita_fecha'    => 'required_if:decision,domicilio|nullable|date',
            // Cambio.
            'producto_id'     => 'required_if:decision,cambio_otro|nullable|integer|exists:productos,id',
            'variante_id'     => 'nullable|integer',
            'combo_config_id' => 'nullable|integer',
            'tienda_id'       => 'nullable|integer',
            'fabricar'        => 'nullable|boolean',
            'precio_unitario' => 'required_if:decision,cambio_otro|nullable|numeric|min:0',
            // Reembolso: cuánto se le devuelve (lo aprueba un supervisor).
            'monto'           => 'required_if:decision,reembolso|nullable|numeric|min:0',
            // No procede.
            'causal'          => ['required_if:decision,no_procede', 'nullable', Rule::in(array_keys(Garantia::CAUSALES))],
        ], [
            'visita_por_id.required_if'   => 'Escoge quién va a la casa del cliente.',
            'visita_fecha.required_if'    => 'Escoge qué día va.',
            'producto_id.required_if'     => 'Escoge el producto por el que se cambia.',
            'precio_unitario.required_if' => 'Falta el precio del producto nuevo.',
            'causal.required_if'          => 'Escoge por qué no procede.',
            'monto.required_if'           => 'Falta cuánto se le devuelve al cliente.',
        ]);

        if (in_array($data['decision'], ['cambio_otro', 'reembolso'], true) && ! GarantiaService::puedeDecidirConPlata($u)) {
            return response()->json([
                'message' => $data['decision'] === 'reembolso'
                    ? 'Devolver la plata lo decide un supervisor.'
                    : 'Cambiar por otro producto cambia el valor de la orden: lo decide un supervisor.',
            ], 403);
        }

        GarantiaService::decidir($g, $data, $u);

        return response()->json($this->comoJson($g->fresh(self::RELACIONES)));
    }

    /** POST /api/garantias/{id}/recibir — el mueble llegó al taller. */
    public function recibir(Request $request, int $id)
    {
        $u = $request->user();
        if (! GarantiaService::puedeDecidir($u) && ! $u->acceso_despacho) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $g = Garantia::with('orden', 'item')->findOrFail($id);
        GarantiaService::recibirEnTaller($g, $u);

        return response()->json($this->comoJson($g->fresh(self::RELACIONES)));
    }

    /**
     * POST /api/garantias/{id}/recibir-devolucion — llegó lo que devolvió el
     * cliente: sale la plata. Es mover plata, así que solo un supervisor.
     */
    public function recibirDevolucion(Request $request, int $id)
    {
        $u = $request->user();
        if (! GarantiaService::puedeDecidirConPlata($u)) {
            return response()->json(['message' => 'Devolver la plata lo registra un supervisor.'], 403);
        }

        $g = Garantia::with('orden', 'item')->findOrFail($id);

        $data = $request->validate([
            'metodo'     => 'required|in:efectivo,transferencia,otro',
            'destino'    => 'required|in:inventario,merma',
            'tienda_id'  => 'required_if:destino,inventario|nullable|integer',
            'referencia' => 'nullable|string|max:100',
            'monto'      => 'nullable|numeric|min:0',
        ], [
            'metodo.required'       => 'Escoge cómo se le devolvió la plata.',
            'destino.required'      => 'Escoge qué se hace con lo que devolvió: inventario o merma.',
            'tienda_id.required_if' => 'Escoge a qué tienda vuelve el producto.',
        ]);

        GarantiaService::recibirDevolucion($g, $data, $u);

        return response()->json($this->comoJson($g->fresh(self::RELACIONES)));
    }

    /** POST /api/garantias/{id}/visita — lo arreglaron en la casa. */
    public function visita(Request $request, int $id)
    {
        $u = $request->user();
        $g = Garantia::with('orden', 'item')->findOrFail($id);

        if (! GarantiaService::puedeDecidir($u) && (int) $g->visita_por_id !== (int) $u->id) {
            return response()->json(['message' => 'Esta visita la registra quien fue o quien decide.'], 403);
        }

        $data = $request->validate([
            'notas'   => 'required|string|min:3|max:1000',
            'fecha'   => 'nullable|date',
            'fotos'   => 'nullable|array|max:6',
            'fotos.*' => 'string|max:500',
        ], ['notas.required' => 'Escribe qué se encontró y qué se hizo.']);

        GarantiaService::registrarVisita($g, $data, $u);

        return response()->json($this->comoJson($g->fresh(self::RELACIONES)));
    }

    /**
     * GET /api/garantias/opciones
     *
     * Lo que necesita la pantalla del dictamen: las causales del anexo y los
     * procesos del taller que se pueden rehacer (despacho no: va siempre).
     */
    public function opciones()
    {
        return response()->json([
            'causales' => collect(Garantia::CAUSALES)->map(fn ($t, $k) => ['clave' => $k, 'texto' => $t])->values(),
            'procesos' => TipoProceso::query()
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('tipos_proceso', 'activo'), fn ($q) => $q->where('activo', true))
                ->where('clave', '!=', \App\Models\ProduccionPaso::DESPACHO)
                ->orderBy('orden')->get(['clave', 'nombre']),
            'meses'    => Garantia::MESES,
            // Quién puede ir a la casa del cliente: tiene que usar el programa,
            // porque le llega el aviso y es quien registra la visita.
            'personas' => \App\Models\Usuario::where('activo', true)->usaElPrograma()
                ->where(fn ($q) => $q->where('apto_produccion', true)->orWhere('acceso_despacho', true)
                    ->orWhereIn('rol', ['supervisor', 'conductor', 'taller', 'despachador']))
                ->orderBy('nombre')->get(['id', 'nombre', 'rol']),
        ]);
    }

    /**
     * GET /api/garantias/stock?producto_id=&variante_id=&combo_config_id=
     *
     * "Se consulta en inventario": cuántos libres hay de ese producto en cada
     * tienda (lo apartado para otras órdenes no cuenta), con sus telas y
     * opciones para escoger exactamente cuál, y un precio sugerido que quien
     * aprueba puede cambiar.
     */
    public function stock(Request $request)
    {
        if (! GarantiaService::puedeDecidir($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'producto_id'     => 'required|integer|exists:productos,id',
            'variante_id'     => 'nullable|integer',
            'combo_config_id' => 'nullable|integer',
        ]);

        $producto   = Producto::findOrFail($data['producto_id']);
        $varianteId = $data['variante_id'] ?? null;
        $comboId    = $data['combo_config_id'] ?? null;

        $variante = $varianteId ? ProductoVariante::where('producto_id', $producto->id)->find($varianteId) : null;
        $combo    = $comboId ? ProductoVarianteConfig::where('producto_id', $producto->id)->find($comboId) : null;

        $tiendas = Tienda::query()
            ->where(fn ($q) => $q->whereNull('es_independientes')->orWhere('es_independientes', false))
            ->where(fn ($q) => $q->whereNull('activa')->orWhere('activa', true))
            ->whereIn('id', Inventario::where('producto_id', $producto->id)->pluck('tienda_id'))
            ->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn ($t) => [
                'tienda_id' => $t->id,
                'nombre'    => $t->nombre,
                'libres'    => max(0, GarantiaService::stockLibre($producto->id, $variante?->id, $combo?->id, $t->id)),
            ])
            ->values();

        // Sugerencia, no regla: el precio de la tela/medida si lo tiene, si
        // no el del producto, más lo que sume la opción.
        $sugerido = (float) ($variante?->precio_variante ?? $producto->precio_base ?? 0)
                  + (float) ($combo?->precio_adicional ?? 0);

        return response()->json([
            'producto'        => ['id' => $producto->id, 'nombre' => $producto->nombre, 'categoria' => $producto->categoria ?? null],
            'precio_sugerido' => round($sugerido, 2),
            'variantes'       => ProductoVariante::where('producto_id', $producto->id)->where('activo', true)
                ->orderBy('marca_tela')->orderBy('nombre_color')->get()
                ->map(fn ($v) => ['id' => $v->id, 'texto' => trim(implode(' ', array_filter([$v->marca_tela, $v->nombre_color, $v->medida])))])
                ->values(),
            'opciones'        => ProductoVarianteConfig::with('opcion:id,nombre')->where('producto_id', $producto->id)->get()
                ->map(fn ($c) => ['id' => $c->id, 'texto' => $c->opcion?->nombre ?? "Opción {$c->id}"])
                ->values(),
            'tiendas'         => $tiendas,
        ]);
    }
}
