<?php

namespace App\Http\Controllers;

use App\Events\DespachoAsignado;
use App\Events\OrdenEntregada;
use App\Models\Camion;
use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\Devolucion;
use App\Models\EntregaLinea;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Services\DescuentoCondicionadoService;
use App\Services\EntregaService;
use App\Services\NotificacionService;
use App\Support\ConvierteImagenesPdf;
use App\Support\StockVariantes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DespachoController extends Controller
{
    use ConvierteImagenesPdf;

    /**
     * GET /api/despacho/cola
     *
     * Lo que se puede subir a un camión y nadie está despachando: las órdenes
     * listas para entrega, y también las que siguen en el taller pero ya
     * tienen algo que entregar —el reloj mientras se fabrica el comedor—. En
     * esas la ruta lleva solo lo que está; lo demás vuelve a la cola cuando
     * el taller lo termine.
     *
     * Cada orden trae sus productos con cuánto falta y si ya se puede.
     */
    public function cola(Request $request)
    {
        $ordenes = Orden::with([
            'cliente:id,nombre,telefono,direccion',
            'tienda:id,nombre',
            'items.producto:id,nombre,foto_url',
            'items.produccion:id,orden_item_id,estado',
        ])->withSum('pagos', 'monto')
            ->whereIn('estado', ['listo_entrega', 'en_produccion', 'pendiente_anticipo'])
            ->whereDoesntHave('despachoItem', fn($q) =>
                $q->whereHas('despacho', fn($q2) =>
                    $q2->whereIn('estado', ['borrador', 'asignado', 'en_ruta'])
                )
            )
            // Lo listo primero, y dentro de eso lo que lleva más tiempo esperando.
            ->orderByRaw("estado = 'listo_entrega' DESC")
            ->orderBy('listo_entrega_at')
            ->orderBy('created_at')
            ->get()
            // Del taller solo entra lo que tiene algo listo para hoy.
            ->filter(fn ($o) => self::cabeEnRuta($o))
            ->values();

        $ordenes->transform(function ($o) {
            $o->total_pagado    = (float) ($o->pagos_sum_monto ?? 0);
            $o->saldo_pendiente = (float) $o->valor_total - $o->total_pagado;
            unset($o->pagos_sum_monto);
            self::anotarEntregables($o);
            return $o;
        });

        return response()->json($ordenes);
    }

    /** Deja en cada producto cuánto falta por entregar y si ya se puede. */
    private static function anotarEntregables(Orden $orden): void
    {
        $orden->items->each(function ($oi) {
            $oi->pendiente_entregar = $oi->pendienteEntregar();
            $oi->entregable         = $oi->estaListoParaEntregar();
            $oi->produccion_estado  = $oi->produccion?->estado;
            unset($oi->produccion);
        });
        $orden->entrega = $orden->resumenEntrega();
    }

    /**
     * Lo que va en una entrega de ruta, leído de la petición: [{orden_item_id,
     * cantidad}]. Sin lista, todo lo que se pueda entregar hoy. Devuelve el
     * mapa limpio o el mensaje de por qué no cuadra.
     *
     * @return array<int,int>|string
     */
    private function lineasParaRuta(Request $request, Orden $orden): array|string
    {
        $orden->loadMissing('items.produccion', 'items.producto:id,nombre');
        $crudo = $request->input('lineas');

        if (! $crudo) {
            $todo = EntregaService::entregablesDe($orden);
            return $todo ?: 'Esta orden no tiene nada que se pueda entregar todavía.';
        }

        $lista = is_array($crudo) ? $crudo : json_decode($crudo, true);
        if (! is_array($lista)) return 'No se entendió qué va en la ruta.';

        return EntregaService::validarLineas($orden, $lista);
    }

    /**
     * ¿Se puede subir esta orden a un camión?
     *
     * Lista para entrega, como siempre. O una orden MIXTA —algo de catálogo y
     * algo que se fabrica— que todavía espera al taller pero ya tiene lo de
     * catálogo listo: el reloj sale hoy y el comedor cuando esté. Una orden
     * solo de catálogo no entra por aquí: esa la pone lista el supervisor.
     */
    private static function cabeEnRuta(Orden $orden): bool
    {
        if ($orden->estado === 'listo_entrega') return true;
        if (! in_array($orden->estado, ['en_produccion', 'pendiente_anticipo'], true)) return false;

        $orden->loadMissing('items.produccion');
        $esperaTaller = $orden->items->contains(fn ($i) =>
            $i->vaAlTaller() && $i->pendienteEntregar() > 0 && ! $i->estaListoParaEntregar()
        );

        return $esperaTaller && $orden->itemsEntregables()->isNotEmpty();
    }

    // ── Rutas (borradores) ────────────────────────────────────────────────────

    /**
     * PATCH /api/despacho/{id}/reprogramar
     * Supervisor reprograma la fecha de una ruta ya enviada (asignado).
     */
    public function reprogramarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'fecha_despacho' => 'required|date',
        ]);

        $despacho = Despacho::whereIn('estado', ['asignado', 'borrador'])->findOrFail($id);
        $despacho->update(['fecha_despacho' => $data['fecha_despacho']]);

        // Notificar al conductor de la nueva fecha
        if ($despacho->conductor_id) {
            $fechaFmt   = \Carbon\Carbon::parse($data['fecha_despacho'])->locale('es')->isoFormat('D [de] MMMM');
            $nombreRuta = $despacho->nombre_ruta ?? "Ruta #{$despacho->id}";
            NotificacionService::crear(
                'ruta_atrasada',
                'Fecha de ruta actualizada',
                "{$nombreRuta} ha sido reprogramada para el {$fechaFmt}",
                ['despacho_id' => $despacho->id],
                $despacho->conductor_id,
            );
        }

        return response()->json($despacho);
    }

    /**
     * GET /api/despacho/rutas
     * Lista rutas en borrador del supervisor.
     */
    public function rutas(Request $request)
    {
        $rutas = Despacho::with([
            'camion:id,nombre',
            'items' => fn($q) => $q->orderBy('posicion'),
            'items.orden:id,cliente_id,valor_total,estado',
            'items.orden.cliente:id,nombre,telefono,direccion',
            'items.orden.pagos:id,orden_id,monto',
            'items.orden.items:id,orden_id,producto_id,nombre_custom,cantidad,cantidad_entregada,devuelto_en',
            'items.orden.items.producto:id,nombre',
            'items.lineas',
        ])->where('estado', 'borrador')
            ->orderBy('fecha_despacho')
            ->orderByDesc('created_at')
            ->get();

        $rutas->each(fn($ruta) => $ruta->items->each(function ($item) {
            if ($item->orden) {
                $pagado = (float) $item->orden->pagos->sum('monto');
                $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $pagado;
                unset($item->orden->pagos);
            }
        }));

        return response()->json($rutas);
    }

    /**
     * POST /api/despacho/rutas
     * Crea una ruta en borrador (nombre + fecha, sin órdenes aún).
     */
    public function crearRuta(Request $request)
    {
        $data = $request->validate([
            'nombre_ruta'    => 'required|string|max:120',
            'fecha_despacho' => 'required|date',
            'instrucciones'  => 'nullable|string|max:2000',
        ]);

        $despacho = Despacho::create([
            'supervisor_id'  => $request->user()->id,
            'estado'         => 'borrador',
            'nombre_ruta'    => $data['nombre_ruta'],
            'fecha_despacho' => $data['fecha_despacho'],
            'instrucciones'  => $data['instrucciones'] ?? null,
        ]);

        return response()->json($despacho, 201);
    }

    /**
     * PATCH /api/despacho/rutas/{id}
     * Edita nombre, fecha o instrucciones de una ruta borrador.
     */
    public function actualizarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'nombre_ruta'    => 'sometimes|nullable|string|max:120',
            'fecha_despacho' => 'sometimes|required|date',
            'instrucciones'  => 'sometimes|nullable|string|max:2000',
        ]);

        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);
        $ruta->update($data);

        return response()->json($ruta);
    }

    /**
     * DELETE /api/despacho/rutas/{id}
     * Elimina una ruta borrador y devuelve sus órdenes a la cola.
     */
    public function eliminarRuta(int $id)
    {
        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);

        DB::transaction(function () use ($ruta) {
            $ruta->items()->delete();
            $ruta->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/despacho/rutas/{id}/ordenes
     * Agrega una orden de la cola a una ruta borrador.
     */
    public function agregarOrdenARuta(Request $request, int $id)
    {
        $data  = $request->validate([
            'orden_id' => 'required|exists:ordenes,id',
            // Qué productos van en el camión: [{orden_item_id, cantidad}].
            // Sin lista, todo lo que se pueda entregar hoy.
            'lineas'   => 'nullable',
        ]);
        $ruta  = Despacho::where('estado', 'borrador')->findOrFail($id);
        $orden = Orden::with('items.produccion')->findOrFail($data['orden_id']);

        if (! self::cabeEnRuta($orden)) {
            return response()->json(['message' => 'La orden no está en cola de entrega.'], 422);
        }

        $yaEnRuta = DespachoItem::where('orden_id', $data['orden_id'])
            ->whereHas('despacho', fn($q) => $q->whereIn('estado', ['borrador', 'asignado', 'en_ruta']))
            ->exists();

        if ($yaEnRuta) {
            return response()->json(['message' => 'Esta orden ya está en una ruta activa.'], 422);
        }

        $lineas = $this->lineasParaRuta($request, $orden);
        if (is_string($lineas)) {
            return response()->json(['message' => $lineas], 422);
        }

        $posicion = $ruta->items()->count() + 1;

        $item = DespachoItem::create([
            'despacho_id' => $ruta->id,
            'orden_id'    => $data['orden_id'],
            'posicion'    => $posicion,
            'estado'      => 'pendiente',
        ]);
        EntregaService::fijarLineas($item, $lineas);

        $item->load(
            'orden:id,cliente_id,valor_total,estado', 'orden.cliente:id,nombre,telefono,direccion', 'orden.pagos:id,orden_id,monto',
            'orden.items:id,orden_id,producto_id,nombre_custom,cantidad,cantidad_entregada,devuelto_en', 'orden.items.producto:id,nombre',
            'lineas',
        );
        if ($item->orden) {
            $pagado = (float) $item->orden->pagos->sum('monto');
            $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $pagado;
            unset($item->orden->pagos);
        }

        return response()->json($item, 201);
    }

    /**
     * DELETE /api/despacho/rutas/{id}/ordenes/{itemId}
     * Quita una orden de una ruta borrador (la devuelve a la cola).
     */
    public function quitarOrdenDeRuta(int $id, int $itemId)
    {
        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);
        $item = DespachoItem::where('despacho_id', $ruta->id)->findOrFail($itemId);
        $item->lineas()->delete();
        $item->delete();

        // Reindexar posiciones
        $ruta->items()->orderBy('posicion')->get()->each(function ($it, $i) {
            $it->update(['posicion' => $i + 1]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/despacho/rutas/{id}/reordenar
     * Actualiza las posiciones de los items de una ruta borrador.
     */
    public function reordenarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'items'            => 'required|array',
            'items.*.id'       => 'required|integer',
            'items.*.posicion' => 'required|integer|min:1',
        ]);

        $ruta = Despacho::where('estado', 'borrador')->findOrFail($id);

        DB::transaction(function () use ($data, $ruta) {
            foreach ($data['items'] as $itemData) {
                DespachoItem::where('despacho_id', $ruta->id)
                    ->where('id', $itemData['id'])
                    ->update(['posicion' => $itemData['posicion']]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/despacho/rutas/{id}/enviar
     * Cierra la ruta: asigna camión, cambia estados y notifica al conductor.
     */
    public function enviarRuta(Request $request, int $id)
    {
        $data = $request->validate([
            'camion_id'     => 'required|exists:camiones,id',
            'nombre_ruta'   => 'sometimes|nullable|string|max:120',
            'instrucciones' => 'sometimes|nullable|string|max:2000',
        ]);
        $ruta    = Despacho::with('items.orden')->where('estado', 'borrador')->findOrFail($id);
        $usuario = $request->user();

        if ($ruta->items->isEmpty()) {
            return response()->json(['message' => 'La ruta no tiene órdenes asignadas.'], 422);
        }

        $camion = Camion::findOrFail($data['camion_id']);
        if (! $camion->conductor_id) {
            return response()->json(['message' => 'El camión no tiene conductor asignado.'], 422);
        }

        $conductor = Usuario::findOrFail($camion->conductor_id);
        if (! $conductor->activo || $conductor->rol !== 'conductor') {
            return response()->json(['message' => 'El conductor del camión no está disponible.'], 422);
        }

        DB::transaction(function () use ($ruta, $camion, $conductor, $usuario, $data) {
            // Las órdenes NO pasan a en_camino aquí — lo hacen cuando el conductor inicia la ruta
            $update = [
                'camion_id'    => $camion->id,
                'conductor_id' => $conductor->id,
                'supervisor_id' => $usuario->id,
                'estado'       => 'asignado',
            ];
            if (array_key_exists('nombre_ruta', $data))   $update['nombre_ruta']   = $data['nombre_ruta'];
            if (array_key_exists('instrucciones', $data)) $update['instrucciones'] = $data['instrucciones'];
            $ruta->update($update);
        });

        event(new DespachoAsignado($ruta->id, $conductor->id, $ruta->items->count()));

        $fechaFmt   = \Carbon\Carbon::parse($ruta->fecha_despacho)->locale('es')->isoFormat('D [de] MMMM');
        $nombreRuta = $ruta->nombre_ruta ? " — {$ruta->nombre_ruta}" : '';

        NotificacionService::crear(
            'despacho_asignado',
            'Nuevas entregas asignadas',
            "Tienes {$ruta->items->count()} entrega(s) para el {$fechaFmt}{$nombreRuta}",
            ['despacho_id' => $ruta->id],
            $conductor->id,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/despacho/asignados
     * Órdenes en estado en_camino agrupadas por despacho (camión + fecha).
     */
    public function asignados(Request $request)
    {
        $camionId = $request->query('camion_id');
        $desde    = $request->query('desde');
        $hasta    = $request->query('hasta');

        $items = DespachoItem::with([
            'despacho.camion:id,nombre,placa',
            'despacho.conductor:id,nombre',
            'despacho.supervisor:id,nombre',
            'orden:id,cliente_id,tienda_id,valor_total,estado,created_at',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.pagos:id,orden_id,monto',
            'orden.items:id,orden_id,producto_id,nombre_custom,cantidad,cantidad_entregada,devuelto_en',
            'orden.items.producto:id,nombre',
            'lineas',
        ])->whereHas('despacho', function ($q) use ($camionId, $desde, $hasta) {
            $q->whereIn('estado', ['asignado', 'en_ruta'])->where('tipo', 'ruta');
            if ($camionId) $q->where('camion_id', $camionId);
            if ($desde)    $q->whereDate('fecha_despacho', '>=', $desde);
            if ($hasta)    $q->whereDate('fecha_despacho', '<=', $hasta);
        })
            ->orderBy('despacho_id')
            ->orderBy('posicion')
            ->get();

        $items->each(function ($item) {
            if ($item->orden) {
                $totalPagado = (float) $item->orden->pagos->sum('monto');
                $item->orden->total_pagado    = $totalPagado;
                $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $totalPagado;
                unset($item->orden->pagos);
            }
        });

        $agrupado = $items->groupBy('despacho_id')->values();

        return response()->json($agrupado);
    }

    /**
     * POST /api/despacho/asignar
     * Crea un despacho asignado a un camión (el conductor se toma del camión).
     */
    public function asignar(Request $request)
    {
        $data = $request->validate([
            'camion_id'          => 'required|exists:camiones,id',
            'fecha_despacho'     => 'required|date',
            'ordenes'            => 'required|array|min:1',
            'ordenes.*.orden_id' => 'required|exists:ordenes,id',
            'ordenes.*.posicion' => 'required|integer|min:1',
            // Qué va de cada orden; sin lista, todo lo que se pueda entregar.
            'ordenes.*.lineas'   => 'nullable|array',
            'nombre_ruta'        => 'nullable|string|max:120',
            'instrucciones'      => 'nullable|string|max:2000',
            'notas'              => 'nullable|string|max:1000',
        ]);

        $camion = Camion::findOrFail($data['camion_id']);

        if (! $camion->conductor_id) {
            return response()->json(['message' => 'El camión no tiene conductor asignado.'], 422);
        }

        $conductor = Usuario::findOrFail($camion->conductor_id);

        if ($conductor->rol !== 'conductor') {
            return response()->json(['message' => 'El usuario asignado al camión no es un conductor.'], 422);
        }
        if (! $conductor->activo) {
            return response()->json(['message' => 'El conductor del camión no está activo.'], 422);
        }

        $usuario = $request->user();

        $despacho = DB::transaction(function () use ($data, $usuario, $camion, $conductor) {
            $despacho = Despacho::create([
                'camion_id'      => $camion->id,
                'conductor_id'   => $conductor->id,
                'supervisor_id'  => $usuario->id,
                'fecha_despacho' => $data['fecha_despacho'],
                'estado'         => 'asignado',
                'nombre_ruta'    => $data['nombre_ruta']   ?? null,
                'instrucciones'  => $data['instrucciones'] ?? null,
                'notas'          => $data['notas']         ?? null,
            ]);

            foreach ($data['ordenes'] as $item) {
                $orden = Orden::with('items.produccion', 'items.producto:id,nombre')
                    ->lockForUpdate()->findOrFail($item['orden_id']);

                if (! self::cabeEnRuta($orden)) {
                    abort(422, "La orden {$orden->referencia} no tiene nada listo para entregar.");
                }

                $lineas = ! empty($item['lineas'])
                    ? EntregaService::validarLineas($orden, $item['lineas'])
                    : EntregaService::entregablesDe($orden);
                if (is_string($lineas)) abort(422, $lineas);
                if (! $lineas) abort(422, "La orden {$orden->referencia} no tiene nada listo para entregar.");

                $yaAsignada = DespachoItem::where('orden_id', $item['orden_id'])
                    ->whereHas('despacho', fn($q) => $q->whereIn('estado', ['borrador', 'asignado', 'en_ruta']))
                    ->exists();

                if ($yaAsignada) {
                    abort(422, "La orden {$orden->referencia} ya está en un despacho activo.");
                }

                $entrega = DespachoItem::create([
                    'despacho_id' => $despacho->id,
                    'orden_id'    => $item['orden_id'],
                    'posicion'    => $item['posicion'],
                    'estado'      => 'pendiente',
                ]);
                EntregaService::fijarLineas($entrega, $lineas);

                $orden->update(['estado' => 'en_camino']);
            }

            return $despacho;
        });

        $despacho->load('items.orden.cliente:id,nombre', 'conductor:id,nombre', 'camion:id,nombre,placa');

        event(new DespachoAsignado(
            $despacho->id,
            $conductor->id,
            count($data['ordenes']),
        ));

        $nombreCamion = $camion->nombre ? " — {$camion->nombre}" : '';
        $fechaFmt     = \Carbon\Carbon::parse($data['fecha_despacho'])->locale('es')->isoFormat('D [de] MMMM');

        NotificacionService::crear(
            'despacho_asignado',
            'Nuevas entregas asignadas',
            "Tienes " . count($data['ordenes']) . " entrega(s) para el {$fechaFmt}{$nombreCamion}",
            ['despacho_id' => $despacho->id],
            $conductor->id,
        );

        return response()->json($despacho, 201);
    }

    /**
     * GET /api/despacho/conductores
     * Lista de conductores activos.
     */
    public function conductores(Request $request)
    {
        $conductores = Usuario::where('rol', 'conductor')
            ->where('activo', true)
            ->get(['id', 'nombre', 'email', 'tienda_default_id']);

        return response()->json($conductores);
    }

    /**
     * GET /api/despacho/historial
     */
    public function historial(Request $request)
    {
        $query = Despacho::with([
            'camion:id,nombre,placa',
            'conductor:id,nombre',
            'items.orden.cliente:id,nombre',
        ])->where('estado', 'completado')->where('tipo', 'ruta');

        if ($v = $request->query('camion_id')) {
            $query->where('camion_id', $v);
        }
        if ($v = $request->query('desde')) {
            $query->whereDate('fecha_despacho', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('fecha_despacho', '<=', $v);
        }
        // Búsqueda por nombre de conductor — para encontrar, por ejemplo,
        // todas las entregas de una cuenta de prueba y confirmar cuáles
        // fueron reales.
        if ($v = $request->query('conductor')) {
            $query->whereHas('conductor', fn ($q) => $q->where('nombre', 'like', "%{$v}%"));
        }

        return response()->json($query->orderByDesc('fecha_despacho')->paginate(20));
    }

    /**
     * GET /api/despacho/{id}
     */
    public function show(int $id)
    {
        $despacho = Despacho::with([
            'conductor:id,nombre',
            'supervisor:id,nombre',
            'items.orden.cliente:id,nombre,telefono,direccion',
            'items.orden.tienda:id,nombre',
            'items.orden.pagos',
            'items.orden.items.producto:id,nombre',
            'items.lineas',
        ])->findOrFail($id);

        $despacho->items->each(function ($item) {
            $item->orden->total_pagado    = (float) $item->orden->pagos->sum('monto');
            $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $item->orden->total_pagado;
        });

        return response()->json($despacho);
    }

    /**
     * GET /api/despacho/por-orden/{ordenId}
     * Devuelve los datos del despacho_item y despacho para una orden entregada.
     * Accesible por supervisor, vendedor y conductor.
     */
    public function porOrden(int $ordenId)
    {
        $item = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'despacho.entregadoPor:id,nombre',
            'despacho.supervisor:id,nombre',
        ])->where('orden_id', $ordenId)->latest('id')->first();

        if (! $item) {
            return response()->json(null);
        }

        return response()->json($item);
    }

    /**
     * GET /api/despacho/entregas-de/{ordenId}
     *
     * Todas las entregas que ya se hicieron de una orden, con qué llevó cada
     * una. Con entregas por producto una orden puede tener varias —el reloj
     * un día, el comedor otro— y cada una tiene su acta y sus fotos; ver solo
     * la última dejaba las anteriores como si no hubieran pasado.
     */
    public function entregasDe(Request $request, int $ordenId)
    {
        $usuario = $request->user();
        $orden   = Orden::with('items.producto:id,nombre')->findOrFail($ordenId);

        if (! $orden->laPuedeVer($usuario) && ! $usuario->acceso_despacho && ! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $nombres = $orden->items->mapWithKeys(fn ($i) => [$i->id => $i->producto->nombre ?? $i->nombre_custom ?? 'Producto']);

        $entregas = DespachoItem::with([
            'despacho:id,tipo,estado,conductor_id,entregado_por_id,fecha_despacho',
            'despacho.conductor:id,nombre', 'despacho.entregadoPor:id,nombre',
            'lineas',
        ])
            ->where('orden_id', $orden->id)
            ->whereIn('estado', ['entregado', 'devuelto'])
            ->orderByDesc('entregado_at')->orderByDesc('id')
            ->get()
            ->map(function (DespachoItem $e) use ($nombres) {
                $agrupa = fn ($lineas) => $lineas->groupBy('orden_item_id')
                    ->map(fn ($g, $itemId) => [
                        'orden_item_id' => (int) $itemId,
                        'nombre'        => $nombres[$itemId] ?? 'Producto',
                        'cantidad'      => (int) $g->sum('cantidad'),
                    ])->values();

                return [
                    'id'            => $e->id,
                    'estado'        => $e->estado,
                    'entregado_at'  => $e->entregado_at,
                    'tipo'          => $e->despacho?->tipo,
                    'quien'         => $e->despacho?->quienEntrega(),
                    // Sin líneas: entrega de antes de las parciales, llevó todo.
                    'llevo_todo'    => $e->lineas->isEmpty(),
                    'entregados'    => $agrupa($e->lineas->whereIn('resultado', EntregaLinea::SE_QUEDO)),
                    'devueltos'     => $agrupa($e->lineas->where('resultado', EntregaLinea::DEVUELTO)),
                    'conforme'      => $e->conforme,
                    'observaciones' => $e->observaciones_entrega,
                    'tiene_acta'    => $e->firma_recibido_url !== null,
                    'firma_omitida_motivo' => $e->firma_omitida_motivo,
                    'recibido_por'  => $e->recibido_por_nombre,
                    'foto_producto' => $e->foto_producto,
                    'foto_pago'     => $e->foto_pago,
                ];
            });

        return response()->json($entregas);
    }

    // ── Entrega directa (sin ruta ni conductor) ───────────────────────────────

    /**
     * POST /api/despacho/entrega-directa  { orden_id }
     *
     * El camino corto para cuando los conductores no usan el programa: el
     * vendedor o supervisor dueño de la orden abre la entrega él mismo.
     *
     * Crea un despacho sintético (tipo=directa, sin camión ni conductor, con
     * `entregado_por_id`) y su item. De ahí en adelante la entrega corre por
     * exactamente los mismos endpoints y la misma pantalla que la de un
     * conductor — mismo descuento de inventario, mismo cierre de producción,
     * misma acta. El permiso `acceso_entregas` ya lo validó el middleware.
     */
    public function crearEntregaDirecta(Request $request)
    {
        $data    = $request->validate(['orden_id' => 'required|exists:ordenes,id']);
        $usuario = $request->user();

        $item = DB::transaction(function () use ($data, $usuario) {
            $orden = Orden::lockForUpdate()->findOrFail($data['orden_id']);

            // ¿Ya la está despachando alguien? (un conductor con su ruta, u otra
            // entrega directa). Se mira antes que el permiso porque la mía sin
            // terminar se reusa: el vendedor volvió a abrir la pantalla y no hay
            // que duplicarle el despacho.
            $yaActiva = DespachoItem::with('despacho')
                ->where('orden_id', $orden->id)
                ->whereHas('despacho', fn ($q) => $q->whereIn('estado', ['borrador', 'asignado', 'en_ruta']))
                ->first();

            if ($yaActiva) {
                if ($yaActiva->despacho->esDirecta()
                    && (int) $yaActiva->despacho->entregado_por_id === (int) $usuario->id) {
                    return $yaActiva;
                }
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => 'Esta orden ya está en un despacho activo.'], 422)
                );
            }

            if (! $orden->laPuedeEntregarDirecto($usuario)) {
                $motivo = ! $orden->tieneAlgoParaEntregar()
                    ? 'Todavía no hay nada que entregar: lo de catálogo ya se entregó y lo demás sigue en el taller.'
                    : 'Esta orden no es tuya o salió de otra tienda.';
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => $motivo], 422)
                );
            }

            return EntregaService::abrirDirecta($orden, $usuario);
        });

        return response()->json(['despacho_item_id' => $item->id], 201);
    }

    /**
     * DELETE /api/despacho/entrega-directa/{ordenId}
     *
     * Cancela una entrega directa empezada y no terminada — la orden vuelve
     * a la cola. Solo quien la abrió o un supervisor, y solo si todavía no
     * se registró el pago/las fotos (después de eso hay plata y acta de por
     * medio: se completa la entrega o un supervisor la revierte).
     */
    public function cancelarEntregaDirecta(Request $request, int $ordenId)
    {
        $usuario = $request->user();

        $item = DespachoItem::with('despacho')
            ->where('orden_id', $ordenId)
            ->where('estado', 'pendiente')
            ->whereHas('despacho', fn ($q) => $q->where('tipo', 'directa'))
            ->firstOrFail();

        $esSuya = (int) $item->despacho->entregado_por_id === (int) $usuario->id;
        if (! $esSuya && $usuario->rol !== 'supervisor') {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($item->foto_producto !== null) {
            return response()->json([
                'message' => 'Ya registraste el pago y las fotos. Completa la entrega o pide a un supervisor que la revierta.',
            ], 422);
        }

        DB::transaction(function () use ($item) {
            $despacho = $item->despacho;
            $item->delete();
            $despacho->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/despacho/mis-entregas/rutas/{despachoId}/iniciar
     * Conductor marca una ruta como "en proceso" (asignado → en_ruta).
     * Solo si ya es el día de la fecha asignada.
     */
    public function iniciarRuta(Request $request, int $despachoId)
    {
        $usuario  = $request->user();
        $despacho = Despacho::findOrFail($despachoId);

        if ((int) $despacho->conductor_id !== (int) $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($despacho->estado !== 'asignado') {
            return response()->json(['message' => 'Esta ruta ya está en proceso o fue completada.'], 422);
        }

        // Solo puede iniciar si ya llegó o pasó la fecha de despacho
        if ($despacho->fecha_despacho && $despacho->fecha_despacho->gt(now()->startOfDay())) {
            $fechaFmt = $despacho->fecha_despacho->locale('es')->isoFormat('dddd D [de] MMMM');
            return response()->json([
                'message' => "Esta ruta está programada para el {$fechaFmt}. Aún no puedes iniciarla.",
            ], 422);
        }

        DB::transaction(function () use ($despacho) {
            // Ahora sí pasan a en_camino — el conductor está saliendo
            foreach ($despacho->items()->with('orden')->get() as $item) {
                $item->orden?->update(['estado' => 'en_camino']);
            }
            $despacho->update(['estado' => 'en_ruta']);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/despacho/mis-entregas
     * Conductor autenticado: lista sus entregas activas ordenadas por posicion.
     */
    public function misEntregas(Request $request)
    {
        $usuario = $request->user();

        $items = DespachoItem::with([
            'despacho:id,conductor_id,estado,nombre_ruta,instrucciones,notas,fecha_despacho',
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
            'orden.pagos:id,orden_id,monto',
            'lineas',
        ])->whereHas('despacho', function ($q) use ($usuario) {
            $q->where('conductor_id', $usuario->id)
                ->whereIn('estado', ['asignado', 'en_ruta']);
        })->where('estado', 'pendiente')
            ->orderBy('despacho_id')  // despachos más antiguos primero (los que ya venía haciendo)
            ->orderBy('posicion')     // dentro de cada despacho, el orden asignado
            ->get()
            ->unique('orden_id')  // evita duplicados si la misma orden está en dos despachos activos
            ->values();

        $items->each(function ($item) {
            $totalPagado = (float) $item->orden->pagos->sum('monto');
            $item->orden->total_pagado    = $totalPagado;
            $item->orden->saldo_pendiente = (float) $item->orden->valor_total - $totalPagado;
            unset($item->orden->pagos);
        });

        return response()->json($items);
    }

    /**
     * GET /api/despacho/mis-entregas/historial
     * Conductor autenticado: entregas ya completadas, paginadas.
     */
    public function misHistorial(Request $request)
    {
        $usuario = $request->user();

        $items = DespachoItem::with([
            'orden.cliente:id,nombre,telefono,direccion',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
        ])->whereHas('despacho', function ($q) use ($usuario) {
            $q->where('conductor_id', $usuario->id);
        })->where('estado', 'entregado')
            ->orderByDesc('entregado_at')
            ->paginate(20);

        return response()->json($items);
    }

    /**
     * ¿Este usuario puede operar esta entrega?
     *
     * El conductor al que se le asignó la ruta, o —cuando es una entrega
     * directa— quien la abrió (vendedor/supervisor).
     */
    private function puedeOperarEntrega(DespachoItem $item, Usuario $usuario): bool
    {
        return (int) $item->despacho->conductor_id     === (int) $usuario->id
            || (int) $item->despacho->entregado_por_id === (int) $usuario->id;
    }

    /**
     * GET /api/despacho/mis-entregas/{despachoItemId}
     */
    public function showEntrega(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();

        $item = DespachoItem::with([
            'despacho:id,conductor_id,entregado_por_id,notas',
            'orden.cliente:id,nombre,telefono,direccion,cedula',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre,foto_url',
            'orden.items.produccion:id,orden_item_id,estado',
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
            'orden.pagos:id,orden_id,monto,metodo,referencia,created_at',
            'lineas',
        ])->findOrFail($despachoItemId);

        if (! $this->puedeOperarEntrega($item, $usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $item->orden->total_pagado    = $item->orden->totalPagado();
        $item->orden->saldo_pendiente = $item->orden->saldoPendiente();

        // Qué se puede entregar hoy y cuánto falta de cada cosa: el
        // formulario deja escoger qué va en ESTA entrega. Lo que ya se
        // entregó o sigue en el taller se ve, pero no se puede marcar.
        $item->orden->items->each(function ($oi) {
            $oi->pendiente_entregar = $oi->pendienteEntregar();
            $oi->entregable         = $oi->estaListoParaEntregar();
            $oi->produccion_estado  = $oi->produccion?->estado;
            unset($oi->produccion);
        });
        $item->orden->entrega = $item->orden->resumenEntrega();

        // Descuento que se pierde si el cliente paga con tarjeta: el conductor
        // necesita saber cuánto tendría que cobrar en ese caso y por qué, para
        // poder explicárselo al cliente en el momento.
        $orden = $item->orden;
        if ($orden->tieneDescuentoCondicionadoVivo()) {
            $item->descuento_condicionado = [
                'monto'               => (float) $orden->descuento_condicionado,
                'pct'                 => (float) $orden->descuento_condicionado_pct,
                'valor_actual'        => (float) $orden->valor_total,
                'valor_sin_descuento' => $orden->valorSinDescuentoCondicionado(),
                'saldo_actual'        => round($orden->saldoPendiente(), 2),
                'saldo_sin_descuento' => round($orden->valorSinDescuentoCondicionado() - $orden->totalPagado(), 2),
                'metodos_que_lo_conservan' => Orden::METODOS_CON_DESCUENTO,
                'explicacion'         => DescuentoCondicionadoService::explicacion($orden),
            ];
        }

        return response()->json($item);
    }

    /**
     * PATCH /api/despacho/mis-entregas/{despachoItemId}/lineas  { lineas: [{orden_item_id, cantidad}] }
     *
     * Deja escrito qué va en esta entrega ANTES de entregarla. Es lo que hace
     * que la orden de entrega impresa diga lo que de verdad sale hoy y no
     * todo lo que falta: si se entregan 2 de 4, la hoja lleva esos 2. Al
     * registrar el pago las líneas se vuelven a escribir con lo que se marcó
     * en el formulario, así que esto no amarra nada.
     */
    public function fijarLineasEntrega(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();
        $item    = DespachoItem::with('despacho', 'orden')->findOrFail($despachoItemId);

        if (! $this->puedeOperarEntrega($item, $usuario) && ! $usuario->acceso_despacho && $usuario->rol !== 'supervisor') {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($item->estado !== 'pendiente') {
            return response()->json(['message' => 'Esta entrega ya se cerró: lo que llevó ya quedó escrito.'], 422);
        }

        $lineas = $this->leerLineas($request, $item);
        if ($lineas instanceof \Illuminate\Http\JsonResponse) {
            return $lineas;
        }

        EntregaService::fijarLineas($item, $lineas);

        return response()->json(['lineas' => $item->lineas()->get()]);
    }

    /**
     * POST /api/despacho/mis-entregas/{despachoItemId}/pago
     * Multipart: monto, metodo, referencia, foto_producto, foto_pago
     */
    public function registrarPago(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();
        $item    = DespachoItem::with('despacho', 'orden')->findOrFail($despachoItemId);

        if (! $this->puedeOperarEntrega($item, $usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($item->estado === 'entregado') {
            return response()->json(['message' => 'Esta entrega ya fue completada.'], 422);
        }

        $saldoPendiente = $item->orden->saldoPendiente();

        // ── Qué va en esta entrega ───────────────────────────────────────────
        // Por producto: el reloj hoy, el mueble cuando salga. Sin lista, va
        // todo lo que falte y se pueda entregar (así funcionaban las entregas
        // antes de las parciales, y así sigue funcionando la del conductor).
        $lineas = $this->leerLineas($request, $item);
        if ($lineas instanceof \Illuminate\Http\JsonResponse) {
            return $lineas;
        }

        // ── Lo que se devuelve ───────────────────────────────────────────────
        // Se lee antes de validar el resto porque cambia las reglas: si el
        // cliente devuelve todo no hay nada que cobrar, y exigirle el pago al
        // conductor lo dejaría trabado en la puerta de la casa.
        $devoluciones = $this->leerDevoluciones($request, $item, $lineas);
        if ($devoluciones instanceof \Illuminate\Http\JsonResponse) {
            return $devoluciones;
        }

        $devueltoPorItem = collect($devoluciones)->groupBy('orden_item_id')
            ->map(fn ($g) => (int) collect($g)->sum('cantidad'))->all();
        $devuelveTodo = $devoluciones && $this->devuelveTodoLoQueVa($lineas, $devueltoPorItem);

        // El saldo se cobra cuando el cliente queda con TODO lo que compró.
        // En una entrega parcial todavía le falta recibir algo, así que el
        // pago es opcional: puede abonar, no se le exige.
        $esLaUltima   = $this->completaLaOrden($item->orden, $lineas, $devueltoPorItem);
        $requierePago = $saldoPendiente > 0.01 && $esLaUltima && ! $devuelveTodo;
        $traePago     = $requierePago || ((float) $request->input('monto', 0) > 0);

        $data = $request->validate([
            'monto'         => $requierePago ? 'required|numeric|min:1'                         : 'nullable|numeric|min:0',
            'metodo'        => $traePago     ? 'required|in:efectivo,transferencia,tarjeta,otro' : 'nullable|in:efectivo,transferencia,tarjeta,otro',
            'referencia'    => 'nullable|string|max:100',
            'foto_producto' => 'required|image|max:10240',
            // Una o varias fotos del comprobante: `foto_pago` (una) o
            // `fotos_pago[]` (lista). Obligatoria si hay pago.
            'foto_pago'     => 'nullable|image|max:10240',
            'fotos_pago'    => 'nullable|array|max:6',
            'fotos_pago.*'  => 'image|max:10240',
            'foto_anexo'    => 'nullable|image|max:10240',

            // ── Acta de satisfacción ─────────────────────────────────────────
            // La firma es obligatoria salvo que se explique por qué no se pudo
            // conseguir: sin eso, el acta no respalda nada.
            'firma_recibido'       => 'nullable|image|max:10240',
            'firma_omitida_motivo' => 'nullable|string|max:300',
            'recibido_por_nombre'  => 'nullable|string|max:150',
            'recibido_por_cedula'  => 'nullable|string|max:40',
            'conforme'             => 'nullable|boolean',
            'observaciones_entrega'=> 'nullable|string|max:500',
            'foto_novedad'         => 'nullable|image|max:10240',

            // ── Lo que se devuelve ───────────────────────────────────────────
            // JSON con [{orden_item_id, cantidad, motivo}]. Va por producto: en
            // el camión pueden ir la cama y dos mesas y volver solo la cama.
            'devoluciones'         => 'nullable|string',
            'foto_devolucion'      => 'nullable|image|max:10240',
        ]);

        $conforme = $request->has('conforme') ? $request->boolean('conforme') : null;

        $archivosPago = $request->hasFile('fotos_pago')
            ? array_values((array) $request->file('fotos_pago'))
            : ($request->hasFile('foto_pago') ? [$request->file('foto_pago')] : []);

        if ($traePago && ! $archivosPago) {
            return response()->json([
                'message' => 'Falta la foto del comprobante de pago.',
                'errors'  => ['foto_pago' => ['Sube la foto o el pantallazo del comprobante.']],
            ], 422);
        }

        if (! $request->hasFile('firma_recibido') && empty($data['firma_omitida_motivo'])) {
            return response()->json([
                'message' => 'Falta la firma de quien recibe. Si no hay quien firme, indica el motivo.',
                'errors'  => ['firma_recibido' => ['Se requiere la firma o el motivo por el que no se pudo obtener.']],
            ], 422);
        }

        if ($request->hasFile('firma_recibido') && empty($data['recibido_por_nombre'])) {
            return response()->json([
                'message' => 'Falta el nombre de quien recibe.',
                'errors'  => ['recibido_por_nombre' => ['Indica quién está recibiendo.']],
            ], 422);
        }

        // Si llegó con novedad hay que decir cuál: "con novedad" a secas no sirve
        // de nada cuando el reclamo llegue después.
        if ($conforme === false && empty($data['observaciones_entrega'])) {
            return response()->json([
                'message' => 'Describe la novedad con la que llegó el producto.',
                'errors'  => ['observaciones_entrega' => ['Explica qué pasó con el producto.']],
            ], 422);
        }

        // ── Descuento condicionado al medio de pago ──────────────────────────
        // Si el cliente paga con tarjeta pierde el descuento por efectivo o
        // transferencia: hay que quitarlo ANTES de validar el monto, porque el
        // saldo a cobrar sube y con el saldo viejo se rechazaría el pago correcto.
        $orden = $item->orden;
        $pierdeDescuento = $traePago
            && $orden->tieneDescuentoCondicionadoVivo()
            && Orden::metodoPierdeDescuento($data['metodo']);

        if ($pierdeDescuento) {
            $saldoExigido = round($orden->valorSinDescuentoCondicionado() - $orden->totalPagado(), 2);

            // Con tarjeta no se puede cobrar menos: el descuento ya no aplica.
            // Solo en la última entrega: en una parcial es un abono y el
            // descuento se pierde igual, pero el saldo se cobra al final.
            if ($requierePago && $data['monto'] < $saldoExigido - 0.01) {
                return response()->json([
                    'message' => 'Al pagar con ' . $data['metodo'] . ' el descuento no aplica: debes cobrar '
                        . '$' . number_format($saldoExigido, 0, ',', '.') . '.',
                    'descuento_condicionado' => [
                        'monto'               => (float) $orden->descuento_condicionado,
                        'saldo_sin_descuento' => $saldoExigido,
                        'explicacion'         => DescuentoCondicionadoService::explicacion($orden),
                    ],
                ], 422);
            }

            DescuentoCondicionadoService::quitar($orden, $usuario, $data['metodo']);
            $orden->refresh();
            $item->setRelation('orden', $orden);
            $saldoPendiente = $orden->saldoPendiente();
        }

        if ($traePago && $data['monto'] > $saldoPendiente + 0.01) {
            return response()->json([
                'message' => "El monto ({$data['monto']}) supera el saldo pendiente (" . round($saldoPendiente, 2) . ").",
            ], 422);
        }

        $fotoProducto = $this->subirCloudinary($request->file('foto_producto'));
        $fotosPago    = array_map(fn ($f) => $this->subirCloudinary($f), $archivosPago);
        $fotoPago     = $fotosPago[0] ?? null;
        $fotoAnexo    = $request->hasFile('foto_anexo')
            ? $this->subirCloudinary($request->file('foto_anexo'))
            : null;
        $firmaRecibido = $request->hasFile('firma_recibido')
            ? $this->subirCloudinary($request->file('firma_recibido'))
            : null;
        $fotoNovedad   = $request->hasFile('foto_novedad')
            ? $this->subirCloudinary($request->file('foto_novedad'))
            : null;
        $fotoDevolucion = $request->hasFile('foto_devolucion')
            ? $this->subirCloudinary($request->file('foto_devolucion'))
            : null;

        DB::transaction(function () use ($item, $data, $usuario, $fotoProducto, $fotoPago, $fotosPago, $fotoAnexo, $traePago, $esLaUltima, $firmaRecibido, $fotoNovedad, $conforme, $devoluciones, $fotoDevolucion, $lineas, $devueltoPorItem) {
            $item->update([
                'foto_producto' => $fotoProducto,
                'foto_pago'     => $fotoPago,
                'fotos_pago'    => $fotosPago ?: null,

                // Acta de satisfacción
                'firma_recibido_url'    => $firmaRecibido,
                'recibido_por_nombre'   => $data['recibido_por_nombre'] ?? null,
                'recibido_por_cedula'   => $data['recibido_por_cedula'] ?? null,
                'conforme'              => $conforme,
                'observaciones_entrega' => $data['observaciones_entrega'] ?? null,
                'foto_novedad_url'      => $fotoNovedad,
                'firma_omitida_motivo'  => $firmaRecibido ? null : ($data['firma_omitida_motivo'] ?? null),
            ]);

            if ($fotoAnexo) {
                $item->orden->update(['anexo_foto_url' => $fotoAnexo]);
            }

            // Qué se llevó el cliente en esta entrega, y qué volvió.
            EntregaService::fijarLineas($item, $lineas, $devueltoPorItem);

            if ($traePago && (float) $data['monto'] > 0) {
                Pago::create([
                    'orden_id'    => $item->orden_id,
                    'vendedor_id' => $usuario->id,
                    // En la última entrega es el saldo; en una parcial, un abono.
                    'tipo'        => $esLaUltima ? 'saldo_final' : 'abono',
                    'monto'       => $data['monto'],
                    'metodo'      => $data['metodo'],
                    'referencia'  => $data['referencia'] ?? null,
                    'comprobante_url'   => $fotoPago,
                    'comprobante_fotos' => $fotosPago ?: null,
                ]);
            }

            // Lo que se regresó en el camión. Se guarda acá y no al marcar
            // entregado porque es acá donde el conductor sube la foto y
            // escribe el motivo, delante del cliente.
            foreach ($devoluciones as $d) {
                Devolucion::create([
                    'orden_id'         => $item->orden_id,
                    'orden_item_id'    => $d['orden_item_id'],
                    'despacho_item_id' => $item->id,
                    'cantidad'         => $d['cantidad'],
                    'motivo'           => $d['motivo'],
                    'preferencia_cliente' => $d['preferencia'] ?? null,
                    'foto_url'         => $fotoDevolucion,
                    'fecha'            => now()->toDateString(),
                    'reportado_por_id' => $usuario->id,
                    'estado'           => 'pendiente',
                ]);
            }
        });

        // Fuera de la transacción: avisar no puede tumbar una entrega ya hecha.
        foreach (Devolucion::where('despacho_item_id', $item->id)->with('orden.cliente', 'item.producto')->get() as $dev) {
            DevolucionController::avisarDevolucion($dev);
        }

        // El otro camino por el que se cobra una orden. Si el conductor cobró
        // con datáfono, la comisión también tiene que bajar 5,5%.
        ComisionController::sincronizarValorOrden($item->orden->fresh());

        $item->load('orden.cliente:id,nombre');
        $item->orden->total_pagado    = $item->orden->totalPagado();
        $item->orden->saldo_pendiente = $item->orden->saldoPendiente();

        // Una novedad en la entrega no puede quedarse solo en el acta: hay que
        // enterarse el mismo día, no cuando el cliente reclame.
        if ($conforme === false) {
            $cliente = $item->orden->cliente?->nombre ?? 'cliente';

            foreach (Usuario::where('activo', true)
                ->where('id', '!=', $usuario->id)
                ->where(fn($q) => $q->where('rol', 'supervisor')->orWhere('facturacion', true))
                ->get() as $d
            ) {
                NotificacionService::crear(
                    tipo:      'entrega_con_novedad',
                    titulo:    'Entrega recibida con novedad',
                    mensaje:   "Orden {$item->orden->referencia} de {$cliente}: "
                             . ($data['observaciones_entrega'] ?? 'el cliente reportó un problema')
                             . " (entregó {$usuario->nombre})",
                    datos:     ['orden_id' => $item->orden_id, 'despacho_item_id' => $item->id],
                    usuarioId: $d->id,
                );
            }
        }

        return response()->json($item);
    }

    /**
     * Qué productos van en esta entrega, ya comprobados.
     *
     * Llega como JSON dentro del multipart: [{orden_item_id, cantidad}]. Sin
     * lista, va todo lo que falte y se pueda entregar hoy.
     *
     * @return array<int,int>|\Illuminate\Http\JsonResponse  [orden_item_id => cantidad]
     */
    private function leerLineas(Request $request, DespachoItem $item)
    {
        $orden = $item->orden()->with('items.produccion', 'items.producto:id,nombre')->first();
        $crudo = $request->input('lineas');

        if (! $crudo) {
            // Lo que se cargó al armar la ruta, si todavía se puede entregar;
            // si no se cargó nada en particular, todo lo que se pueda hoy.
            $entregables = EntregaService::entregablesDe($orden);
            $cargado     = $item->lineas()->whereIn('resultado', EntregaLinea::SE_QUEDO)->get()
                ->groupBy('orden_item_id')->map(fn ($g) => (int) $g->sum('cantidad'));
            $todo = [];
            foreach ($cargado as $itemId => $cant) {
                if (isset($entregables[$itemId])) $todo[$itemId] = min($cant, $entregables[$itemId]);
            }
            if (! $todo) $todo = $entregables;
            if (! $todo) {
                return response()->json([
                    'message' => 'No hay nada que entregar: lo de catálogo ya se entregó y lo demás sigue en el taller.',
                ], 422);
            }
            return $todo;
        }

        $lista = is_array($crudo) ? $crudo : json_decode($crudo, true);
        if (! is_array($lista)) {
            return response()->json(['message' => 'No se entendió qué se entrega.'], 422);
        }

        $limpias = EntregaService::validarLineas($orden, $lista);
        if (is_string($limpias)) {
            return response()->json(['message' => $limpias, 'errors' => ['lineas' => [$limpias]]], 422);
        }

        return $limpias;
    }

    /**
     * Lo que el conductor marcó como devuelto, ya comprobado.
     *
     * Llega como JSON dentro del multipart porque el formulario sube fotos.
     * Devuelve la lista limpia, o una respuesta de error si algo no cuadra —
     * un ítem que no iba en esta entrega, o más unidades de las que iban.
     *
     * @param  array<int,int> $lineas  lo que va en esta entrega
     * @return array<int, array{orden_item_id:int, cantidad:int, motivo:string}>|\Illuminate\Http\JsonResponse
     */
    private function leerDevoluciones(Request $request, DespachoItem $item, array $lineas)
    {
        $crudo = $request->input('devoluciones');

        if (! $crudo) {
            return [];
        }

        $lista = is_array($crudo) ? $crudo : json_decode($crudo, true);

        if (! is_array($lista)) {
            return response()->json(['message' => 'No se entendió lo que se devuelve.'], 422);
        }

        $itemsOrden = $item->orden->items->keyBy('id');
        $limpias    = [];

        foreach ($lista as $d) {
            $itemId   = (int) ($d['orden_item_id'] ?? 0);
            $cantidad = (int) ($d['cantidad'] ?? 0);
            $motivo   = trim((string) ($d['motivo'] ?? ''));
            // Qué prefiere el cliente (arreglar / otra igual / otro producto).
            // Lo anota quien entrega; decide el supervisor.
            $prefiere = in_array($d['preferencia'] ?? null, ['arreglar', 'cambiar_mismo', 'cambiar_otro'], true)
                ? $d['preferencia'] : null;

            if ($cantidad < 1) continue;

            $ordenItem = $itemsOrden->get($itemId);
            if (! $ordenItem || ! isset($lineas[$itemId])) {
                return response()->json([
                    'message' => 'Se está devolviendo algo que no iba en esta entrega.',
                ], 422);
            }
            if ($cantidad > $lineas[$itemId]) {
                $nombre = $ordenItem->nombre_custom ?: ($ordenItem->producto?->nombre ?? 'ese producto');
                return response()->json([
                    'message' => "No se pueden devolver {$cantidad} de {$nombre}: iban {$lineas[$itemId]}.",
                ], 422);
            }
            // Sin motivo la devolución no sirve para decidir nada después.
            if (mb_strlen($motivo) < 3) {
                return response()->json([
                    'message' => 'Falta decir por qué se devuelve.',
                    'errors'  => ['devoluciones' => ['Escribe el motivo de la devolución.']],
                ], 422);
            }

            $limpias[] = ['orden_item_id' => $itemId, 'cantidad' => $cantidad, 'motivo' => $motivo, 'preferencia' => $prefiere];
        }

        return $limpias;
    }

    /** ¿Se regresó absolutamente todo lo que iba en esta entrega? */
    private function devuelveTodoLoQueVa(array $lineas, array $devueltoPorItem): bool
    {
        foreach ($lineas as $itemId => $cant) {
            if ((int) $cant > (int) ($devueltoPorItem[$itemId] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Con esta entrega el cliente queda con todo lo que compró?
     *
     * Lo que ya tenía, más lo que va ahora y se queda, contra lo que lleva la
     * orden. Es lo que decide si se le cobra el saldo o no.
     */
    private function completaLaOrden(Orden $orden, array $lineas, array $devueltoPorItem): bool
    {
        foreach ($orden->items as $item) {
            $seQueda = (int) ($lineas[$item->id] ?? 0) - (int) ($devueltoPorItem[$item->id] ?? 0);
            if ($item->pendienteEntregar() > max(0, $seQueda)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GET /api/ordenes/{ordenId}/orden-entrega
     *
     * La hoja que se lleva quien entrega: la que antes se hacía a mano.
     * Datos del cliente, lo que va, el total, lo abonado y lo que hay que
     * cobrar contra entrega, y espacio para la firma de quien recibe.
     *
     * Lista lo que FALTA por entregar. Si se pide con `?entrega=` (id de una
     * entrega abierta), lista lo que se cargó en esa.
     */
    public function ordenEntrega(Request $request, int $ordenId)
    {
        $usuario = $request->user();

        $orden = Orden::with([
            'cliente:id,nombre,telefono,direccion,cedula', 'vendedor:id,nombre', 'tienda:id,nombre',
            'items.producto:id,nombre', 'items.produccion:id,orden_item_id,estado',
            'items.variante', 'items.comboConfig.tipo', 'items.comboConfig.opcion',
            'pagos',
        ])->findOrFail($ordenId);

        if (! $orden->laPuedeVer($usuario) && ! $usuario->acceso_despacho && ! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $entrega = null;
        if ($request->query('entrega')) {
            $entrega = DespachoItem::with('despacho.conductor:id,nombre', 'despacho.entregadoPor:id,nombre', 'lineas')
                ->where('orden_id', $orden->id)->find($request->query('entrega'));
        }

        [$lineas, $yaEntregado] = $this->lineasParaHoja($orden, $entrega);
        $pendientes = $this->pendientesTras($orden, $lineas);
        $parcial    = $lineas->isNotEmpty() && $pendientes->isNotEmpty();

        $abonos = $orden->totalPagado();
        $pdf = Pdf::loadView('pdf.orden_entrega', [
            'orden'       => $orden,
            'lineas'      => $lineas,
            'yaEntregado' => $yaEntregado,
            // Lo que NO va en esta hoja y sigue debiéndosele al cliente: en
            // una parcial es lo primero que pregunta quien recibe.
            'pendientes'  => $pendientes,
            'parcial'     => $parcial,
            'valorEntrega' => (float) $lineas->sum('valor'),
            'totalPedido' => (float) $orden->valor_total,
            'abonos'      => $abonos,
            'saldo'       => max(0, (float) $orden->valor_total - $abonos),
            'entregador'  => $entrega?->despacho?->quienEntrega(),
            'logoBase64'  => $this->avifToPngBase64(public_path('img/logo.avif')),
        ]);
        $pdf->setPaper('letter');

        return $pdf->download('orden-entrega-' . strtolower(str_replace('#', '', $orden->referencia)) . '.pdf');
    }

    /**
     * Qué renglones van en una hoja de entrega, y qué ya se entregó antes.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function lineasParaHoja(Orden $orden, ?DespachoItem $entrega): array
    {
        $nombre = fn ($i) => $i->producto->nombre ?? $i->nombre_custom ?? 'Producto';

        // Lo cargado en la entrega, si la hay y trae líneas.
        $cargado = $entrega
            ? $entrega->lineas->whereIn('resultado', EntregaLinea::SE_QUEDO)
                ->groupBy('orden_item_id')->map(fn ($g) => (int) $g->sum('cantidad'))
            : collect();

        $lineas = $orden->items->filter->estaVivo()
            ->map(function ($i) use ($cargado, $nombre) {
                $cant = $cargado->isNotEmpty() ? (int) ($cargado[$i->id] ?? 0) : $i->pendienteEntregar();
                return [
                    'id' => $i->id, 'nombre' => $nombre($i), 'variante' => $i->variante_texto,
                    'cantidad' => $cant, 'valor' => $cant * (float) $i->precio_unitario,
                ];
            })
            ->filter(fn ($l) => $l['cantidad'] > 0)->values();

        // Orden sin nada pendiente (ya entregada, o lista sin líneas): va todo.
        if ($lineas->isEmpty()) {
            $lineas = $orden->items->filter->estaVivo()->map(fn ($i) => [
                'id' => $i->id, 'nombre' => $nombre($i), 'variante' => $i->variante_texto,
                'cantidad' => (int) $i->cantidad, 'valor' => (int) $i->cantidad * (float) $i->precio_unitario,
            ])->values();
        }

        $yaEntregado = $orden->items->filter(fn ($i) => (int) $i->cantidad_entregada > 0)
            ->map(fn ($i) => ['nombre' => $nombre($i), 'cantidad' => (int) $i->cantidad_entregada])->values();

        return [$lineas, $yaEntregado];
    }

    /**
     * Lo que le seguirá faltando al cliente después de esta entrega.
     *
     * Es la otra mitad de la hoja: si hoy van 2 de 4, el papel tiene que
     * decir cuáles 2 quedan para la próxima, y por qué (en el taller, o
     * simplemente no se cargaron).
     *
     * @return \Illuminate\Support\Collection<int, array{nombre:string, cantidad:int, motivo:string}>
     */
    private function pendientesTras(Orden $orden, \Illuminate\Support\Collection $lineas): \Illuminate\Support\Collection
    {
        $orden->loadMissing('items.produccion');
        $nombre = fn ($i) => $i->producto->nombre ?? $i->nombre_custom ?? 'Producto';

        return $orden->items->filter->estaVivo()
            ->map(function ($i) use ($lineas, $nombre) {
                $vaAhora = (int) ($lineas->firstWhere('id', $i->id)['cantidad'] ?? 0);
                $queda   = $i->pendienteEntregar() - $vaAhora;
                if ($queda <= 0) return null;

                return [
                    'nombre'   => $nombre($i),
                    'variante' => $i->variante_texto,
                    'cantidad' => $queda,
                    'motivo'   => $i->estaListoParaEntregar() ? 'no va en esta entrega' : 'en el taller',
                ];
            })
            ->filter()->values();
    }

    /**
     * GET /api/despacho/{id}/hoja-ruta
     *
     * La hoja del conductor: las paradas en orden, a quién y dónde, qué se
     * baja en cada una y cuánto cobrar. Al final, cuánto debe volver.
     */
    public function hojaRuta(Request $request, int $id)
    {
        $usuario  = $request->user();
        $despacho = Despacho::with([
            'conductor:id,nombre', 'supervisor:id,nombre', 'camion:id,nombre,placa',
            'items' => fn ($q) => $q->orderBy('posicion'),
            'items.lineas',
            'items.orden.cliente:id,nombre,telefono,direccion,cedula',
            'items.orden.vendedor:id,nombre',
            'items.orden.items.producto:id,nombre',
            'items.orden.items.variante', 'items.orden.items.comboConfig.tipo', 'items.orden.items.comboConfig.opcion',
            'items.orden.pagos',
        ])->findOrFail($id);

        $esSuya = (int) $despacho->conductor_id === (int) $usuario->id;
        if (! $esSuya && ! $usuario->acceso_despacho && $usuario->rol !== 'supervisor') {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $paradas = $despacho->items->map(function (DespachoItem $item) {
            $orden = $item->orden;
            [$lineas] = $this->lineasParaHoja($orden, $item);
            $pendientes = $this->pendientesTras($orden, $lineas);
            $parcial    = $pendientes->isNotEmpty();
            $abonado = $orden->totalPagado();

            return [
                'posicion'   => $item->posicion,
                'referencia' => $orden->referencia,
                'cliente'    => $orden->cliente->nombre ?? '—',
                'cedula'     => $orden->cliente->cedula ?? null,
                'telefono'   => $orden->cliente->telefono ?? null,
                'direccion'  => $orden->direccion_envio ?: ($orden->cliente->direccion ?? null),
                'ciudad'     => $orden->ciudad_envio,
                'asesor'     => $orden->vendedor->nombre ?? null,
                'lineas'     => $lineas->all(),
                'parcial'    => $parcial,
                'pendientes' => $pendientes->all(),
                'total'      => (float) $orden->valor_total,
                'abonado'    => $abonado,
                'saldo'      => max(0, (float) $orden->valor_total - $abonado),
                'notas'      => $orden->notas,
            ];
        })->values();

        $pdf = Pdf::loadView('pdf.hoja_ruta', [
            'despacho'    => $despacho,
            'paradas'     => $paradas,
            // Solo lo que se cobra hoy: en una parcial el saldo espera.
            'totalCobrar' => $paradas->reject(fn ($p) => $p['parcial'])->sum('saldo'),
            'logoBase64'  => $this->avifToPngBase64(public_path('img/logo.avif')),
        ]);
        $pdf->setPaper('letter');

        $nombre = $despacho->nombre_ruta ? \Illuminate\Support\Str::slug($despacho->nombre_ruta) : 'ruta-' . $despacho->id;

        return $pdf->download("hoja-ruta-{$nombre}.pdf");
    }

    /**
     * GET /api/ordenes/{ordenId}/acta-entrega[?entrega=]
     *
     * PDF del acta de satisfacción firmada por quien recibió. Es el acta de
     * UNA entrega: con `entrega` se pide la de esa; sin él, la última
     * firmada. Lista solo lo que se entregó en ese viaje —una orden puede
     * tener varias actas si se entregó por partes— y deja escrito qué quedó
     * pendiente y qué se devolvió, para que el papel cuente lo que pasó.
     */
    public function actaEntrega(Request $request, int $ordenId)
    {
        $usuario = $request->user();

        $query = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'despacho.entregadoPor:id,nombre',
            'orden.cliente:id,nombre,telefono,cedula',
            'orden.tienda:id,nombre',
            'orden.items.producto:id,nombre',
            'orden.items.produccion:id,orden_item_id,estado',
            'orden.items.variante', 'orden.items.comboConfig.tipo', 'orden.items.comboConfig.opcion',
            'lineas',
        ])
            ->where('orden_id', $ordenId)
            ->whereNotNull('firma_recibido_url');

        $item = $request->query('entrega')
            ? $query->find($request->query('entrega'))
            : $query->latest('entregado_at')->first();

        if (! $item) {
            return response()->json(['message' => 'Esta orden no tiene acta de entrega firmada.'], 404);
        }

        $orden = $item->orden;

        // El acta es un papel de la orden: quien puede verla, la imprime.
        if (! $orden->laPuedeVer($usuario) && ! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        [$entregados, $devueltos, $pendientes] = $this->lineasDelActa($orden, $item);

        $firmaBase64 = $this->urlToBase64($item->firma_recibido_url);
        $logoBase64  = $this->avifToPngBase64(public_path('img/logo.avif'));

        $pdf = Pdf::loadView('pdf.acta_entrega', compact(
            'orden', 'item', 'entregados', 'devueltos', 'pendientes', 'firmaBase64', 'logoBase64'
        ));
        $pdf->setPaper('letter');

        $sufijo = $request->query('entrega') ? "-e{$item->id}" : '';

        return $pdf->download('acta-' . strtolower(str_replace('#', '', $orden->referencia)) . $sufijo . '.pdf');
    }

    /**
     * Qué dice el acta de una entrega: lo que se quedó en la casa, lo que
     * volvió en el camión y lo que sigue pendiente para otra entrega.
     *
     * Una entrega sin líneas escritas (de antes de las parciales) llevó la
     * orden completa, así que se lista todo.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     */
    private function lineasDelActa(Orden $orden, DespachoItem $entrega): array
    {
        $nombre = fn ($i) => $i->producto->nombre ?? $i->nombre_custom ?? 'Producto personalizado';
        $porItem = fn ($grupo) => $grupo->groupBy('orden_item_id')->map(fn ($g) => (int) $g->sum('cantidad'));

        $seQuedo = $porItem($entrega->lineas->whereIn('resultado', EntregaLinea::SE_QUEDO));
        $volvio  = $porItem($entrega->lineas->where('resultado', EntregaLinea::DEVUELTO));

        $fila = fn ($i, $cant) => [
            'id' => $i->id, 'nombre' => $nombre($i), 'variante' => $i->variante_texto, 'cantidad' => $cant,
        ];

        if ($entrega->lineas->isEmpty()) {
            $entregados = $orden->items->filter->estaVivo()->map(fn ($i) => $fila($i, (int) $i->cantidad))->values();
            return [$entregados, collect(), collect()];
        }

        $entregados = $orden->items->filter(fn ($i) => ($seQuedo[$i->id] ?? 0) > 0)
            ->map(fn ($i) => $fila($i, $seQuedo[$i->id]))->values();
        $devueltos  = $orden->items->filter(fn ($i) => ($volvio[$i->id] ?? 0) > 0)
            ->map(fn ($i) => $fila($i, $volvio[$i->id]))->values();

        // Lo pendiente se mira contra lo que falta HOY: si esta acta es de una
        // entrega vieja y después se entregó el resto, ya no hay pendientes.
        // Si la entrega está cerrada, lo que llevó ya está descontado de
        // `pendienteEntregar`, así que se pasa una lista vacía como "va ahora".
        $pendientes = $this->pendientesTras($orden, collect());

        return [$entregados, $devueltos, $pendientes];
    }

    /**
     * PATCH /api/despacho/mis-entregas/{despachoItemId}/entregar
     * Marca como entregado — requiere fotos + pago previos.
     *
     * Lo que pasa de verdad (stock, producción, estado de la orden) vive en
     * EntregaService: es la misma puerta por la que sale el reloj que el
     * cliente se lleva del mostrador y el mueble que lleva el conductor.
     */
    public function entregar(Request $request, int $despachoItemId)
    {
        $usuario = $request->user();

        $item = DespachoItem::with([
            'despacho.conductor:id,nombre',
            'despacho.entregadoPor:id,nombre',
            'orden.cliente:id,nombre',
        ])->findOrFail($despachoItemId);

        if (! $this->puedeOperarEntrega($item, $usuario)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if (in_array($item->estado, ['entregado', 'devuelto'], true)) {
            return response()->json(['message' => 'Ya fue entregada.'], 422);
        }
        if (! $item->puedeEntregar()) {
            return response()->json([
                'message' => 'Debes registrar el pago y subir ambas fotos antes de marcar como entregado.',
            ], 422);
        }

        // Conductor de ruta, o quien la abrió si fue entrega directa.
        $esDirecta      = $item->despacho->esDirecta();
        $quienEntregaId = $item->despacho->conductor_id ?? $item->despacho->entregado_por_id ?? $usuario->id;
        $quienEntrega   = $item->despacho->quienEntrega() ?? $usuario->nombre;
        $etiquetaCanal  = $esDirecta ? 'entrega directa' : 'conductor';

        $resultado = EntregaService::entregar($item, (int) $quienEntregaId, $etiquetaCanal);

        $item->refresh()->load('orden.cliente:id,nombre', 'orden.items', 'lineas');
        $orden   = $item->orden;
        $entrega = $orden->resumenEntrega();
        $parcial = $entrega['parcial'];
        $item->orden->entrega = $entrega;

        $queSeEntrego = $parcial
            ? " ({$entrega['entregados']} de {$entrega['total']} productos; falta el resto)"
            : '';

        event(new OrdenEntregada(
            $item->orden_id,
            $orden->cliente->nombre,
            $quienEntrega,
            $orden->referencia,
        ));

        NotificacionService::crear(
            'entregado',
            ($parcial ? 'Entrega parcial' : 'Orden entregada') . ($esDirecta ? '' : ' por conductor'),
            "Orden {$orden->referencia} de {$orden->cliente->nombre}: "
                . ($parcial ? 'se entregó parte' : 'fue entregada') . " por {$quienEntrega}{$queSeEntrego}",
            ['orden_id' => $item->orden_id],
        );

        // Facturar cuando el cliente ya tiene todo: una entrega parcial no
        // cierra la venta.
        if (! $parcial && $resultado['estado_orden'] === 'entregado') {
            $facturacionVendedores = Usuario::where('rol', 'vendedor')
                ->where('facturacion', true)
                ->where('tienda_default_id', $orden->tienda_id)
                ->get();

            foreach ($facturacionVendedores as $vendedor) {
                NotificacionService::crear(
                    'facturar',
                    'Orden pendiente de facturación',
                    "Orden {$orden->referencia} de {$orden->cliente->nombre} fue entregada — pendiente de facturación",
                    ['orden_id' => $item->orden_id],
                    $vendedor->id,
                );
            }
        }

        return response()->json($item);
    }

    private function subirCloudinary($file): string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $timestamp = time();
        $folder    = 'decasa/entregas';

        $signature = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");

        $response = Http::attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => $folder,
        ]);

        if (! $response->ok()) {
            $detalle = $response->json('error.message') ?? $response->body();
            abort(502, "Error Cloudinary: {$detalle}");
        }

        return $response->json('secure_url');
    }
}
