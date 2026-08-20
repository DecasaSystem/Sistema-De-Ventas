<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El roster de trabajadores de nómina — mano de obra de taller que casi
 * nunca tiene cuenta en la app. Acceso ya validado por el middleware
 * `permiso:acceso_nomina` en las rutas.
 */
class EmpleadoController extends Controller
{
    private function comoJson(Empleado $e): array
    {
        return [
            'id'                => $e->id,
            'nombre'            => $e->nombre,
            'cedula'            => $e->cedula,
            'cargo'             => $e->cargo,
            'nomina_sueldo_id'  => $e->nomina_sueldo_id,
            'sueldo'            => $e->relationLoaded('sueldo') ? $e->sueldo : null,
            'valor_label'       => $e->valor_label,
            'valor'             => $e->valor !== null ? (float) $e->valor : null,
            'unidad'            => $e->unidad,
            'horas_dia'         => $e->horas_dia !== null ? (float) $e->horas_dia : null,
            'periodicidad'       => $e->periodicidad,
            'periodicidad_label' => $e->labelPeriodicidad(),
            'activo'            => (bool) $e->activo,
            // Lo que de verdad rige, venga del catálogo o sea personalizado —
            // así el front no tiene que resolver la fuente por su cuenta.
            'valor_dia_efectivo'  => $e->valorDiaEfectivo(),
            'valor_hora_efectivo' => $e->valorHoraEfectivo(),
            'horas_dia_efectivo'  => $e->horasDiaEfectivo(),
            'label_efectivo'      => $e->labelEfectivo(),
        ];
    }

    /**
     * Exactamente una fuente de valor: un sueldo del catálogo, o uno propio
     * (valor + unidad + horas_dia + valor_label). Nunca las dos, nunca ninguna.
     */
    private function validarFuenteDeValor(array $data): void
    {
        $tieneSueldo = ! empty($data['nomina_sueldo_id']);
        $tienePersonalizado = array_key_exists('valor', $data) && $data['valor'] !== null;

        if ($tieneSueldo && $tienePersonalizado) {
            throw ValidationException::withMessages([
                'valor' => ['Elige un sueldo del catálogo o escribe un valor propio, no las dos cosas.'],
            ]);
        }
        if (! $tieneSueldo && ! $tienePersonalizado) {
            throw ValidationException::withMessages([
                'valor' => ['Elige un sueldo del catálogo o escribe un valor propio para este trabajador.'],
            ]);
        }
    }

    /** GET /api/nomina/empleados?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = Empleado::with('sueldo')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get()->map(fn (Empleado $e) => $this->comoJson($e)));
    }

    /** POST /api/nomina/empleados */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:120',
            'cedula'           => 'nullable|string|max:20|unique:empleados,cedula',
            'cargo'            => 'nullable|string|max:80',
            'nomina_sueldo_id' => 'nullable|exists:nomina_sueldos,id',
            'valor_label'      => 'nullable|string|max:60',
            'valor'            => 'nullable|numeric|min:0',
            'unidad'           => 'nullable|in:dia,hora',
            'horas_dia'        => 'nullable|numeric|min:0.25|max:24',
            'periodicidad'     => 'nullable|in:diario,semanal,quincenal,20_dias,mensual',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'cedula.unique'   => 'Ya hay un empleado con esa cédula.',
        ]);

        $this->validarFuenteDeValor($data);

        $esPersonalizado = empty($data['nomina_sueldo_id']);

        $empleado = Empleado::create([
            'nombre'           => $data['nombre'],
            'cedula'           => $data['cedula'] ?? null,
            'cargo'            => $data['cargo'] ?? null,
            'nomina_sueldo_id' => $esPersonalizado ? null : $data['nomina_sueldo_id'],
            'valor_label'      => $esPersonalizado ? ($data['valor_label'] ?? 'Personalizado') : null,
            'valor'            => $esPersonalizado ? $data['valor'] : null,
            'unidad'           => $esPersonalizado ? ($data['unidad'] ?? 'dia') : 'dia',
            'horas_dia'        => $esPersonalizado ? ($data['horas_dia'] ?? 8) : 8,
            'periodicidad'     => $data['periodicidad'] ?? 'quincenal',
            'activo'           => true,
        ]);

        return response()->json($this->comoJson($empleado->load('sueldo')), 201);
    }

    /** PATCH /api/nomina/empleados/{id} */
    public function update(Request $request, int $id)
    {
        $empleado = Empleado::findOrFail($id);

        $data = $request->validate([
            'nombre'           => 'sometimes|required|string|max:120',
            'cedula'           => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('empleados', 'cedula')->ignore($empleado->id)],
            'cargo'            => 'sometimes|nullable|string|max:80',
            'nomina_sueldo_id' => 'sometimes|nullable|exists:nomina_sueldos,id',
            'valor_label'      => 'sometimes|nullable|string|max:60',
            'valor'            => 'sometimes|nullable|numeric|min:0',
            'unidad'           => 'sometimes|nullable|in:dia,hora',
            'horas_dia'        => 'sometimes|nullable|numeric|min:0.25|max:24',
            'periodicidad'     => 'sometimes|in:diario,semanal,quincenal,20_dias,mensual',
            'activo'           => 'sometimes|boolean',
        ], [
            'cedula.unique' => 'Ya hay un empleado con esa cédula.',
        ]);

        // Solo se revalida la fuente de valor si el pedido toca alguno de
        // los dos campos — así "activo" o "cargo" se pueden actualizar
        // solos sin tener que reenviar el valor completo cada vez.
        if (array_key_exists('nomina_sueldo_id', $data) || array_key_exists('valor', $data)) {
            $fuente = [
                'nomina_sueldo_id' => $data['nomina_sueldo_id'] ?? $empleado->nomina_sueldo_id,
                'valor'            => array_key_exists('valor', $data) ? $data['valor'] : $empleado->valor,
            ];
            $this->validarFuenteDeValor($fuente);

            $esPersonalizado = empty($fuente['nomina_sueldo_id']);
            $data['nomina_sueldo_id'] = $esPersonalizado ? null : $fuente['nomina_sueldo_id'];
            $data['valor']            = $esPersonalizado ? $fuente['valor'] : null;
            $data['valor_label']      = $esPersonalizado ? ($data['valor_label'] ?? $empleado->valor_label ?? 'Personalizado') : null;
            $data['unidad']           = $esPersonalizado ? ($data['unidad'] ?? $empleado->unidad ?? 'dia') : 'dia';
            $data['horas_dia']        = $esPersonalizado ? ($data['horas_dia'] ?? $empleado->horas_dia ?? 8) : 8;
        }

        $empleado->update($data);

        return response()->json($this->comoJson($empleado->fresh('sueldo')));
    }

    /**
     * DELETE /api/nomina/empleados/{id}
     *
     * Solo se borra de verdad si nunca estuvo en un período de nómina. Si
     * ya tiene historial, se desactiva: borrarlo dejaría períodos pagados
     * apuntando a un empleado inexistente.
     */
    public function destroy(int $id)
    {
        $empleado = Empleado::findOrFail($id);
        $usos = NominaItem::where('empleado_id', $id)->count();

        if ($usos > 0) {
            $empleado->update(['activo' => false]);

            return response()->json([
                'message' => "\"{$empleado->nombre}\" ya tiene {$usos} registro(s) de nómina, así que no se borra: " .
                             'queda desactivado y deja de aparecer para períodos nuevos.',
                'desactivado' => true,
            ]);
        }

        $empleado->delete();

        return response()->json(['message' => 'Empleado eliminado.', 'desactivado' => false]);
    }
}
