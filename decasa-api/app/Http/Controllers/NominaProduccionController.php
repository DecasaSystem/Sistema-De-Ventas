<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaPago;
use App\Models\NominaProduccion;
use App\Services\CicloNomina;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Lo que el trabajador va haciendo y que suma para su bonificación:
 * "base cama redonda $30.000", "silla blanca $10.000 × 4".
 *
 * Mismo patrón que faltas y ajustes: cuelga del trabajador y una fecha, y
 * cae en el ciclo que contenga esa fecha. Cuando ese ciclo se cobra, se
 * evalúa el total contra el esquema de bonificación asignado.
 */
class NominaProduccionController extends Controller
{
    private function comoJson(NominaProduccion $p): array
    {
        $pago = $p->relationLoaded('pago') ? $p->pago : null;

        return [
            'id'             => $p->id,
            'empleado_id'    => $p->empleado_id,
            'fecha'          => $p->fecha->toDateString(),
            'concepto'       => $p->concepto,
            'valor_unitario' => (float) $p->valor_unitario,
            'cantidad'       => (float) $p->cantidad,
            'total'          => (float) $p->total,
            'pagada'         => $p->estaPagada(),
            'registrada_en'  => $p->created_at->toIso8601String(),
            'pago_id'        => $p->nomina_pago_id,
            'ciclo'          => $pago?->nombreCiclo() ?? $this->cicloDe($p),
        ];
    }

    /** En qué ciclo va a caer una producción que todavía no se ha cobrado. */
    private function cicloDe(NominaProduccion $p): ?string
    {
        $empleado = $p->relationLoaded('empleado') ? $p->empleado : null;
        if (! $empleado) {
            return null;
        }

        [$inicio, $fin] = CicloNomina::rango($empleado->periodicidad, $p->fecha);

        return CicloNomina::nombre($empleado->periodicidad, $inicio, $fin);
    }

    /** GET /api/nomina/producciones?empleado_id=&pendientes=1 */
    public function index(Request $request)
    {
        $q = NominaProduccion::query()->with('pago', 'empleado')->orderByDesc('fecha')->orderByDesc('id');

        if ($empleadoId = $request->query('empleado_id')) {
            $q->where('empleado_id', $empleadoId);
        }
        if ($request->boolean('pendientes')) {
            $q->whereNull('nomina_pago_id');
        }

        return response()->json($q->get()->map(fn (NominaProduccion $p) => $this->comoJson($p)));
    }

    /** POST /api/nomina/producciones */
    public function store(Request $request)
    {
        $data = $request->validate([
            'empleado_id'    => 'required|exists:empleados,id',
            'fecha'          => 'required|date',
            'concepto'       => 'required|string|max:120',
            'valor_unitario' => 'required|numeric|min:0',
            'cantidad'       => 'required|numeric|min:0.01|max:9999',
        ], [
            'empleado_id.required'    => 'Elige el trabajador.',
            'fecha.required'          => 'La fecha es obligatoria.',
            'concepto.required'       => 'Escribe qué hizo (mesa de comedor, silla blanca...).',
            'valor_unitario.required' => 'Falta cuánto vale cada una.',
            'cantidad.required'       => 'Falta cuántas hizo.',
        ]);

        $empleado = Empleado::findOrFail($data['empleado_id']);
        $fecha    = CicloNomina::fecha($data['fecha']);

        // Si esa fecha ya quedó dentro de un ciclo cobrado, sumarla ahora no
        // cambiaría nada: la bonificación de ese ciclo ya se liquidó.
        if ($pago = $this->pagoQueCubre($empleado->id, $fecha)) {
            return response()->json([
                'message' => "Esa fecha ya se le pagó a {$empleado->nombre} ({$pago->nombreCiclo()}), " .
                             'así que ya no suma para la bonificación de ese ciclo. Regístrala en el ciclo actual o deshaz ese pago.',
            ], 422);
        }

        // El total se guarda calculado y no se recalcula al leer: es lo que
        // de verdad sumó, aunque después se corrija el valor unitario.
        $total = round((float) $data['valor_unitario'] * (float) $data['cantidad']);

        $produccion = NominaProduccion::create([
            'empleado_id'    => $empleado->id,
            'fecha'          => $fecha,
            'concepto'       => $data['concepto'],
            'valor_unitario' => $data['valor_unitario'],
            'cantidad'       => $data['cantidad'],
            'total'          => $total,
        ]);
        $produccion->setRelation('empleado', $empleado);

        return response()->json($this->comoJson($produccion), 201);
    }

    /** DELETE /api/nomina/producciones/{id} */
    public function destroy(int $id)
    {
        $produccion = NominaProduccion::with('pago')->findOrFail($id);

        if ($produccion->estaPagada()) {
            return response()->json([
                'message' => 'Esa producción ya entró en un pago (' . $produccion->pago->nombreCiclo() . ') y no se puede quitar.',
            ], 422);
        }

        $produccion->delete();

        return response()->json(['ok' => true]);
    }

    private function pagoQueCubre(int $empleadoId, Carbon $fecha): ?NominaPago
    {
        return NominaPago::where('empleado_id', $empleadoId)
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->first();
    }
}
