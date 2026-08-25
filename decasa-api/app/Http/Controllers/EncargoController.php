<?php

namespace App\Http\Controllers;

use App\Models\Encargo;
use App\Models\EncargoRevision;
use App\Models\EncargoRevisionItem;
use App\Models\NominaAjuste;
use App\Models\NominaPago;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\NotificacionService;
use App\Services\RevisionEncargos;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Encargos: de qué responde cada trabajador.
 *
 * Dos cosas viven acá. La primera es la entrega: qué se le dio, cuántas,
 * cuándo y cuánto vale reponerla. La segunda es la revista —pasar contando
 * lo que tiene cada cierto tiempo— y lo que sale de ella: lo dañado queda
 * marcado, lo perdido se le descuenta de la cantidad que tiene a cargo y, si
 * se decide cobrarlo, se le manda a Nómina como un descuento.
 *
 * Una revista se guarda entera de una sola vez y no se edita. La fecha en que
 * se contó importa tanto como lo que se contó: corregir una vieja borraría
 * cuándo se notó la pérdida, que suele ser el único dato útil para saber qué
 * pasó.
 */
class EncargoController extends Controller
{
    // ── Serialización ────────────────────────────────────────────────────────

    private function encargoJson(Encargo $e): array
    {
        return [
            'id'              => $e->id,
            'usuario_id'      => $e->usuario_id,
            'nombre'          => $e->nombre,
            'cantidad'        => (int) $e->cantidad,
            'cantidad_danada' => (int) $e->cantidad_danada,
            'serial'          => $e->serial,
            'valor_unitario'  => $e->valor_unitario !== null ? (float) $e->valor_unitario : null,
            'valor_total'     => $e->valorTotal(),
            'fecha_entrega'   => $e->fecha_entrega->toDateString(),
            'foto_url'        => $e->foto_url,
            'notas'           => $e->notas,
            'estado'          => $e->estado,
            'cerrado_en'      => $e->cerrado_en?->toDateString(),
            'entregado_por'   => $e->entregadoPor?->nombre,
        ];
    }

    private function revisionJson(EncargoRevision $r, bool $conItems = false): array
    {
        $base = [
            'id'              => $r->id,
            'usuario_id'      => $r->usuario_id,
            'fecha'           => $r->fecha->toDateString(),
            'revisado_por'    => $r->revisadoPor?->nombre,
            'notas'           => $r->notas,
            'descuento_total' => (float) $r->descuento_total,
            'descontado'      => $r->nomina_ajuste_id !== null,
            'registrado_en'   => $r->created_at->toIso8601String(),
        ];

        if (! $conItems) {
            return $base;
        }

        return $base + [
            'items' => $r->items->map(fn (EncargoRevisionItem $i) => [
                'id'               => $i->id,
                'encargo_id'       => $i->encargo_id,
                // El nombre viene del encargo: sigue existiendo aunque ya se
                // haya perdido o devuelto, así que no hay que copiarlo.
                'nombre'           => $i->encargo?->nombre ?? 'Artículo eliminado',
                'serial'           => $i->encargo?->serial,
                'cantidad_ok'      => (int) $i->cantidad_ok,
                'cantidad_danada'  => (int) $i->cantidad_danada,
                'cantidad_perdida' => (int) $i->cantidad_perdida,
                'descuento'        => (float) $i->descuento,
                'notas'            => $i->notas,
            ])->all(),
        ];
    }

    /** El resumen de una persona: qué tiene, cuánto vale y cómo va de revista. */
    private function trabajadorJson(Usuario $u, ?Carbon $hoy = null): array
    {
        $aCargo = $u->encargos->where('estado', 'a_cargo');

        return [
            'id'              => $u->id,
            'nombre'          => $u->nombre,
            'cargo'           => $u->rolAsignado?->nombre ?? $u->rol,
            'activo'          => (bool) $u->activo,
            'no_usa_programa' => (bool) $u->no_usa_programa,
            'articulos'       => $aCargo->count(),
            'piezas'          => (int) $aCargo->sum('cantidad'),
            'danados'         => (int) $aCargo->sum('cantidad_danada'),
            'valor_total'     => round($aCargo->sum(fn (Encargo $e) => $e->valorTotal()), 2),
            'revision'        => RevisionEncargos::estadoDe($u, $hoy),
        ];
    }

    // ── Quién administra y quién solo mira lo suyo ───────────────────────────

