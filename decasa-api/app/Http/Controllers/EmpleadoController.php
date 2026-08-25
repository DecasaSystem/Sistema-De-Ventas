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
            'nomina_desde'       => $e->nomina_desde?->toDateString(),
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

        // "En nómina" es tener sueldo asignado. Sin este filtro la lista traía
        // a los 53 trabajadores, así que sacar a alguien no se notaba: se le
        // quitaba el sueldo pero seguía ahí, y parecía que el botón no servía.
        // Con `sin_sueldo=1` se piden los otros, para poder agregarlos.
        if ($request->boolean('sin_sueldo')) {
            $q->whereNull('nomina_sueldo_id');
        } elseif (! $request->boolean('todos')) {
            $q->whereNotNull('nomina_sueldo_id');
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
            // Se puede corregir a mano: si alguien entró el 20 y se carga el
            // 25, hay que poder decirlo para que la primera quincena cuadre.
            'nomina_desde'           => 'sometimes|nullable|date',
        ]);

        // Entra a nómina ahora: desde hoy, salvo que se diga otra fecha. Sin
        // esto, los ciclos ya cerrados le saldrían como pagos atrasados.
        if (! empty($data['nomina_sueldo_id']) && ! $trabajador->nomina_desde
            && ! array_key_exists('nomina_desde', $data)) {
            $data['nomina_desde'] = now()->toDateString();
        }

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
    /**
     * PATCH /api/nomina/empleados/lote
     *
     * Ponerle el mismo sueldo y la misma frecuencia a varios de una. Los nueve
     * lijadores ganan lo mismo: cargarlos uno por uno son treinta y dos
     * formularios, y eso es lo que hace que nómina no arranque nunca.
     */
    public function lote(Request $request)
    {
        $data = $request->validate([
            'usuarios'           => 'required|array|min:1',
            'usuarios.*'         => 'integer|exists:usuarios,id',
            'nomina_sueldo_id'   => 'nullable|exists:nomina_sueldos,id',
            'periodicidad'       => ['nullable', Rule::in(['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])],
            'nomina_bonificacion_id' => 'nullable|exists:nomina_bonificaciones,id',
        ]);

        // Solo se toca lo que venga: mandar el sueldo no debe borrarle a nadie
        // la frecuencia que ya tenía puesta.
        $cambios = array_filter([
            'nomina_sueldo_id'       => $data['nomina_sueldo_id'] ?? null,
            'periodicidad'           => $data['periodicidad'] ?? null,
            'nomina_bonificacion_id' => $data['nomina_bonificacion_id'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($cambios)) {
            return response()->json([
                'message' => 'No mandaste nada que cambiar: elige el sueldo, la frecuencia o la bonificación.',
            ], 422);
        }

        // Al entrar a nómina se marca desde cuándo, si no lo tenían: sin eso,
        // los ciclos ya cerrados aparecerían como pagos atrasados que nadie debe.
        if (isset($cambios['nomina_sueldo_id'])) {
            Usuario::whereIn('id', $data['usuarios'])
                ->whereNull('nomina_desde')
                ->update(['nomina_desde' => now()->toDateString()]);
        }

        $n = Usuario::whereIn('id', $data['usuarios'])->update($cambios);

        return response()->json([
            'ok'      => true,
            'message' => $n === 1 ? 'Se actualizó 1 trabajador.' : "Se actualizaron {$n} trabajadores.",
        ]);
    }

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
