<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\NominaSueldo;
use Illuminate\Http\Request;

/**
 * El catálogo de sueldos con nombre ("Mínimo" = $X/día), para elegir al dar
 * de alta un trabajador en vez de escribir el valor cada vez. Acceso ya
 * validado por el middleware `permiso:acceso_nomina` en las rutas.
 */
class NominaSueldoController extends Controller
{
    /** GET /api/nomina/sueldos?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = NominaSueldo::query()->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get());
    }

    /** POST /api/nomina/sueldos */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:60',
            'valor'     => 'required|numeric|min:0',
            'unidad'    => 'nullable|in:dia,hora',
            'horas_dia' => 'nullable|numeric|min:0.25|max:24',
        ], [
            'nombre.required' => 'Ponle un nombre al sueldo.',
            'valor.required'  => 'El valor es obligatorio.',
        ]);

        $sueldo = NominaSueldo::create([
            'nombre'    => $data['nombre'],
            'valor'     => $data['valor'],
            'unidad'    => $data['unidad'] ?? 'dia',
            'horas_dia' => $data['horas_dia'] ?? 8,
            'activo'    => true,
        ]);

        return response()->json($sueldo, 201);
    }

    /**
     * PATCH /api/nomina/sueldos/{id}
     *
     * Cambiar el valor mueve lo que todavía no se ha cobrado — incluido el
     * ciclo en curso, que se recalcula completo con el valor nuevo. Lo ya
     * pagado no se toca: cada pago guarda su propia copia congelada.
     */
    public function update(Request $request, int $id)
    {
        $sueldo = NominaSueldo::findOrFail($id);

        $data = $request->validate([
            'nombre'    => 'sometimes|required|string|max:60',
            'valor'     => 'sometimes|numeric|min:0',
            'unidad'    => 'sometimes|in:dia,hora',
            'horas_dia' => 'sometimes|numeric|min:0.25|max:24',
            'activo'    => 'sometimes|boolean',
        ]);

        $sueldo->update($data);

        return response()->json($sueldo->fresh());
    }

    /**
     * DELETE /api/nomina/sueldos/{id}
     *
     * Solo se borra de verdad si ningún empleado lo tiene asignado. Si ya
     * lo usan, se desactiva: borrarlo dejaría a esos empleados sin sueldo.
     */
    public function destroy(int $id)
    {
        $sueldo = NominaSueldo::findOrFail($id);
        $usos = Usuario::where('nomina_sueldo_id', $id)->count();

        if ($usos > 0) {
            $sueldo->update(['activo' => false]);

            return response()->json([
                'message' => "\"{$sueldo->nombre}\" lo tienen asignado {$usos} trabajador(es), así que no se borra: " .
                             'queda desactivado y deja de ofrecerse para asignar de nuevo.',
                'desactivado' => true,
            ]);
        }

        $sueldo->delete();

        return response()->json(['message' => 'Sueldo eliminado.', 'desactivado' => false]);
    }
}