    /**
     * Un trabajador puede abrir SU ficha sin tener el permiso del módulo: ver
     * de qué responde uno mismo no es administrar nada, y si no pudiera
     * mirarlo el único que sabe qué tiene a cargo sería el que le hace la
     * revista.
     */
    private function puedeVer(Request $request, int $usuarioId): bool
    {
        return $request->user()->acceso_encargos || $request->user()->id === $usuarioId;
    }

    // ── Lectura ──────────────────────────────────────────────────────────────

    /**
     * GET /api/encargos/trabajadores?incluir_inactivos=1
     *
     * La gente a la que se le entregó algo. Solo la que tiene el módulo
     * prendido: los demás no responden por nada y llenarían la lista.
     */
    public function trabajadores(Request $request)
    {
        $hoy = Carbon::today();

        $q = Usuario::where('lleva_encargos', true)
            ->with(['rolAsignado:id,nombre', 'encargos']);

        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        $lista = $q->orderBy('nombre')->get()
            ->map(fn (Usuario $u) => $this->trabajadorJson($u, $hoy))
            ->values();

        // A quién se le puede entregar algo. Va todo el mundo activo, no solo
        // los que ya llevan encargos: al primer taladro que se le entrega a
        // alguien, esa persona todavía no está en la lista de arriba — y si no
        // se pudiera elegir desde acá habría que ir antes a su ficha a marcar
        // una casilla, que es el paso que nadie recuerda.
        $asignables = Usuario::where('activo', true)
            ->with('rolAsignado:id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'rol', 'rol_id'])
            ->map(fn (Usuario $u) => [
                'id'     => $u->id,
                'nombre' => $u->nombre,
                'cargo'  => $u->rolAsignado?->nombre ?? $u->rol,
            ]);

        return response()->json([
            'trabajadores'  => $lista,
            'asignables'    => $asignables,
            'dias_generales' => RevisionEncargos::diasGenerales(),
            // Lo que la empresa tiene repartido por ahí, sumado. Es el número
            // que justifica el módulo entero.
            'valor_total'   => round($lista->sum('valor_total'), 2),
            'vencidas'      => $lista->where('revision.estado', 'vencida')->count(),
        ]);
    }

    /**
     * GET /api/encargos/trabajadores/{id}
     *
     * Lo que tiene una persona, más las revistas que se le han hecho. Sirve
     * igual para "mi ficha" que para la de otro: quién puede verla ya lo
     * decide `puedeVer`.
     */
    public function trabajador(Request $request, int $id)
    {
        if (! $this->puedeVer($request, $id)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $usuario = Usuario::with(['rolAsignado:id,nombre', 'encargos.entregadoPor:id,nombre'])->findOrFail($id);

        $revisiones = EncargoRevision::where('usuario_id', $id)
            ->with('revisadoPor:id,nombre')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'trabajador' => $this->trabajadorJson($usuario) + [
                'lleva_encargos'        => (bool) $usuario->lleva_encargos,
                'encargo_revision_dias' => $usuario->encargo_revision_dias,
            ],
            // Lo que tiene primero y lo cerrado después: al abrir la ficha lo
            // que importa es qué debería poder mostrar hoy.
            'encargos'   => $usuario->encargos
                ->sortBy([['estado', 'asc'], ['nombre', 'asc']])
                ->map(fn (Encargo $e) => $this->encargoJson($e))->values(),
            'revisiones' => $revisiones->map(fn (EncargoRevision $r) => $this->revisionJson($r)),
            'puede_administrar' => (bool) $request->user()->acceso_encargos,
        ]);
    }

    /** GET /api/encargos/mios — atajo a la ficha propia, sin saberse el id. */
    public function mios(Request $request)
    {
        return $this->trabajador($request, $request->user()->id);
    }

