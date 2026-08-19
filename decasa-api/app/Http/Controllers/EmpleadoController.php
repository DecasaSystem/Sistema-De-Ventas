<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El roster de trabajadores de nómina — mano de obra de taller que casi
 * nunca tiene cuenta en la app. Acceso ya validado por el middleware
 * `permiso:acceso_nomina` en las rutas.
 */
class EmpleadoController extends Controller
{
    /** GET /api/nomina/empleados?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = Empleado::query()->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get());
    }

    /** POST /api/nomina/empleados */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:120',
            'cedula'      => 'nullable|string|max:20|unique:empleados,cedula',
            'cargo'       => 'nullable|string|max:80',
            'valor_label' => 'nullable|string|max:60',
            'valor_base'  => 'nullable|numeric|min:0',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'cedula.unique'   => 'Ya hay un empleado con esa cédula.',
        ]);

        $empleado = Empleado::create([
            'nombre'      => $data['nombre'],
            'cedula'      => $data['cedula'] ?? null,
            'cargo'       => $data['cargo'] ?? null,
            'valor_label' => $data['valor_label'] ?? 'Salario quincenal',
            'valor_base'  => $data['valor_base'] ?? 0,
            'activo'      => true,
        ]);

        return response()->json($empleado, 201);
    }

    /** PATCH /api/nomina/empleados/{id} */
    public function update(Request $request, int $id)
    {
        $empleado = Empleado::findOrFail($id);

        $data = $request->validate([
            'nombre'      => 'sometimes|required|string|max:120',
            'cedula'      => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('empleados', 'cedula')->ignore($empleado->id)],
            'cargo'       => 'sometimes|nullable|string|max:80',
            'valor_label' => 'sometimes|required|string|max:60',
            'valor_base'  => 'sometimes|numeric|min:0',
            'activo'      => 'sometimes|boolean',
        ], [
            'cedula.unique' => 'Ya hay un empleado con esa cédula.',
        ]);

        $empleado->update($data);

        return response()->json($empleado->fresh());
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
