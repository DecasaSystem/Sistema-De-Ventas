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
            'valor_dia'         => $e->valor_dia !== null ? (float) $e->valor_dia : null,
            'activo'            => (bool) $e->activo,
            // Lo que de verdad rige, venga del catálogo o sea personalizado —
            // así el front no tiene que resolver la fuente por su cuenta.
            'valor_dia_efectivo' => $e->valorDiaEfectivo(),
            'label_efectivo'      => $e->labelEfectivo(),
        ];
    }

    /**
     * Exactamente una fuente de valor: un sueldo del catálogo, o uno propio
     * (valor_dia + valor_label). Nunca las dos, nunca ninguna.
     */
    private function validarFuenteDeValor(array $data): void
    {
        $tieneSueldo = ! empty($data['nomina_sueldo_id']);
        $tienePersonalizado = array_key_exists('valor_dia', $data) && $data['valor_dia'] !== null;

        if ($tieneSueldo && $tienePersonalizado) {
            throw ValidationException::withMessages([
                'valor_dia' => ['Elige un sueldo del catálogo o escribe un valor propio, no las dos cosas.'],
            ]);
        }
        if (! $tieneSueldo && ! $tienePersonalizado) {
            throw ValidationException::withMessages([
                'valor_dia' => ['Elige un sueldo del catálogo o escribe un valor propio para este trabajador.'],
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
            'valor_dia'        => 'nullable|numeric|min:0',
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
            'valor_dia'        => $esPersonalizado ? $data['valor_dia'] : null,
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
            'valor_dia'        => 'sometimes|nullable|numeric|min:0',
            'activo'           => 'sometimes|boolean',
        ], [
            'cedula.unique' => 'Ya hay un empleado con esa cédula.',
        ]);

        // Solo se revalida la fuente de valor si el pedido toca alguno de
        // los dos campos — así "activo" o "cargo" se pueden actualizar
        // solos sin tener que reenviar el valor completo cada vez.
        if (array_key_exists('nomina_sueldo_id', $data) || array_key_exists('valor_dia', $data)) {
            $fuente = [
                'nomina_sueldo_id' => $data['nomina_sueldo_id'] ?? $empleado->nomina_sueldo_id,
                'valor_dia'        => array_key_exists('valor_dia', $data) ? $data['valor_dia'] : $empleado->valor_dia,
            ];
            $this->validarFuenteDeValor($fuente);

            $esPersonalizado = empty($fuente['nomina_sueldo_id']);
            $data['nomina_sueldo_id'] = $esPersonalizado ? null : $fuente['nomina_sueldo_id'];
            $data['valor_dia']        = $esPersonalizado ? $fuente['valor_dia'] : null;
            $data['valor_label']      = $esPersonalizado ? ($data['valor_label'] ?? $empleado->valor_label ?? 'Personalizado') : null;
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
