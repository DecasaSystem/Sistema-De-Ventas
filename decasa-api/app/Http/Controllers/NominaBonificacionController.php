<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
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
            'num_trabajadores' => Empleado::where('nomina_bonificacion_id', $b->id)->where('activo', true)->count(),
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
        $usos = Empleado::where('nomina_bonificacion_id', $id)->count();

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
