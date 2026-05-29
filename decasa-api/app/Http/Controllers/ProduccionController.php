<?php

namespace App\Http\Controllers;

use App\Events\OrdenListaParaEntrega;
use App\Events\ProduccionActualizada;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProduccionController extends Controller
{
    /**
     * GET /api/produccion
     * Supervisor: todos. Vendedor: solo los suyos. Ebanista/tapicero/despachador: no accede aquí.
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Produccion::with([
            'ordenItem.producto:id,nombre,categoria,foto_url',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.vendedor:id,nombre',
            'ordenItem.orden.tienda:id,nombre',
            'pasos',
        ]);

        if ($usuario->rol === 'vendedor') {
            $query->whereHas('ordenItem.orden', fn($q) => $q->where('vendedor_id', $usuario->id));
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($tiendaId = $request->query('tienda_id')) {
            $query->whereHas('ordenItem.orden', fn($q) => $q->where('tienda_id', $tiendaId));
        }

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('ordenItem.producto', fn($p) => $p->whereRaw('LOWER(nombre) LIKE ?', [$term]))
                  ->orWhereHas('ordenItem.orden.cliente', fn($c) => $c->whereRaw('LOWER(nombre) LIKE ?', [$term]));
            });
        }

        $producciones = $query
            ->orderByRaw("FIELD(estado, 'pendiente', 'retrasado', 'en_proceso', 'pendiente_despachador', 'listo', 'entregado')")
            ->orderBy('fecha_compromiso')
            ->paginate(20)
            ->through(fn($p) => $this->conDiasRestantes($p));

        return response()->json($producciones);
    }

    /**
     * GET /api/produccion/mis-pasos
     * Para ebanistas y supervisores-tapiceros: pasos activos (en_proceso) asignados a su rol.
     */
    public function misPasos(Request $request)
    {
        $usuario = $request->user();

        $tiposProceso = $this->tiposParaRol($usuario);

        if (empty($tiposProceso)) {
            return response()->json(['message' => 'Sin acceso a pasos de producción.'], 403);
        }

        $pasos = ProduccionPaso::with([
            'produccion.ordenItem.producto:id,nombre,categoria,foto_url',
            'produccion.ordenItem.orden.cliente:id,nombre,telefono',
            'produccion.ordenItem.orden.vendedor:id,nombre',
            'produccion.ordenItem.orden.tienda:id,nombre',
            'produccion.pasos',
        ])
        ->whereIn('tipo_proceso', $tiposProceso)
        ->where('estado', 'en_proceso')
        ->orderBy('orden')
        ->get();

        return response()->json($pasos);
    }

    /**
     * GET /api/produccion/historial-pasos
     * Para ebanistas y tapiceros: pasos completados por el propio usuario.
     */
    public function historialPasos(Request $request)
    {
        $usuario = $request->user();
        $tiposProceso = $this->tiposParaRol($usuario);

        if (empty($tiposProceso)) {
            return response()->json(['message' => 'Sin acceso a pasos de producción.'], 403);
        }

        $pasos = ProduccionPaso::with([
            'produccion.ordenItem.producto:id,nombre,categoria,foto_url',
            'produccion.ordenItem.orden.cliente:id,nombre,telefono',
            'produccion.ordenItem.orden.vendedor:id,nombre',
            'produccion.ordenItem.orden.tienda:id,nombre',
        ])
        ->whereIn('tipo_proceso', $tiposProceso)
        ->where('estado', 'completado')
        ->where('completado_por', $usuario->id)
        ->orderByDesc('completado_at')
        ->limit(50)
        ->get();

        return response()->json($pasos);
    }

    /**
     * PATCH /api/produccion/pasos/{id}/completar
     * Ebanista o tapicero marca un paso como terminado.
     */
    public function completarPaso(Request $request, int $id)
    {
        $usuario = $request->user();
        $paso    = ProduccionPaso::with('produccion.ordenItem.orden')->findOrFail($id);

        // Verificar que el usuario puede completar este tipo de paso
        $tiposPermitidos = $this->tiposParaRol($usuario);
        if (! in_array($paso->tipo_proceso, $tiposPermitidos)) {
            return response()->json(['message' => 'No autorizado para este proceso.'], 403);
        }

        if ($paso->estado === 'completado') {
            return response()->json(['message' => 'Este paso ya fue completado.'], 422);
        }

        if ($paso->estado !== 'en_proceso') {
            return response()->json(['message' => 'Solo se puede completar un paso activo.'], 422);
        }

        $data = $request->validate([
            'trabajadores'   => 'required|array|min:1',
            'trabajadores.*' => 'required|string|max:100',
        ]);

        // Completar el paso actual
        $paso->update([
            'estado'         => 'completado',
            'completado_por' => $usuario->id,
            'completado_at'  => now(),
            'trabajadores'   => $data['trabajadores'],
        ]);

        $produccion = $paso->produccion;
        $orden      = $produccion->ordenItem->orden;

        // Buscar siguiente paso pendiente
        $siguientePaso = ProduccionPaso::where('produccion_id', $produccion->id)
            ->where('estado', 'pendiente')
            ->orderBy('orden')
            ->first();

        $productoNombre = $produccion->ordenItem->producto->nombre ?? 'Producto';
        $vendedorId     = $orden->vendedor_id;

        if ($siguientePaso) {
            $siguientePaso->update(['estado' => 'en_proceso']);
            $labelSiguiente = ProduccionPaso::labelProceso($siguientePaso->tipo_proceso);

            // Notificar trabajadores del siguiente paso
            $this->notificarTrabajadores($siguientePaso->tipo_proceso, $produccion->id, $orden->id, $productoNombre);

            // Notificar al vendedor sobre el cambio de etapa
            NotificacionService::crear(
                'paso_produccion',
                'Tu pedido avanzó en producción',
                "\"{$productoNombre}\" paso a {$labelSiguiente}",
                ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
                $vendedorId,
            );
        } else {
            // Todos los pasos terminaron → espera al despachador
            $produccion->update(['estado' => 'pendiente_despachador']);

            // Notificar despachadores
            $despachadores = Usuario::where('rol', 'despachador')->where('activo', true)->get();
            foreach ($despachadores as $d) {
                NotificacionService::crear(
                    'paso_produccion',
                    'Producto listo para despacho',
                    "\"{$productoNombre}\" completo produccion y esta en espera de despacho",
                    ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
                    $d->id,
                );
            }

            // Notificar supervisores
            NotificacionService::crear(
                'paso_produccion',
                'Producción completada',
                "\"{$productoNombre}\" completo todos los pasos - en espera de despachador",
                ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
            );

            // Notificar al vendedor
            NotificacionService::crear(
                'paso_produccion',
                'Tu pedido terminó producción',
                "\"{$productoNombre}\" completo todos los procesos y esta pendiente de entrega",
                ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
                $vendedorId,
            );

            event(new ProduccionActualizada($produccion->id, $orden->id, 'pendiente_despachador'));
        }

        return response()->json(['message' => 'Paso completado.']);
    }

    /**
     * GET /api/produccion/pendientes-despacho
     * Solo despachadores: producciones que terminaron todos sus pasos.
     */
    public function pendientesDespacho(Request $request)
    {
        $pasos = Produccion::with([
            'ordenItem.producto:id,nombre,categoria,foto_url',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.vendedor:id,nombre',
            'ordenItem.orden.tienda:id,nombre',
            'pasos',
        ])
        ->where('estado', 'pendiente_despachador')
        ->orderBy('updated_at')
        ->get()
        ->map(fn($p) => $this->conDiasRestantes($p));

        return response()->json($pasos);
    }

    /**
     * PATCH /api/produccion/{id}/completar-despacho
     * Despachador marca el producto como listo → la orden pasa a listo_entrega.
     */
    public function completarDespacho(Request $request, int $id)
    {
        $usuario    = $request->user();
        $produccion = Produccion::with('ordenItem.orden')->findOrFail($id);

        if ($produccion->estado !== 'pendiente_despachador') {
            return response()->json(['message' => 'Este pedido no está pendiente de despacho.'], 422);
        }

        DB::transaction(function () use ($produccion, $usuario) {
            $produccion->update([
                'estado'         => 'listo',
                'fecha_real'     => now()->toDateString(),
                'despachado_por' => $usuario->id,
            ]);

            // Sincronizar estado de la orden
            $orden = $produccion->ordenItem->orden;
            $orden->loadMissing('items.produccion');

            $estadosProduccion = $orden->items
                ->map(fn($item) => optional($item->produccion)->estado)
                ->filter();

            if ($estadosProduccion->isNotEmpty()) {
                if ($estadosProduccion->every(fn($e) => $e === 'entregado')) {
                    $orden->update(['estado' => 'entregado']);
                } elseif ($estadosProduccion->every(fn($e) => in_array($e, ['listo', 'entregado']))) {
                    $orden->update([
                        'estado'           => 'listo_entrega',
                        'listo_entrega_at' => now(),
                    ]);
                    try { event(new OrdenListaParaEntrega($orden->id)); } catch (\Throwable) {}
                } else {
                    $orden->update(['estado' => 'en_produccion']);
                }
            }
        });

        $produccion->load(['ordenItem.producto:id,nombre', 'ordenItem.orden.vendedor:id']);
        $productoNombre = $produccion->ordenItem->producto->nombre ?? 'Producto';
        $vendedorId     = $produccion->ordenItem->orden->vendedor_id;

        // Notificar al vendedor
        NotificacionService::crear(
            'entregado',
            'Tu pedido está listo para entrega',
            "\"{$productoNombre}\" esta listo y en camino al area de entrega",
            ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
            $vendedorId,
        );

        event(new ProduccionActualizada($produccion->id, $orden->id, 'listo'));

        return response()->json(['message' => 'Producto despachado. La orden pasó a listo para entrega.']);
    }

    /**
     * PATCH /api/produccion/{id}
     * Supervisor cambia estado. Si cambia a en_proceso, debe enviar los pasos.
     */
    public function update(Request $request, int $id)
    {
        $usuario    = $request->user();
        $produccion = Produccion::with('ordenItem.orden')->findOrFail($id);

        // Vendedor solo puede actualizar sus propios pedidos (para otros estados, no en_proceso)
        if ($usuario->rol === 'vendedor' &&
            $produccion->ordenItem->orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'estado'         => 'required|in:pendiente,en_proceso,listo,retrasado,entregado,cancelado',
            'motivo_retraso' => 'nullable|string|max:500',
            'pasos'          => 'nullable|array|min:1',
            'pasos.*.tipo_proceso' => 'required_with:pasos|in:ebanisteria,tapizado,laca',
            'pasos.*.orden'        => 'required_with:pasos|integer|min:1',
        ]);

        // en_proceso requiere pasos (solo supervisor)
        if ($data['estado'] === 'en_proceso') {
            if ($usuario->rol !== 'supervisor') {
                return response()->json(['message' => 'Solo el supervisor puede iniciar producción.'], 403);
            }
            if (empty($data['pasos'])) {
                return response()->json([
                    'message' => 'Debes definir al menos un paso de producción.',
                    'errors'  => ['pasos' => ['Se requiere al menos un paso.']],
                ], 422);
            }
        }

        if ($data['estado'] === 'retrasado' && empty($data['motivo_retraso'])) {
            return response()->json([
                'message' => 'Se requiere motivo_retraso cuando el estado es retrasado.',
                'errors'  => ['motivo_retraso' => ['Campo obligatorio para estado retrasado.']],
            ], 422);
        }

        $updates = ['estado' => $data['estado']];

        if (! empty($data['motivo_retraso'])) {
            $updates['motivo_retraso'] = $data['motivo_retraso'];
        }

        if (in_array($data['estado'], ['listo', 'entregado'])) {
            $updates['fecha_real'] = now()->toDateString();
        }

        $produccion->update($updates);

        // Si cambia a en_proceso: crear pasos
        if ($data['estado'] === 'en_proceso' && ! empty($data['pasos'])) {
            // Eliminar pasos anteriores si los hubiera (reinicio de flujo)
            ProduccionPaso::where('produccion_id', $produccion->id)->delete();

            $pasosOrdenados = collect($data['pasos'])->sortBy('orden');
            foreach ($pasosOrdenados as $paso) {
                ProduccionPaso::create([
                    'produccion_id' => $produccion->id,
                    'tipo_proceso'  => $paso['tipo_proceso'],
                    'orden'         => $paso['orden'],
                    'estado'        => 'pendiente',
                ]);
            }

            // Activar el primer paso
            $primerPaso = ProduccionPaso::where('produccion_id', $produccion->id)
                ->orderBy('orden')
                ->first();

            if ($primerPaso) {
                $primerPaso->update(['estado' => 'en_proceso']);
                $produccion->load(['ordenItem.producto:id,nombre', 'ordenItem.orden.vendedor:id']);
                $productoNombre = $produccion->ordenItem->producto->nombre ?? 'Producto';
                $labelPaso      = ProduccionPaso::labelProceso($primerPaso->tipo_proceso);
                $vendedorId     = $produccion->ordenItem->orden->vendedor_id;

                $this->notificarTrabajadores($primerPaso->tipo_proceso, $produccion->id, $produccion->ordenItem->orden->id, $productoNombre);

                // Notificar al vendedor: producción iniciada
                NotificacionService::crear(
                    'en_produccion',
                    'Tu pedido entró a producción',
                    "\"{$productoNombre}\" comenzo en {$labelPaso}",
                    ['produccion_id' => $produccion->id, 'orden_id' => $produccion->ordenItem->orden->id],
                    $vendedorId,
                );
            }
        }

        // Sincronizar estado de la orden según todos sus ítems de producción
        $orden = $produccion->ordenItem->orden;
        $orden->loadMissing('items.produccion');

        $estadosProduccion = $orden->items
            ->map(fn($item) => optional($item->produccion)->estado)
            ->filter();

        if ($estadosProduccion->isNotEmpty()) {
            if ($estadosProduccion->every(fn($e) => $e === 'entregado')) {
                $orden->update(['estado' => 'entregado']);
            } elseif ($estadosProduccion->every(fn($e) => in_array($e, ['listo', 'entregado']))) {
                if ($orden->estado !== 'listo_entrega') {
                    $orden->update(['estado' => 'listo_entrega', 'listo_entrega_at' => now()]);
                    try { event(new OrdenListaParaEntrega($orden->id)); } catch (\Throwable) {}
                }
            } else {
                $orden->update(['estado' => 'en_produccion']);
            }
        }

        event(new ProduccionActualizada(
            $produccion->id,
            $produccion->ordenItem->orden->id,
            $data['estado'],
        ));

        $produccion->load([
            'ordenItem.producto:id,nombre,categoria',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.tienda:id,nombre',
            'pasos',
        ]);

        $productoNombre = $produccion->ordenItem->producto->nombre ?? 'Producto';
        $clienteNombre  = $produccion->ordenItem->orden->cliente->nombre ?? '';
        $tiendaNombre   = $produccion->ordenItem->orden->tienda->nombre ?? '';
        $ordenId        = $produccion->ordenItem->orden->id;
        $vendedorId     = $produccion->ordenItem->orden->vendedor_id;

        if ($data['estado'] === 'retrasado') {
            NotificacionService::crear('retrasado', 'Producción retrasada',
                "{$productoNombre} — {$clienteNombre} ({$tiendaNombre})",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
            );
            NotificacionService::crear('retrasado', 'Tu pedido está atrasado',
                "{$productoNombre} para {$clienteNombre} ha superado el tiempo comprometido",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
                $vendedorId,
            );
        } elseif ($data['estado'] === 'entregado') {
            NotificacionService::crear('entregado', 'Producto entregado',
                "Orden #{$ordenId} — {$productoNombre} · {$clienteNombre}",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
            );
            NotificacionService::crear('entregado', 'Tu pedido fue entregado',
                "{$productoNombre} para {$clienteNombre} ha sido entregado",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
                $vendedorId,
            );
        }

        return response()->json($this->conDiasRestantes($produccion));
    }

    /**
     * GET /api/produccion/historial-despacho
     * Para despachadores: producciones que ellos mismos despacharon.
     */
    public function historialDespacho(Request $request)
    {
        $usuario = $request->user();

        if ($usuario->rol !== 'despachador') {
            return response()->json(['message' => 'Sin acceso.'], 403);
        }

        $producciones = Produccion::with([
            'ordenItem.producto:id,nombre,categoria,foto_url',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.vendedor:id,nombre',
            'ordenItem.orden.tienda:id,nombre',
            'pasos',
        ])
        ->where('despachado_por', $usuario->id)
        ->whereIn('estado', ['listo', 'entregado'])
        ->orderByDesc('fecha_real')
        ->limit(50)
        ->get()
        ->map(fn($p) => $this->conDiasRestantes($p));

        return response()->json($producciones);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────────────────────────

    private function conDiasRestantes(Produccion $p): Produccion
    {
        if ($p->fecha_compromiso) {
            $hoy        = now()->startOfDay();
            $compromiso = \Carbon\Carbon::parse($p->fecha_compromiso)->startOfDay();
            $p->dias_restantes = $hoy->diffInDays($compromiso, false);
        } else {
            $p->dias_restantes = null;
        }
        return $p;
    }

    private function tiposParaRol(Usuario $usuario): array
    {
        if ($usuario->rol === 'ebanista') {
            return ['ebanisteria', 'laca'];
        }
        if ($usuario->rol === 'supervisor' && $usuario->es_tapicero) {
            return ['tapizado', 'laca'];
        }
        return [];
    }

    private function notificarTrabajadores(string $tipoProceso, int $produccionId, int $ordenId, string $productoNombre): void
    {
        $label = ProduccionPaso::labelProceso($tipoProceso);

        $usuariosANotificar = collect();

        if (in_array($tipoProceso, ['ebanisteria', 'laca'])) {
            $usuariosANotificar = $usuariosANotificar->merge(
                Usuario::where('rol', 'ebanista')->where('activo', true)->get()
            );
        }

        if (in_array($tipoProceso, ['tapizado', 'laca'])) {
            $usuariosANotificar = $usuariosANotificar->merge(
                Usuario::where('rol', 'supervisor')->where('es_tapicero', true)->where('activo', true)->get()
            );
        }

        foreach ($usuariosANotificar->unique('id') as $trabajador) {
            NotificacionService::crear(
                'paso_produccion',
                "Nuevo paso: {$label}",
                "\"{$productoNombre}\" requiere {$label}",
                ['produccion_id' => $produccionId, 'orden_id' => $ordenId],
                $trabajador->id,
            );
        }
    }
}
