<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\NominaBonificacion;
use App\Models\NominaBonificacionMeta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los esquemas de bonificación por producción y su escalera de metas.
 *
 * Todo se configura desde acá: el tope se cambia o se apaga sin perder el
 * valor, las metas se agregan y se desactivan una por una, y se pueden
 * tener varios esquemas con nombre para asignarle a cada trabajador el que
 * le corresponda.
 */
class NominaBonificacionController extends Controller
{
    /**
     * Sobre qué ventana se mide el tope. 'ciclo' es el ciclo de pago de cada
     * trabajador; el resto son ventanas fijas iguales para todos.
     */
    private const PERIODOS = ['ciclo', 'diario', 'semanal', 'quincenal', '20_dias', 'mensual'];

    private function comoJson(NominaBonificacion $b): array
    {
        $metas = $b->relationLoaded('metas') ? $b->metas : $b->metas()->get();

        return [
            'id'            => $b->id,
            'nombre'        => $b->nombre,
            'periodo'       => $b->periodo,
            'periodo_label' => $b->labelPeriodo(),
            'tope'        => (float) $b->tope,
            'tope_activo' => (bool) $b->tope_activo,
            'activo'      => (bool) $b->activo,
            'metas'       => $metas->map(fn (NominaBonificacionMeta $m) => [
                'id'       => $m->id,
                'desde'    => (float) $m->desde,
                'hasta'    => $m->hasta === null ? null : (float) $m->hasta,
                'monto'    => (float) $m->monto,
                'activo'   => (bool) $m->activo,
                'etiqueta' => $m->etiqueta(),
            ])->values(),
            'num_trabajadores' => Usuario::where('nomina_bonificacion_id', $b->id)->where('activo', true)->count(),
        ];
    }

    /** GET /api/nomina/bonificaciones?incluir_inactivas=1 */
    public function index(Request $request)
    {
        $q = NominaBonificacion::with('metas')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivas')) {
            $q->where('activo', true);
        }

