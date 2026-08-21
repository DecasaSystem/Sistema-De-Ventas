<?php

namespace App\Http\Controllers;

use App\Models\NominaPago;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La vista de Nómina sobre los trabajadores.
 *
 * Acá NO se crea gente: un trabajador se da de alta una sola vez en
 * Trabajadores (UsuarioController) y aparece solo en esta lista. Lo que se
 * hace desde acá es lo propio de Nómina — asignarle sueldo, bonificación y
 * cada cuánto se le paga — más ver lo que lleva devengado en el ciclo.
 *
 * Antes esto era una segunda tabla de personas (`empleados`) paralela a
 * `usuarios`, y la misma persona podía existir dos veces sin relación.
 */
class EmpleadoController extends Controller
{
    private function comoJson(Usuario $e, ?Carbon $hoy = null): array
    {
        $base = [
            'id'                 => $e->id,
            'nombre'             => $e->nombre,
            'cedula'             => $e->cedula,
            // El cargo es el rol del trabajador, que se mantiene en Trabajadores.
            'cargo'              => $e->rolAsignado?->nombre,
            'no_usa_programa'    => (bool) $e->no_usa_programa,
            'nomina_sueldo_id'   => $e->nomina_sueldo_id,
            'sueldo'             => $e->relationLoaded('sueldo') ? $e->sueldo : null,
            'nomina_bonificacion_id' => $e->nomina_bonificacion_id,
            'bonificacion_nombre'    => $e->bonificacion?->nombre,
            'periodicidad'       => $e->periodicidad,
            'periodicidad_label' => $e->labelPeriodicidad(),
            'activo'             => (bool) $e->activo,
            'valor_dia_efectivo'  => $e->valorDiaEfectivo(),
            'valor_hora_efectivo' => $e->valorHoraEfectivo(),
            'horas_dia_efectivo'  => $e->horasDiaEfectivo(),
            'label_efectivo'      => $e->labelEfectivo(),
        ];

        if (! $hoy || ! $e->activo || ! $e->nomina_sueldo_id) {
            return $base + ['ciclo' => null, 'faltas_futuras' => 0];
        }

        $ciclo = NominaLiquidador::cicloActual($e, $hoy);

        $finCiclo = CicloNomina::fecha($ciclo['fecha_fin']);
        $futuras = $e->ausencias
            ->filter(fn ($a) => CicloNomina::fecha($a->fecha)->greaterThan($finCiclo))
            ->count();

        return $base + ['ciclo' => $ciclo, 'faltas_futuras' => $futuras];
    }

    /**
     * GET /api/nomina/empleados?incluir_inactivos=1
     *
     * Todos los trabajadores, no solo los de fábrica: a un vendedor con
     * sueldo base también se le puede liquidar. Quien no tenga sueldo
     * asignado sale igual en la lista, para poder asignárselo desde acá.
     */
    public function index(Request $request)
    {
        $hoy = CicloNomina::hoy();

        $q = Usuario::with(NominaLiquidador::relaciones())->orderBy('nombre');

        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get()->map(fn (Usuario $e) => $this->comoJson($e, $hoy)));
    }

    /**
     * PATCH /api/nomina/empleados/{id}
     *
     * Solo lo que le toca a Nómina. El nombre, la cédula y el rol se cambian
     * en Trabajadores: acá se rebotan a propósito para que no haya dos sitios
     * editando lo mismo.
     */
    public function update(Request $request, int $id)
    {
        $trabajador = Usuario::findOrFail($id);

        $data = $request->validate([
            'nomina_sueldo_id'       => 'sometimes|nullable|exists:nomina_sueldos,id',
            'nomina_bonificacion_id' => 'sometimes|nullable|exists:nomina_bonificaciones,id',
            'periodicidad'           => ['sometimes', 'required', Rule::in(CicloNomina::FRECUENCIAS)],
        ]);

        $trabajador->update($data);

        return response()->json($this->comoJson(
            $trabajador->fresh(NominaLiquidador::relaciones()), CicloNomina::hoy()
        ));
    }

    /**
     * DELETE /api/nomina/empleados/{id}
     *
     * Sacar a alguien de Nómina es quitarle el sueldo, no borrar a la
     * persona: el trabajador sigue existiendo en Trabajadores. Borrarlo de
     * verdad se hace allá, y solo si nunca cobró.
     */
    public function destroy(int $id)
    {
        $trabajador = Usuario::findOrFail($id);
        $pagos = NominaPago::where('usuario_id', $id)->count();

        $trabajador->update(['nomina_sueldo_id' => null, 'nomina_bonificacion_id' => null]);

        return response()->json([
            'message' => $pagos > 0
                ? "\"{$trabajador->nombre}\" tiene {$pagos} pago(s) en el historial, que se conservan. " .
                  'Se le quitó el sueldo, así que deja de aparecer para cobrar.'
                : "Se le quitó el sueldo a \"{$trabajador->nombre}\": deja de aparecer para cobrar.",
        ]);
    }
}
