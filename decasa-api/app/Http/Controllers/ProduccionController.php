<?php

namespace App\Http\Controllers;

use App\Events\OrdenListaParaEntrega;
use App\Events\ProduccionActualizada;
use App\Models\PasoTrabajador;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
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

        // El vendedor ve lo suyo sin permiso aparte —son sus ventas—; para el
        // tablero del taller hace falta el permiso, o llevar algún paso. Antes
        // esto solo se le exigía al supervisor, así que cualquier otro rol veía
        // la producción entera sin que nadie se la hubiera activado.
        if (! $usuario->soloVeSusOrdenes() && ! $usuario->veProduccion()) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $query = Produccion::with([
            'ordenItem.producto:id,nombre,categoria,foto_url',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.vendedor:id,nombre',
            'ordenItem.orden.tienda:id,nombre',
            // Quién está haciendo cada paso: sin esto el tablero no puede
            // mostrar al responsable mientras la pieza sigue en el taller.
            'pasos.participantes.usuario:id,nombre',
            'pasos.completadoPor:id,nombre',
        ]);

        if ($usuario->soloVeSusOrdenes()) {
            // Las mismas órdenes que ve en su lista: si una compartida le sale
            // ahí, poder seguirla en el taller es parte de lo mismo.
            $query->whereHas('ordenItem.orden', fn ($q) => $q->visiblesPara($usuario));
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

        // Lo que uno fijó va de primeras, por encima del orden por estado. Se
        // fija la ORDEN, no el paso: en el taller uno dice "esta orden primero"
        // y así todas sus piezas suben juntas.
        $fijada = "(SELECT 1 FROM orden_fijadas f
                    JOIN orden_items oi ON oi.id = produccion.orden_item_id
                    WHERE f.orden_id = oi.orden_id AND f.usuario_id = ?) IS NOT NULL";

        $producciones = $query
            ->addSelect(['*', DB::raw("($fijada) AS fijada")])
            ->addBinding($usuario->id, 'select')
            ->when($request->query('orden') === 'entrega', function ($q) {
                // Por lo que se entrega primero, sin agrupar por estado: la
                // pregunta es "qué sale esta semana", y agrupando por estado lo
                // de mañana que ya está en proceso queda debajo de lo de dentro
                // de un mes que sigue pendiente.
                //
                // Se deja fuera lo que ya salió del taller. Y no se respeta lo
                // fijado: si uno pide ver por urgencia, algo fijado colándose
                // arriba sin que le toque rompe la respuesta.
                $q->whereNotIn('estado', ['entregado', 'cancelado'])
                  // Sin fecha no se sabe cuándo sale, así que va al final; en
                  // MySQL un NULL se ordenaría primero.
                  ->orderByRaw('fecha_compromiso IS NULL')
                  ->orderBy('fecha_compromiso');
            }, function ($q) use ($usuario, $fijada) {
                $q->orderByRaw("$fijada DESC", [$usuario->id])
                  ->orderByRaw("FIELD(estado, 'pendiente', 'retrasado', 'en_proceso', 'pendiente_despachador', 'listo', 'entregado')")
                  ->orderBy('fecha_compromiso');
            })
            // Como en órdenes: se puede pedir más de una página de golpe para
            // que al recargar no se pierda lo que ya se había bajado.
            ->paginate(min((int) $request->query('per_page', 20) ?: 20, 200))
            ->through(function ($p) {
                $p = $this->conDiasRestantes($p);
                $p->fijada = (bool) $p->fijada;
                return $p;
            });

        return response()->json($producciones);
    }

    /**
     * GET /api/produccion/mis-pasos
     * Para ebanistas y supervisores-tapiceros: pasos activos (en_proceso) asignados a su rol.
     */
    public function misPasos(Request $request)
    {
        $usuario = $request->user();

        if (empty($usuario->procesosQuePuedeTrabajar())) {
            return response()->json(['message' => 'Sin acceso a pasos de producción.'], 403);
        }

        $pasos = ProduccionPaso::with([
            'produccion.ordenItem.producto:id,nombre,categoria,foto_url',
            'produccion.ordenItem.orden.cliente:id,nombre,telefono',
            'produccion.ordenItem.orden.vendedor:id,nombre',
            'produccion.ordenItem.orden.tienda:id,nombre',
            'produccion.pasos.participantes.usuario:id,nombre',
            'participantes.usuario:id,nombre',
        ])
        ->tap(fn ($q) => $this->soloSusPasos($q, $usuario))
        ->where('estado', 'en_proceso')
        // Y que la pieza siga viva. Mirar solo el estado del paso hacía que una
        // pieza cancelada siguiera apareciéndole al ebanista como trabajo
        // pendiente. Se comprueba acá además de al cancelar: cualquier otro
        // camino que deje un paso huérfano se topa con esto.
        ->whereHas('produccion', fn ($q) => $q->whereNotIn('estado', ['cancelado', 'entregado']))
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

        if (empty($usuario->procesosQuePuedeTrabajar())) {
            return response()->json(['message' => 'Sin acceso a pasos de producción.'], 403);
        }

        $pasos = ProduccionPaso::with([
            'produccion.ordenItem.producto:id,nombre,categoria,foto_url',
            'produccion.ordenItem.orden.cliente:id,nombre,telefono',
            'produccion.ordenItem.orden.vendedor:id,nombre',
            'produccion.ordenItem.orden.tienda:id,nombre',
            'participantes.usuario:id,nombre',
        ])
        ->tap(fn ($q) => $this->soloSusPasos($q, $usuario))
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

        // Verificar que el usuario puede completar este tipo de paso, en la
        // línea de esta pieza: si el taller lleva las restauraciones aparte,
        // el encargado de lo nuevo no cierra el tapizado de una restauración.
        if (! $this->puedeTrabajar($usuario, $paso)) {
            return response()->json(['message' => $this->porQueNoPuede($paso)], 403);
        }

        if ($paso->estado === 'completado') {
            return response()->json(['message' => 'Este paso ya fue completado.'], 422);
        }

        if ($paso->estado !== 'en_proceso') {
            return response()->json(['message' => 'Solo se puede completar un paso activo.'], 422);
        }

        // Último candado: la pieza tiene que seguir viva. Si se canceló, este
        // paso no es trabajo de nadie —aunque se hubiera quedado en la pantalla
        // del ebanista de antes de recargar— y completarlo la haría avanzar al
        // paso siguiente como si nada hubiera pasado.
        if (in_array($paso->produccion?->estado, ['cancelado', 'entregado'], true)) {
            return response()->json([
                'message' => 'Esta pieza ya no está en producción: se canceló. Actualiza la pantalla.',
            ], 422);
        }

        $participantes = $this->validarParticipantes($request, exigirAlMenosUno: true);

        // Completar el paso actual. `completado_por` es quien AUTORIZA que siga
        // al siguiente paso, que no siempre es quien lo hizo: por eso se guarda
        // aparte de los participantes.
        $paso->update([
            'estado'         => 'completado',
            'completado_por' => $usuario->id,
            'completado_at'  => now(),
            // Se sigue llenando el JSON de nombres para que nada de lo que ya
            // lo lee (PDF, pantallas viejas en caché) se quede en blanco.
            'trabajadores'   => $this->nombresDe($participantes),
        ]);

        $this->guardarParticipantes($paso, $participantes, $usuario, conCalificacion: true);

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
            $siguientePaso->update(['estado' => 'en_proceso', 'iniciado_at' => now()]);
            $labelSiguiente = ProduccionPaso::labelProceso($siguientePaso->tipo_proceso);

            // El estado de la producción se sigue moviendo igual que antes, para
            // que el tablero y los filtros no cambien: lo que se fue es la
            // pantalla aparte, no el estado.
            if ($siguientePaso->tipo_proceso === ProduccionPaso::DESPACHO) {
                $produccion->update(['estado' => 'pendiente_despachador']);
                event(new ProduccionActualizada($produccion->id, $orden->id, 'pendiente_despachador'));
            }

            // Notificar trabajadores del siguiente paso
            $this->notificarTrabajadores($siguientePaso->tipo_proceso, $siguientePaso->linea, $produccion->id, $orden->id, $productoNombre);

            // Notificar al vendedor sobre el cambio de etapa
            NotificacionService::crear(
                'paso_produccion',
                'Tu pedido avanzó en producción',
                "\"{$productoNombre}\" paso a {$labelSiguiente}",
                ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
                $vendedorId,
            );
        } else {
            // No quedan pasos. El último es siempre el de despacho, así que
            // llegar aquí significa que la pieza ya salió del taller.
            $this->cerrarProduccion($produccion, $orden, $usuario, $productoNombre, $vendedorId);
        }

        return response()->json(['message' => 'Paso completado.']);
    }

    /**
     * PATCH /api/produccion/pasos/{id}/devolver
     * El trabajador del paso actual detecta un defecto en un paso anterior
     * y lo devuelve para que sea corregido.
     */
    public function devolverPaso(Request $request, int $id)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'paso_destino_id' => 'required|integer|exists:produccion_pasos,id',
            'motivo'          => 'required|string|max:500',
        ]);

        $pasoOrigen  = ProduccionPaso::with('produccion.ordenItem.producto', 'produccion.ordenItem.orden')->findOrFail($id);
        $pasoDestino = ProduccionPaso::findOrFail($data['paso_destino_id']);

        // Devolver es tan delicado como completar —resetea todos los pasos
        // desde el destino en adelante y borra quién los había hecho—, pero no
        // verificaba nada: cualquiera con sesión podía devolver el paso de
        // cualquier producción. Se pide lo mismo que para completar: poder
        // trabajar el paso desde el que se devuelve. La pantalla ya lo usa así
        // (solo se devuelve desde un paso propio), así que no cambia el flujo.
        if (! $this->puedeTrabajar($usuario, $pasoOrigen)) {
            return response()->json(['message' => $this->porQueNoPuede($pasoOrigen)], 403);
        }

        // Validaciones
        if ($pasoOrigen->produccion_id !== $pasoDestino->produccion_id) {
            return response()->json(['message' => 'Los pasos no pertenecen a la misma producción.'], 422);
        }
        if ($pasoOrigen->estado !== 'en_proceso') {
            return response()->json(['message' => 'Solo puedes devolver desde un paso activo.'], 422);
        }
        if ($pasoDestino->orden >= $pasoOrigen->orden) {
            return response()->json(['message' => 'Solo puedes devolver a un paso anterior.'], 422);
        }

        $produccion     = $pasoOrigen->produccion;
        $productoNombre = $produccion->ordenItem->producto->nombre ?? 'Producto';
        $orden          = $produccion->ordenItem->orden;

        DB::transaction(function () use ($pasoOrigen, $pasoDestino, $produccion, $usuario, $data) {
            // Registrar el rechazo en el paso con el defecto (destino)
            $pasoDestino->update([
                'rechazos'         => $pasoDestino->rechazos + 1,
                'ultimo_rechazo'   => $data['motivo'],
                'rechazado_por_id' => $usuario->id,
                'rechazado_at'     => now(),
            ]);

            // Resetear todos los pasos desde el destino en adelante (inclusive el origen)
            ProduccionPaso::where('produccion_id', $pasoOrigen->produccion_id)
                ->where('orden', '>=', $pasoDestino->orden)
                ->update([
                    'estado'         => 'pendiente',
                    'completado_por' => null,
                    'completado_at'  => now(),
                    'trabajadores'   => null,
                ]);

            // Activar el paso con el defecto para que sea corregido
            $pasoDestino->update(['estado' => 'en_proceso', 'iniciado_at' => now()]);

            // Si la producción estaba en pendiente_despachador, volver a en_proceso
            if (in_array($produccion->estado, ['pendiente_despachador', 'listo'])) {
                $produccion->update(['estado' => 'en_proceso']);
            }
        });

        // Notificar a los trabajadores del paso devuelto
        $this->notificarTrabajadores(
            $pasoDestino->tipo_proceso,
            $pasoDestino->linea,
            $produccion->id,
            $orden->id,
            $productoNombre
        );

        $labelOrigen  = ProduccionPaso::labelProceso($pasoOrigen->tipo_proceso);
        $labelDestino = ProduccionPaso::labelProceso($pasoDestino->tipo_proceso);

        // Notificar al supervisor
        NotificacionService::crear(
            'paso_produccion',
            'Paso devuelto en producción',
            "\"{$productoNombre}\": {$labelOrigen} devolvió {$labelDestino} — {$data['motivo']}",
            ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
        );

        // Notificar al vendedor
        NotificacionService::crear(
            'paso_produccion',
            'Corrección en tu pedido',
            "\"{$productoNombre}\" regresó a {$labelDestino} para corrección",
            ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
            $orden->vendedor_id,
        );

        event(new ProduccionActualizada($produccion->id, $orden->id, 'en_proceso'));

        return response()->json(['message' => "Paso devuelto a {$labelDestino} correctamente."]);
    }



    /**
     * La pieza salió del taller: se cierra la producción y la orden se pone al día.
     *
     * Antes esto vivía en `completarDespacho`, el botón de un módulo aparte.
     * Ahora lo dispara el paso de despacho al completarse, igual que cualquier
     * otro paso, y por eso el despachador ya no necesita pantalla propia.
     */
    private function cerrarProduccion(Produccion $produccion, $orden, Usuario $usuario, string $productoNombre, ?int $vendedorId): void
    {
        DB::transaction(function () use ($produccion, $usuario, $orden) {
            $produccion->update([
                'estado'         => 'listo',
                'fecha_real'     => now()->toDateString(),
                'despachado_por' => $usuario->id,
            ]);

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

        NotificacionService::crear(
            'entregado',
            'Tu pedido está listo para entrega',
            "\"{$productoNombre}\" esta listo y en camino al area de entrega",
            ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
            $vendedorId,
        );

        // A los supervisores también, que es lo que avisaba el módulo viejo.
        NotificacionService::crear(
            'paso_produccion',
            'Producción completada',
            "\"{$productoNombre}\" salió del taller y está listo para entrega",
            ['produccion_id' => $produccion->id, 'orden_id' => $orden->id],
        );

        event(new ProduccionActualizada($produccion->id, $orden->id, 'listo'));
    }

    /**
     * PATCH /api/produccion/{id}
     * Supervisor cambia estado. Si cambia a en_proceso, debe enviar los pasos.
     */
    public function update(Request $request, int $id)
    {
        $usuario    = $request->user();
        $produccion = Produccion::with('ordenItem.orden')->findOrFail($id);

        // Arrancar un proceso, armarle los pasos o moverle el estado a una
        // pieza es mandar en el taller, no mirarlo: va con su propio permiso.
        // El vendedor conserva lo suyo (más abajo se comprueba que sea de él).
        if (! $usuario->soloVeSusOrdenes() && ! $usuario->gestionaProduccion()) {
            return response()->json([
                'message' => 'Puedes ver la producción, pero no cambiarla.',
            ], 403);
        }

        // Vendedor solo puede actualizar sus propios pedidos (para otros estados, no en_proceso)
        if ($usuario->soloVeSusOrdenes() &&
            $produccion->ordenItem->orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'estado'         => 'required|in:pendiente,en_proceso,listo,retrasado,entregado,cancelado',
            'motivo_retraso' => 'nullable|string|max:500',
            'pasos'          => 'nullable|array|min:1',
            'pasos.*.tipo_proceso' => 'required_with:pasos|exists:tipos_proceso,clave',
            'pasos.*.orden'        => 'required_with:pasos|integer|min:1',
        ]);

        // Arrancar una pieza es armarle el flujo de pasos, y eso lo decide quien
        // gestiona el taller. Antes se exigía ser supervisor, así que activarle
        // el permiso a otro rol no servía de nada: el permiso quedaba puesto y
        // el botón seguía dando 403.
        if ($data['estado'] === 'en_proceso') {
            if (! $usuario->gestionaProduccion()) {
                return response()->json(['message' => 'No tienes permiso para arrancar producción.'], 403);
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

        // Cancelar una pieza tiene que sacarla del taller de verdad.
        //
        // Antes solo se le cambiaba el estado a ella y sus pasos seguían como
        // estaban, así que el paso en curso continuaba saliendo en "Mis pasos"
        // y el ebanista lo podía avanzar. Lo ya completado se conserva —ese
        // trabajo se hizo— y se cancela lo que quedaba por delante.
        if ($data['estado'] === 'cancelado') {
            ProduccionPaso::where('produccion_id', $produccion->id)
                ->whereIn('estado', ['pendiente', 'en_proceso'])
                ->update(['estado' => 'cancelado']);
        }

        // Si cambia a en_proceso: crear pasos
        if ($data['estado'] === 'en_proceso' && ! empty($data['pasos'])) {
            // Eliminar pasos anteriores si los hubiera (reinicio de flujo)
            ProduccionPaso::where('produccion_id', $produccion->id)->delete();

            // El despacho cierra siempre: se añade solo al final, para que no
            // dependa de que quien arma el flujo se acuerde de ponerlo.
            $delTaller = collect($data['pasos'])
                ->reject(fn ($p) => $p['tipo_proceso'] === ProduccionPaso::DESPACHO)
                ->sortBy('orden')
                ->values();

            // El orden es un número pequeño (la columna es un tinyint), así que
            // el despacho se numera justo después del último, no con un número
            // alto simbólico.
            $pasosOrdenados = $delTaller->push([
                'tipo_proceso' => ProduccionPaso::DESPACHO,
                'orden'        => (int) $delTaller->max('orden') + 1,
            ]);

            // De qué es la pieza se decide UNA vez, aquí, y queda escrito en
            // cada paso: es lo que después reparte el trabajo entre el
            // encargado de restauraciones y el de lo nuevo.
            $linea = TipoProceso::lineaDe((bool) $produccion->ordenItem->es_restauracion);

            foreach ($pasosOrdenados as $paso) {
                ProduccionPaso::create([
                    'produccion_id' => $produccion->id,
                    'tipo_proceso'  => $paso['tipo_proceso'],
                    'linea'         => $linea,
                    'orden'         => $paso['orden'],
                    'estado'        => 'pendiente',
                ]);
            }

            // Activar el primer paso
            $primerPaso = ProduccionPaso::where('produccion_id', $produccion->id)
                ->orderBy('orden')
                ->first();

            if ($primerPaso) {
                $primerPaso->update(['estado' => 'en_proceso', 'iniciado_at' => now()]);
                $produccion->load(['ordenItem.producto:id,nombre', 'ordenItem.orden.vendedor:id']);
                $productoNombre = $produccion->ordenItem->producto->nombre ?? 'Producto';
                $labelPaso      = ProduccionPaso::labelProceso($primerPaso->tipo_proceso);
                $vendedorId     = $produccion->ordenItem->orden->vendedor_id;

                $this->notificarTrabajadores($primerPaso->tipo_proceso, $primerPaso->linea, $produccion->id, $produccion->ordenItem->orden->id, $productoNombre);

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
                    $produccion->loadMissing('ordenItem.producto:id,nombre');
                    $nomProd = $produccion->ordenItem->producto->nombre ?? 'Producto';
                    NotificacionService::crear(
                        'listo_entrega',
                        'Tu pedido está listo para entrega',
                        "\"{$nomProd}\" completó producción y está en espera de despacho",
                        ['orden_id' => $orden->id],
                        $orden->vendedor_id,
                    );
                }
            } else {
                $orden->update(['estado' => 'en_produccion']);
            }
        }

        try {
            event(new ProduccionActualizada(
                $produccion->id,
                $produccion->ordenItem->orden->id,
                $data['estado'],
            ));
        } catch (\Throwable) {}

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
        $numeroOrden    = $produccion->ordenItem->orden->numero_orden ?? $ordenId;
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
                "Orden #{$numeroOrden} — {$productoNombre} · {$clienteNombre}",
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

    /**
     * GET /api/produccion/trabajadores
     * A quién se puede poner en un paso.
     *
     * Salen los del taller, no sólo los del proceso: ahí adentro entra a ayudar
     * quien esté libre. Se filtra por "apto para producción" y no por si entra
     * al programa: la gente de fábrica no entra y es justamente la que hace el
     * trabajo. Los del proceso vienen marcados para que salgan de primeros.
     */
    public function trabajadores(Request $request)
    {
        $proceso = $request->query('proceso');
        // La línea del paso, para que "del proceso" signifique lo mismo que en
        // "Mis pasos": en una restauración salen primero sus encargados.
        $linea   = in_array($request->query('linea'), TipoProceso::LINEAS, true)
            ? $request->query('linea')
            : null;

        $usuarios = Usuario::where('activo', true)->aptoProduccion()
            ->with('rolAsignado:id,nombre')
            ->orderBy('nombre')
            ->get();

        return response()->json(
            $usuarios->map(function (Usuario $u) use ($proceso, $linea) {
                $d = $u->desempenoTaller();

                return [
                    'id'               => $u->id,
                    'nombre'           => $u->nombre,
                    'rol'              => $u->rolAsignado?->nombre ?? $u->rol,
                    'del_proceso'      => $proceso ? in_array($proceso, $u->procesosQuePuedeTrabajar($linea), true) : false,
                    'calidad_promedio' => $d['calidad_promedio'],
                    'calificaciones'   => $d['calificaciones'],
                    'pasos'            => $d['pasos'],
                    'horas_promedio'   => $d['horas_promedio'],
                ];
            })
            // Primero los del proceso, luego los mejor calificados: así el
            // encargado ve arriba a quien conviene poner.
            ->sortBy([
                fn ($a, $b) => ($b['del_proceso'] <=> $a['del_proceso']),
                fn ($a, $b) => (($b['calidad_promedio'] ?? -1) <=> ($a['calidad_promedio'] ?? -1)),
                fn ($a, $b) => strcmp($a['nombre'], $b['nombre']),
            ])
            ->values()
        );
    }

    /**
     * PATCH /api/produccion/pasos/{id}/trabajadores
     * Dejar apuntado quién está haciendo el paso, sin cerrarlo todavía.
     *
     * Existe porque el encargado no siempre sabe al cerrar quién lo hizo: si
     * se apunta al empezar, al final sólo hay que poner horas y calidad.
     */
    public function asignarTrabajadores(Request $request, int $id)
    {
        $usuario = $request->user();
        $paso    = ProduccionPaso::with('participantes')->findOrFail($id);

        // El encargado del paso, o quien gestione el taller. Antes se pedía ser
        // supervisor, y eso dejaba fuera a cualquier otro rol al que se le
        // hubiera activado el permiso.
        if (! $this->puedeTrabajar($usuario, $paso) && ! $usuario->gestionaProduccion()) {
            return response()->json(['message' => $this->porQueNoPuede($paso)], 403);
        }

        if ($paso->estado === 'completado') {
            return response()->json([
                'message' => 'Este paso ya se cerró: su registro no se cambia.',
            ], 422);
        }

        $participantes = $this->validarParticipantes($request, exigirAlMenosUno: false);
        $this->guardarParticipantes($paso, $participantes, $usuario, conCalificacion: false);

        // El paso queda marcado como tomado, para que en el tablero se vea
        // quién lo está haciendo y no parezca que nadie lo agarró.
        if ($paso->estado === 'en_proceso' && ! $paso->iniciado_at) {
            $paso->update(['iniciado_at' => now()]);
        }

        return response()->json([
            'message'       => 'Trabajadores asignados.',
            'participantes' => $this->participantesJson($paso->fresh()->load('participantes.usuario')),
        ]);
    }

    /**
     * Lee y valida la lista de participantes que manda la pantalla.
     *
     * Acepta también la forma vieja (un arreglo de nombres sueltos) porque una
     * app abierta desde antes del cambio sigue mandando eso, y un taller no se
     * puede quedar sin poder cerrar un paso porque no refrescó la página.
     *
     * @return array<int, array{usuario_id:?int, nombre:?string, horas:?float, calidad:?int, comentario:?string}>
     */
    private function validarParticipantes(Request $request, bool $exigirAlMenosUno): array
    {
        $crudo = $request->input('trabajadores', []);
        $esFormaVieja = is_array($crudo) && ! empty($crudo) && is_string(reset($crudo));

        if ($esFormaVieja) {
            $data = $request->validate([
                'trabajadores'   => ($exigirAlMenosUno ? 'required' : 'nullable') . '|array',
                'trabajadores.*' => 'required|string|max:100',
            ]);

            return array_map(fn ($n) => [
                'usuario_id' => null, 'nombre' => $n,
                'horas' => null, 'calidad' => null, 'comentario' => null,
            ], $data['trabajadores'] ?? []);
        }

        $data = $request->validate([
            'trabajadores'              => ($exigirAlMenosUno ? 'required|array|min:1' : 'nullable|array'),
            'trabajadores.*.usuario_id' => 'required|integer|exists:usuarios,id',
            // Cuánto se demoró, en la unidad que diga `unidad`. `horas` es la
            // forma vieja —siempre en horas— y la sigue mandando una app que
            // se haya quedado abierta desde antes de que existieran los días.
            'trabajadores.*.tiempo'     => 'nullable|numeric|min:0',
            'trabajadores.*.horas'      => 'nullable|numeric|min:0',
            'trabajadores.*.unidad'     => 'nullable|in:hora,dia',
            'trabajadores.*.calidad'    => 'nullable|integer|min:1|max:5',
            'trabajadores.*.comentario' => 'nullable|string|max:300',
        ]);

        $lista = collect($data['trabajadores'] ?? [])
            ->map(fn ($t) => [
                'usuario_id' => (int) $t['usuario_id'],
                'nombre'     => null,
                'horas'      => $this->horasDe($t),
                'calidad'    => isset($t['calidad']) && $t['calidad'] !== '' ? (int) $t['calidad'] : null,
                'comentario' => trim($t['comentario'] ?? '') ?: null,
            ])
            // Que la pantalla mande dos veces a la misma persona no debe
            // reventar contra el índice único.
            ->unique('usuario_id')
            ->values()
            ->all();

        if ($exigirAlMenosUno && empty($lista)) {
            abort(422, 'Elige al menos un trabajador.');
        }

        return $lista;
    }

    /**
     * Cuánto se demoró un participante, siempre en horas.
     *
     * Lo que llega es el número tal como lo escribieron más la unidad, porque
     * en el taller se cuenta por días y pasarlo a horas en la pantalla dejaría
     * el dato a merced de la versión de la app que tenga abierta cada quien.
     */
    private function horasDe(array $t): ?float
    {
        $crudo  = $t['tiempo'] ?? $t['horas'] ?? null;
        if ($crudo === null || $crudo === '') {
            return null;
        }

        $horas = PasoTrabajador::aHoras((float) $crudo, $t['unidad'] ?? null);

        // Un paso que se llevó más de un mes de trabajo de una sola persona no
        // es un dato: es un dedo que se resbaló en el teclado.
        if ($horas > 500) {
            abort(422, 'Ese tiempo es demasiado: revisa si son horas o días.');
        }

        return round($horas, 2);
    }

    /** Los nombres, para el JSON viejo que siguen leyendo otras pantallas. */
    private function nombresDe(array $participantes): array
    {
        $ids = array_filter(array_column($participantes, 'usuario_id'));
        $porId = $ids ? Usuario::whereIn('id', $ids)->pluck('nombre', 'id') : collect();

        return array_values(array_filter(array_map(
            fn ($p) => $p['usuario_id'] ? ($porId[$p['usuario_id']] ?? null) : $p['nombre'],
            $participantes
        )));
    }

    /**
     * Deja el paso con exactamente estos participantes.
     *
     * Al asignar (sin calificación) se respeta lo que ya tuviera cada quien:
     * corregir la lista de quién está trabajando no debe borrar unas horas que
     * ya se habían anotado.
     */
    private function guardarParticipantes(ProduccionPaso $paso, array $participantes, Usuario $autor, bool $conCalificacion): void
    {
        $conUsuario = array_values(array_filter($participantes, fn ($p) => $p['usuario_id']));
        if (empty($conUsuario)) {
            return;   // Forma vieja: sólo nombres, no hay a quién enganchar.
        }

        DB::transaction(function () use ($paso, $conUsuario, $autor, $conCalificacion) {
            $ids = array_column($conUsuario, 'usuario_id');

            // Quien salió de la lista deja de figurar.
            $paso->participantes()->whereNotIn('usuario_id', $ids)->delete();

            foreach ($conUsuario as $p) {
                $fila = $paso->participantes()->firstOrNew(['usuario_id' => $p['usuario_id']]);

                if (! $fila->exists) {
                    $fila->asignado_por = $autor->id;
                    $fila->asignado_at  = now();
                }

                if ($conCalificacion) {
                    $fila->horas      = $p['horas'];
                    $fila->calidad    = $p['calidad'];
                    $fila->comentario = $p['comentario'];
                    if ($p['calidad'] !== null || $p['horas'] !== null) {
                        $fila->calificado_por = $autor->id;
                        $fila->calificado_at  = now();
                    }
                }

                $paso->participantes()->save($fila);
            }
        });
    }

    /** Cómo viaja un participante a la pantalla. */
    private function participantesJson(ProduccionPaso $paso): array
    {
        return $paso->participantes->map(fn ($p) => [
            'id'         => $p->id,
            'usuario_id' => $p->usuario_id,
            'nombre'     => $p->usuario?->nombre,
            'horas'      => $p->horas !== null ? (float) $p->horas : null,
            'calidad'    => $p->calidad,
            'comentario' => $p->comentario,
        ])->values()->all();
    }

    /**
     * ¿Este paso es trabajo de esta persona?
     *
     * El proceso y la línea van juntos siempre: son las dos mitades de la
     * misma pregunta, y separarlas es lo que dejaría a "Mis pasos" mostrando
     * algo que luego no se puede cerrar.
     */
    private function puedeTrabajar(Usuario $usuario, ProduccionPaso $paso): bool
    {
        return in_array(
            $paso->tipo_proceso,
            $usuario->procesosQuePuedeTrabajar($paso->linea),
            true,
        );
    }

    /** Un "no autorizado" que explique cuál de las dos cosas falló. */
    private function porQueNoPuede(ProduccionPaso $paso): string
    {
        if (TipoProceso::separaRestauraciones() && $paso->esRestauracion()) {
            return 'Este paso es de una restauración y las lleva otro encargado.';
        }

        return 'No autorizado para este proceso.';
    }

    /** Los pasos que le tocan: su proceso y, si se separan, su línea. */
    private function soloSusPasos($query, Usuario $usuario)
    {
        if (! TipoProceso::separaRestauraciones()) {
            return $query->whereIn('tipo_proceso', $usuario->procesosQuePuedeTrabajar());
        }

        return $query->where(function ($q) use ($usuario) {
            foreach (TipoProceso::LINEAS as $linea) {
                $q->orWhere(fn ($sub) => $sub
                    ->where('linea', $linea)
                    ->whereIn('tipo_proceso', $usuario->procesosQuePuedeTrabajar($linea)));
            }
        });
    }

    private function notificarTrabajadores(string $tipoProceso, string $linea, int $produccionId, int $ordenId, string $productoNombre): void
    {
        $label = ProduccionPaso::labelProceso($tipoProceso);

        // A quien se avisa sale del mismo sitio que quien puede hacerlo: los
        // trabajadores que tengan ese proceso asignado. Antes se cruzaba
        // además con el "perfil" de la persona, que era lo que obligaba a
        // darle un rol de taller a quien solo estaba encargado de un paso.
        $usuariosANotificar = Usuario::where('activo', true)->usaElPrograma()->aptoProduccion()
            ->whereHas('procesosAsignados', function ($p) use ($tipoProceso, $linea) {
                $p->where('clave', $tipoProceso);
                // Y en la línea de la pieza: avisarle al encargado de lo nuevo
                // de una restauración que no va a poder tocar es ruido.
                if (TipoProceso::separaRestauraciones()) {
                    // Sobre la tabla pivote a mano: dentro de un whereHas el
                    // callback trae un Builder normal, no la relación, así que
                    // wherePivotIn no existe aquí.
                    $p->whereIn('proceso_trabajadores.linea', [TipoProceso::LINEA_AMBAS, $linea]);
                }
            })
            ->get();

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
