<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaPago;
use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El roster de trabajadores de nómina — mano de obra de taller que casi
 * nunca tiene cuenta en la app. Acceso ya validado por el middleware
 * `permiso:acceso_nomina` en las rutas.
 *
 * El listado no es solo el roster: trae también lo que cada uno lleva
 * devengado en el ciclo en curso (días corridos, faltas ya descontadas y
 * neto a hoy), que es lo que se ve en la pantalla de Trabajadores.
 */
class EmpleadoController extends Controller
{
    private function comoJson(Empleado $e, ?Carbon $hoy = null): array
    {
        $base = [
            'id'                 => $e->id,
            'nombre'             => $e->nombre,
            'cedula'             => $e->cedula,
            'cargo'              => $e->cargo,
            'nomina_sueldo_id'   => $e->nomina_sueldo_id,
            'sueldo'             => $e->relationLoaded('sueldo') ? $e->sueldo : null,
            // Null = no aplica para bonificación por producción.
            'nomina_bonificacion_id' => $e->nomina_bonificacion_id,
            'bonificacion_nombre'    => $e->bonificacion?->nombre,
            'periodicidad'       => $e->periodicidad,
            'periodicidad_label' => $e->labelPeriodicidad(),
            'activo'             => (bool) $e->activo,
            // Lo que de verdad rige, resuelto desde el sueldo del catálogo,
            // para que el front no tenga que hacer la conversión hora/día.
            'valor_dia_efectivo'  => $e->valorDiaEfectivo(),
            'valor_hora_efectivo' => $e->valorHoraEfectivo(),
            'horas_dia_efectivo'  => $e->horasDiaEfectivo(),
            'label_efectivo'      => $e->labelEfectivo(),
        ];

        // El acumulado del ciclo solo tiene sentido para quien está activo
        // y ya tiene sueldo: sin eso daría $0 y confundiría más que ayudar.
        if (! $hoy || ! $e->activo || ! $e->nomina_sueldo_id) {
            return $base + ['ciclo' => null, 'faltas_futuras' => 0];
        }

        $ciclo = NominaLiquidador::cicloActual($e, $hoy);

        // Lo que ya avisó que va a faltar en ciclos posteriores a este.
        $finCiclo = CicloNomina::fecha($ciclo['fecha_fin']);
        $futuras = $e->ausencias
            ->filter(fn ($a) => CicloNomina::fecha($a->fecha)->greaterThan($finCiclo))
            ->count();

        return $base + ['ciclo' => $ciclo, 'faltas_futuras' => $futuras];
    }

    /** GET /api/nomina/empleados?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $hoy = CicloNomina::hoy();

        $q = Empleado::with(NominaLiquidador::relaciones())->orderBy('nombre');

        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get()->map(fn (Empleado $e) => $this->comoJson($e, $hoy)));
    }

    /** POST /api/nomina/empleados */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:120',
            'cedula'           => 'nullable|string|max:20|unique:empleados,cedula',
            'cargo'            => 'nullable|string|max:80',
            'nomina_sueldo_id' => 'required|exists:nomina_sueldos,id',
            'nomina_bonificacion_id' => 'nullable|exists:nomina_bonificaciones,id',
            'periodicidad'     => ['nullable', Rule::in(CicloNomina::FRECUENCIAS)],
        ], [
            'nombre.required'           => 'El nombre es obligatorio.',
            'cedula.unique'             => 'Ya hay un empleado con esa cédula.',
            'nomina_sueldo_id.required' => 'Elige el sueldo de este trabajador.',
        ]);

        $empleado = Empleado::create([
            'nombre'           => $data['nombre'],
            'cedula'           => $data['cedula'] ?? null,
            'cargo'            => $data['cargo'] ?? null,
            'nomina_sueldo_id' => $data['nomina_sueldo_id'],
            'nomina_bonificacion_id' => $data['nomina_bonificacion_id'] ?? null,
            'periodicidad'     => $data['periodicidad'] ?? 'quincenal',
            'activo'           => true,
        ]);

        return response()->json($this->comoJson($empleado->load('sueldo', 'bonificacion')), 201);
    }

    /** PATCH /api/nomina/empleados/{id} */
    public function update(Request $request, int $id)
    {
        $empleado = Empleado::findOrFail($id);

        $data = $request->validate([
            'nombre'           => 'sometimes|required|string|max:120',
            'cedula'           => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('empleados', 'cedula')->ignore($empleado->id)],
            'cargo'            => 'sometimes|nullable|string|max:80',
            'nomina_sueldo_id' => 'sometimes|required|exists:nomina_sueldos,id',
            // Mandar null aquí es desactivarle la bonificación.
            'nomina_bonificacion_id' => 'sometimes|nullable|exists:nomina_bonificaciones,id',
            'periodicidad'     => ['sometimes', 'required', Rule::in(CicloNomina::FRECUENCIAS)],
            'activo'           => 'sometimes|boolean',
        ], [
            'cedula.unique'             => 'Ya hay un empleado con esa cédula.',
            'nomina_sueldo_id.required' => 'Elige el sueldo de este trabajador.',
        ]);

        $empleado->update($data);

        return response()->json($this->comoJson($empleado->fresh(['sueldo', 'bonificacion'])));
    }

    /**
     * DELETE /api/nomina/empleados/{id}
     *
     * Solo se borra de verdad si nunca se le pagó nada. Si ya tiene
     * historial, se desactiva: borrarlo dejaría pagos hechos apuntando a un
     * trabajador inexistente.
     */
    public function destroy(int $id)
    {
        $empleado = Empleado::findOrFail($id);
        $pagos = NominaPago::where('empleado_id', $id)->count();

        if ($pagos > 0) {
            $empleado->update(['activo' => false]);

            return response()->json([
                'message' => "\"{$empleado->nombre}\" ya tiene {$pagos} pago(s) registrado(s), así que no se borra: " .
                             'queda desactivado y deja de aparecer para cobrar.',
                'desactivado' => true,
            ]);
        }

        $empleado->delete();

        return response()->json(['message' => 'Trabajador eliminado.', 'desactivado' => false]);
    }
}