    /** GET /api/encargos/revisiones/{id} — una revista con lo que se contó. */
    public function revision(Request $request, int $id)
    {
        $revision = EncargoRevision::with(['revisadoPor:id,nombre', 'items.encargo'])->findOrFail($id);

        if (! $this->puedeVer($request, $revision->usuario_id)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json($this->revisionJson($revision, true));
    }

    // ── Entregar y cerrar ────────────────────────────────────────────────────

    /** POST /api/encargos — entregarle algo a alguien. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'     => 'required|exists:usuarios,id',
            'nombre'         => 'required|string|max:150',
            'cantidad'       => 'required|integer|min:1|max:9999',
            'serial'         => 'nullable|string|max:80',
            'valor_unitario' => 'nullable|numeric|min:0',
            'fecha_entrega'  => 'required|date',
            'foto_url'       => 'nullable|string|max:500',
            'notas'          => 'nullable|string|max:1000',
        ], [
            'usuario_id.required'    => 'Elige a quién se le entrega.',
            'nombre.required'        => 'Escribe qué se le entrega.',
            'cantidad.required'      => 'Falta cuántas se le entregan.',
            'fecha_entrega.required' => 'Falta la fecha de entrega.',
        ]);

        $trabajador = Usuario::findOrFail($data['usuario_id']);

        // Se prende solo al entregarle lo primero. Obligar a ir a la ficha del
        // trabajador a marcar una casilla antes de poder entregarle un taladro
        // es un paso que nadie recuerda y que solo sirve para que el taladro
        // termine sin registrar.
        if (! $trabajador->lleva_encargos) {
            $trabajador->update(['lleva_encargos' => true]);
        }

        $encargo = Encargo::create($data + [
            'entregado_por_id' => $request->user()->id,
            'estado'           => 'a_cargo',
        ]);

        return response()->json($this->encargoJson($encargo->load('entregadoPor:id,nombre')), 201);
    }

    /** PATCH /api/encargos/{id} — corregir lo que se anotó mal. */
    public function update(Request $request, int $id)
    {
        $encargo = Encargo::findOrFail($id);

        $data = $request->validate([
            'nombre'         => 'sometimes|string|max:150',
            // La cantidad se corrige acá solo cuando se escribió mal. Lo que
            // se pierde no se descuenta por este lado: sale de una revista,
            // que es lo que deja constancia de qué pasó y cuándo.
            'cantidad'       => 'sometimes|integer|min:1|max:9999',
            'serial'         => 'sometimes|nullable|string|max:80',
            'valor_unitario' => 'sometimes|nullable|numeric|min:0',
            'fecha_entrega'  => 'sometimes|date',
            'foto_url'       => 'sometimes|nullable|string|max:500',
            'notas'          => 'sometimes|nullable|string|max:1000',
        ]);

        if (! $encargo->estaACargo() && array_key_exists('cantidad', $data)) {
            return response()->json([
                'message' => 'Esto ya no está a cargo de nadie: no se le puede cambiar la cantidad.',
            ], 422);
        }

        $encargo->update($data);

        return response()->json($this->encargoJson($encargo->fresh('entregadoPor')));
    }

    /**
     * POST /api/encargos/{id}/cerrar
     *
     * Deja de estar a su cargo: lo devolvió, se dio por perdido fuera de una
     * revista, o se acabó su vida útil. La fila se queda —es el historial de
     * quién tuvo qué— y solo cambia de estado.
     */
    public function cerrar(Request $request, int $id)
    {
        $encargo = Encargo::findOrFail($id);

        if (! $encargo->estaACargo()) {
            return response()->json(['message' => 'Esto ya estaba cerrado.'], 422);
        }

        $data = $request->validate([
            'estado' => 'required|in:devuelto,perdido,baja',
            'fecha'  => 'nullable|date',
            'notas'  => 'nullable|string|max:1000',
        ], [
            'estado.required' => 'Falta decir si se devolvió, se perdió o se dio de baja.',
        ]);

        $encargo->update([
            'estado'     => $data['estado'],
            'cerrado_en' => $data['fecha'] ?? Carbon::today()->toDateString(),
            'notas'      => $data['notas'] ?? $encargo->notas,
        ]);

        return response()->json($this->encargoJson($encargo->fresh('entregadoPor')));
    }

    /**
     * DELETE /api/encargos/{id}
     *
     * Solo para lo que se creó por error. Si ya salió en una revista es parte
     * de un conteo firmado: borrarlo dejaría esa revista sin cuadrar.
     */
    public function destroy(int $id)
    {
        $encargo = Encargo::findOrFail($id);

        if ($encargo->itemsRevision()->exists()) {
            return response()->json([
                'message' => 'Ya se contó en una revista. Ciérralo como devuelto, perdido o dado de baja.',
            ], 422);
        }

        $encargo->delete();

        return response()->json(['ok' => true]);
    }

    // ── La revista ───────────────────────────────────────────────────────────

    /**
     * POST /api/encargos/revisiones
     *
     * Pasar contando lo de un trabajador. Se manda entera: hay que contar
     * TODO lo que tiene a cargo, y de cada cosa cuántas están bien, cuántas
     * dañadas y cuántas faltan. Una revista a medias no sirve de nada — lo
     * que no se contó es exactamente lo que se pierde.
     */
    public function guardarRevision(Request $request)
    {
        $data = $request->validate([
            'usuario_id'               => 'required|exists:usuarios,id',
            'fecha'                    => 'required|date',
            'notas'                    => 'nullable|string|max:1000',
            // Cobrarle lo perdido o no es una decisión de quien revisa: a
            // veces la herramienta se rompió trabajando y no se le cobra.
            'descontar'                => 'boolean',
            'items'                    => 'required|array|min:1',
            'items.*.encargo_id'       => 'required|integer|exists:encargos,id',
            'items.*.cantidad_ok'      => 'required|integer|min:0',
            'items.*.cantidad_danada'  => 'required|integer|min:0',
            'items.*.cantidad_perdida' => 'required|integer|min:0',
            'items.*.descuento'        => 'nullable|numeric|min:0',
            'items.*.notas'            => 'nullable|string|max:300',
        ], [
            'usuario_id.required' => 'Elige a quién se le está revisando.',
            'fecha.required'      => 'Falta la fecha de la revisión.',
            'items.required'      => 'No hay nada que contar.',
        ]);

        $trabajador = Usuario::findOrFail($data['usuario_id']);
        $fecha      = Carbon::parse($data['fecha'])->startOfDay();

        $aCargo = Encargo::where('usuario_id', $trabajador->id)
            ->where('estado', 'a_cargo')
            ->get()
            ->keyBy('id');

        if ($aCargo->isEmpty()) {
            return response()->json([
                'message' => "{$trabajador->nombre} no tiene nada a cargo: no hay qué revisar.",
            ], 422);
        }

        // ── Que el conteo cuadre, antes de tocar nada ────────────────────────
        $porEncargo = [];
        foreach ($data['items'] as $item) {
            $encargo = $aCargo->get($item['encargo_id']);

            if (! $encargo) {
                return response()->json([
                    'message' => 'Se está contando algo que no está a cargo de esta persona. Vuelve a abrir la revisión.',
                ], 422);
            }

            $contadas = $item['cantidad_ok'] + $item['cantidad_danada'] + $item['cantidad_perdida'];
            if ($contadas !== (int) $encargo->cantidad) {
                return response()->json([
                    'message' => "En «{$encargo->nombre}» contaste {$contadas} y tiene {$encargo->cantidad}. " .
                                 'Entre buenas, dañadas y perdidas tienen que dar la cantidad que tiene a cargo.',
                ], 422);
            }

            $porEncargo[$encargo->id] = $item;
        }

        $faltantes = $aCargo->keys()->diff(array_keys($porEncargo));
        if ($faltantes->isNotEmpty()) {
            $nombres = $aCargo->only($faltantes->all())->pluck('nombre')->implode(', ');
            return response()->json([
                'message' => "Falta contar: {$nombres}. La revisión tiene que cubrir todo lo que tiene a cargo.",
            ], 422);
        }

        // ── Guardar ──────────────────────────────────────────────────────────
        $aviso = null;

        $revision = DB::transaction(function () use ($trabajador, $fecha, $data, $porEncargo, $aCargo, $request, &$aviso) {
            $revision = EncargoRevision::create([
                'usuario_id'      => $trabajador->id,
                'revisado_por_id' => $request->user()->id,
                'fecha'           => $fecha->toDateString(),
                'notas'           => $data['notas'] ?? null,
                'descuento_total' => 0,
            ]);

            $total = 0.0;

            foreach ($porEncargo as $encargoId => $item) {
                $encargo   = $aCargo->get($encargoId);
                $perdidas  = (int) $item['cantidad_perdida'];
                // Se sugiere lo que cuesta reponerlas, pero manda lo que venga:
                // quien revisa puede perdonarlo o cobrarlo a medias.
                $descuento = array_key_exists('descuento', $item) && $item['descuento'] !== null
                    ? (float) $item['descuento']
                    : round($perdidas * (float) ($encargo->valor_unitario ?? 0), 2);

                EncargoRevisionItem::create([
                    'revision_id'      => $revision->id,
                    'encargo_id'       => $encargo->id,
                    'cantidad_ok'      => $item['cantidad_ok'],
                    'cantidad_danada'  => $item['cantidad_danada'],
                    'cantidad_perdida' => $perdidas,
                    'descuento'        => $descuento,
                    'notas'            => $item['notas'] ?? null,
                ]);

                $total += $descuento;

                // Lo perdido deja de estar a su cargo: si no se le restara,
                // en la próxima revista se le volvería a contar —y a cobrar—
                // lo mismo. Lo dañado sí sigue siendo suyo, marcado.
                $quedan = (int) $encargo->cantidad - $perdidas;

                if ($quedan > 0) {
                    $encargo->update([
                        'cantidad'        => $quedan,
                        'cantidad_danada' => $item['cantidad_danada'],
                    ]);
                } else {
                    // Se perdió todo. La cantidad se deja como estaba: la fila
                    // pasa a ser el registro de qué se perdió, y contarla como
                    // cero borraría cuántas eran.
                    $encargo->update([
                        'estado'          => 'perdido',
                        'cantidad_danada' => 0,
                        'cerrado_en'      => $fecha->toDateString(),
                    ]);
                }
            }

            $revision->descuento_total = round($total, 2);

            if ($total > 0 && $request->boolean('descontar')) {
                if ($pago = $this->pagoQueCubre($trabajador->id, $fecha)) {
                    // La revista se guarda igual: perderla por no poder cobrar
                    // sería tirar el conteo entero. El descuento se anota a
                    // mano en un ciclo abierto.
                    $aviso = "La revisión quedó guardada, pero el descuento no se pudo aplicar: " .
                             "esa fecha ya se le pagó a {$trabajador->nombre} ({$pago->nombreCiclo()}). " .
                             'Anótalo como ajuste en el ciclo actual.';
                } else {
                    $ajuste = NominaAjuste::create([
                        'usuario_id' => $trabajador->id,
                        'fecha'      => CicloNomina::fecha($fecha->toDateString()),
                        'nombre'     => 'Encargos perdidos — revisión del ' . $fecha->locale('es')->isoFormat('D MMM'),
                        // Negativo: en Nómina un ajuste positivo suma y uno
                        // negativo resta.
                        'monto'      => -1 * round($total, 2),
                    ]);
                    $revision->nomina_ajuste_id = $ajuste->id;
                }
            }

            $revision->save();

            return $revision;
        });

        $this->avisarAlTrabajador($trabajador, $revision);

        return response()->json(
            $this->revisionJson($revision->load(['revisadoPor:id,nombre', 'items.encargo']), true)
            + ['aviso' => $aviso],
            201
        );
    }

    /**
     * Al trabajador se le avisa si se le descontó algo.
     *
     * Enterarse de un descuento el día del pago es la forma más rápida de que
     * una revista termine en discusión. Si no usa el programa no hay a dónde
     * mandárselo: eso se lo dice de frente quien le hizo la revista.
     */
    private function avisarAlTrabajador(Usuario $trabajador, EncargoRevision $revision): void
    {
        if ($trabajador->no_usa_programa || ! $revision->nomina_ajuste_id) {
            return;
        }

        $monto = '$' . number_format((float) $revision->descuento_total, 0, ',', '.');

        NotificacionService::crear(
            'encargo_descuento',
            'Descuento por herramienta perdida',
            "En la revisión de tus encargos del " . $revision->fecha->locale('es')->isoFormat('D [de] MMMM') .
            " se te descontaron {$monto}.",
            ['usuario_id' => $trabajador->id, 'revision_id' => $revision->id],
            $trabajador->id,
        );
    }

    private function pagoQueCubre(int $usuarioId, Carbon $fecha): ?NominaPago
    {
        return NominaPago::where('usuario_id', $usuarioId)
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->first();
    }

    // ── Cada cuánto se revisa ────────────────────────────────────────────────

    /**
     * PUT /api/encargos/config
     *
     * El intervalo general y, si hace falta, el propio de una persona: al del
     * portátil se le puede mirar cada seis meses y al del taller cada mes.
     */
    public function guardarConfig(Request $request)
    {
        $data = $request->validate([
            'dias_generales' => 'nullable|integer|min:1|max:730',
            'usuario_id'     => 'nullable|exists:usuarios,id',
            // null en el del trabajador = vuelve a usar el general.
            'dias_usuario'   => 'nullable|integer|min:1|max:730',
        ], [
            'dias_generales.max' => 'Dos años es lo máximo: más allá de eso no es una revisión.',
        ]);

        if (isset($data['dias_generales'])) {
            RevisionEncargos::guardarDiasGenerales($data['dias_generales']);
        }

        if (! empty($data['usuario_id'])) {
            Usuario::whereKey($data['usuario_id'])
                ->update(['encargo_revision_dias' => $data['dias_usuario'] ?? null]);
        }

        return response()->json(['dias_generales' => RevisionEncargos::diasGenerales()]);
    }
}