        return response()->json($q->get()->map(fn (NominaBonificacion $b) => $this->comoJson($b)));
    }

    /** POST /api/nomina/bonificaciones */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:80',
            'periodo'     => ['nullable', Rule::in(self::PERIODOS)],
            'tope'        => 'nullable|numeric|min:0',
            'tope_activo' => 'nullable|boolean',
        ], [
            'nombre.required' => 'Ponle un nombre a la bonificación.',
        ]);

        $bonificacion = NominaBonificacion::create([
            'nombre'      => $data['nombre'],
            'periodo'     => $data['periodo'] ?? 'ciclo',
            'tope'        => $data['tope'] ?? 0,
            'tope_activo' => $data['tope_activo'] ?? true,
            'activo'      => true,
        ]);

        return response()->json($this->comoJson($bonificacion->load('metas')), 201);
    }

    /** PATCH /api/nomina/bonificaciones/{id} */
    public function update(Request $request, int $id)
    {
        $bonificacion = NominaBonificacion::findOrFail($id);

        $data = $request->validate([
            'nombre'      => 'sometimes|required|string|max:80',
            'periodo'     => ['sometimes', 'required', Rule::in(self::PERIODOS)],
            'tope'        => 'sometimes|numeric|min:0',
            'tope_activo' => 'sometimes|boolean',
            'activo'      => 'sometimes|boolean',
        ]);

        $bonificacion->update($data);

        return response()->json($this->comoJson($bonificacion->fresh('metas')));
    }

    /**
     * DELETE /api/nomina/bonificaciones/{id}
     *
     * Solo se borra si nadie la tiene asignada. Si ya la usan, se desactiva:
     * borrarla dejaría a esos trabajadores sin el bono que se les prometió.
     */
    public function destroy(int $id)
    {
        $bonificacion = NominaBonificacion::findOrFail($id);
        $usos = Usuario::where('nomina_bonificacion_id', $id)->count();

        if ($usos > 0) {
            $bonificacion->update(['activo' => false]);

            return response()->json([
                'message' => "\"{$bonificacion->nombre}\" la tienen asignada {$usos} trabajador(es), así que no se borra: " .
                             'queda desactivada y deja de pagar bono.',
                'desactivado' => true,
            ]);
        }

        $bonificacion->delete();

        return response()->json(['message' => 'Bonificación eliminada.', 'desactivado' => false]);
    }

    /**
     * PUT /api/nomina/bonificaciones/{id}/cuadro
     *
     * Guarda el bono entero de una vez, como se ve en el cuadro de Excel de
     * la fábrica: nombre, cada cuánto se mide y la escalera completa
     * ("de 900.000 a 950.000 paga 50.000, de 950.001 a 1.000.000 paga
     * 75.000..."). El tope es donde empieza el primer escalón: el que no
     * llega ahí no cobra.
     *
     * Antes había que crear el bono con un "tope" suelto y después agregar
     * las metas de a una; con once escalones eran once formularios y no se
     * parecía en nada al cuadro.
     *
     * Reemplaza la escalera: lo que ya se pagó no cambia, porque cada pago
     * guarda su bono congelado.
     */
    public function guardarCuadro(Request $request, int $id)
    {
        $bonificacion = NominaBonificacion::findOrFail($id);

        return $this->guardarEscalera($request, $bonificacion);
    }

    /** POST /api/nomina/bonificaciones/cuadro — el mismo, para uno nuevo. */
    public function crearConCuadro(Request $request)
    {
        return $this->guardarEscalera($request, new NominaBonificacion(['activo' => true]), 201);
    }

    private function guardarEscalera(Request $request, NominaBonificacion $bonificacion, int $status = 200)
    {
        $data = $request->validate([
            'nombre'          => 'required|string|max:80',
            'periodo'         => ['required', Rule::in(self::PERIODOS)],
            'metas'           => 'required|array|min:1|max:60',
            'metas.*.desde'   => 'required|numeric|min:0',
            'metas.*.hasta'   => 'nullable|numeric|min:0',
            'metas.*.monto'   => 'required|numeric|min:0',
        ], [
            'nombre.required' => 'Ponle un nombre al bono.',
            'metas.required'  => 'Arma el cuadro: al menos un escalón.',
            'metas.min'       => 'Arma el cuadro: al menos un escalón.',
        ]);

        // Ordenada por "desde" y sin escalones que se pisen: si dos cubren
        // el mismo valor, cuál se paga sería cuestión de suerte. Solo el
        // último puede quedar abierto (sin "hasta").
        $metas = collect($data['metas'])
            ->map(fn ($m) => ['desde' => round((float) $m['desde']), 'hasta' => isset($m['hasta']) && $m['hasta'] !== '' ? round((float) $m['hasta']) : null, 'monto' => round((float) $m['monto'])])
            ->sortBy('desde')->values();

        foreach ($metas as $i => $m) {
            $n = $i + 1;
            if ($m['hasta'] !== null && $m['hasta'] < $m['desde']) {
                throw ValidationException::withMessages(['metas' => ["En el escalón {$n} el \"hasta\" es menor que el \"desde\"."]]);
            }
            if ($m['hasta'] === null && $i < $metas->count() - 1) {
                throw ValidationException::withMessages(['metas' => ["Solo el último escalón puede quedar sin \"hasta\" (el {$n} no lo es)."]]);
            }
            $sig = $metas[$i + 1] ?? null;
            if ($sig && $m['hasta'] !== null && $sig['desde'] <= $m['hasta']) {
                throw ValidationException::withMessages(['metas' => ["Los escalones {$n} y " . ($n + 1) . ' se pisan.']]);
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($bonificacion, $data, $metas) {
            $bonificacion->fill([
                'nombre'      => $data['nombre'],
                'periodo'     => $data['periodo'],
                // El que no llega al primer escalón no cobra.
                'tope'        => $metas[0]['desde'],
                'tope_activo' => true,
            ])->save();

            $bonificacion->metas()->delete();
            foreach ($metas as $m) {
                NominaBonificacionMeta::create($m + ['nomina_bonificacion_id' => $bonificacion->id, 'activo' => true]);
            }
        });

        return response()->json($this->comoJson($bonificacion->fresh('metas')), $status);
    }

    /** POST /api/nomina/bonificaciones/{id}/metas */
    public function agregarMeta(Request $request, int $id)
    {
        $bonificacion = NominaBonificacion::with('metas')->findOrFail($id);

        $data = $request->validate([
            'desde' => 'required|numeric|min:0',
            'hasta' => 'nullable|numeric|min:0',
            'monto' => 'required|numeric|min:0',
        ], [
            'desde.required' => 'Falta desde cuánto aplica esta meta.',
            'monto.required' => 'Falta cuánto se paga en esta meta.',
        ]);

        $this->validarTramo($bonificacion, $data['desde'], $data['hasta'] ?? null, null);

        $meta = NominaBonificacionMeta::create([
            'nomina_bonificacion_id' => $bonificacion->id,
            'desde'  => $data['desde'],
            'hasta'  => $data['hasta'] ?? null,
            'monto'  => $data['monto'],
            'activo' => true,
        ]);

        return response()->json($this->comoJson($bonificacion->fresh('metas')), 201);
    }

    /** PATCH /api/nomina/metas/{id} */
    public function actualizarMeta(Request $request, int $id)
    {
        $meta = NominaBonificacionMeta::findOrFail($id);
        $bonificacion = NominaBonificacion::with('metas')->findOrFail($meta->nomina_bonificacion_id);

        $data = $request->validate([
            'desde'  => 'sometimes|numeric|min:0',
            'hasta'  => 'sometimes|nullable|numeric|min:0',
            'monto'  => 'sometimes|numeric|min:0',
            'activo' => 'sometimes|boolean',
        ]);

        // Solo se revisa el solape si de verdad se están moviendo los
        // bordes: prender o apagar una meta no tiene por qué revalidarse.
        if (array_key_exists('desde', $data) || array_key_exists('hasta', $data)) {
            $this->validarTramo(
                $bonificacion,
                $data['desde'] ?? (float) $meta->desde,
                array_key_exists('hasta', $data) ? $data['hasta'] : ($meta->hasta === null ? null : (float) $meta->hasta),
                $meta->id
            );
        }

        $meta->update($data);

        return response()->json($this->comoJson($bonificacion->fresh('metas')));
    }

    /** DELETE /api/nomina/metas/{id} */
    public function eliminarMeta(int $id)
    {
        $meta = NominaBonificacionMeta::findOrFail($id);
        $bonificacionId = $meta->nomina_bonificacion_id;
        $meta->delete();

        return response()->json($this->comoJson(NominaBonificacion::with('metas')->findOrFail($bonificacionId)));
    }

    /**
     * Un tramo tiene que ser coherente y no pisarse con otro: si dos metas
     * cubren el mismo monto, cuál se paga sería cuestión de suerte.
     * `hasta` en null es "de aquí en adelante", o sea infinito.
     */
    private function validarTramo(NominaBonificacion $bonificacion, float $desde, ?float $hasta, ?int $ignorarId): void
    {
        if ($hasta !== null && $hasta < $desde) {
            throw ValidationException::withMessages([
                'hasta' => ['El "hasta" no puede ser menor que el "desde".'],
            ]);
        }

        $infinito = INF;
        $finNuevo = $hasta ?? $infinito;

        foreach ($bonificacion->metas as $otra) {
            if ($ignorarId !== null && $otra->id === $ignorarId) {
                continue;
            }

            $inicioOtra = (float) $otra->desde;
            $finOtra    = $otra->hasta === null ? $infinito : (float) $otra->hasta;

            if ($desde <= $finOtra && $inicioOtra <= $finNuevo) {
                throw ValidationException::withMessages([
                    'desde' => ["Ese rango se pisa con la meta {$otra->etiqueta()}. Ajusta los límites."],
                ]);
            }
        }
    }
}
