<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\NominaAjuste;
use App\Models\NominaPago;
use App\Services\CicloNomina;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Bonos y descuentos con nombre libre (positivo suma, negativo resta),
 * anotados contra el trabajador y una fecha — mismo patrón que las faltas.
 * Caen solos en el ciclo que contenga esa fecha y se cobran con él.
 */
class NominaAjusteController extends Controller
{
    private function comoJson(NominaAjuste $a): array
    {
        $pago = $a->relationLoaded('pago') ? $a->pago : null;

        return [
            'id'            => $a->id,
            'usuario_id'   => $a->usuario_id,
            'fecha'         => $a->fecha->toDateString(),
            'nombre'        => $a->nombre,
            'monto'         => (float) $a->monto,
            'pagado'        => $a->estaPagado(),
            'registrado_en' => $a->created_at->toIso8601String(),
            'pago_id'       => $a->nomina_pago_id,
            'ciclo'         => $pago?->nombreCiclo(),
        ];
    }

    /** GET /api/nomina/ajustes?usuario_id=&pendientes=1 */
    public function index(Request $request)
    {
        $q = NominaAjuste::query()->with('pago')->orderByDesc('fecha');

        if ($empleadoId = $request->query('usuario_id')) {
            $q->where('usuario_id', $empleadoId);
        }
        if ($request->boolean('pendientes')) {
            $q->whereNull('nomina_pago_id');
        }

        return response()->json($q->get()->map(fn (NominaAjuste $a) => $this->comoJson($a)));
    }

    /** POST /api/nomina/ajustes */
    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => 'required|exists:usuarios,id',
            'fecha'       => 'required|date',
            'nombre'      => 'required|string|max:120',
            'monto'       => 'required|numeric',
        ], [
            'usuario_id.required' => 'Elige el trabajador.',
            'fecha.required'       => 'La fecha es obligatoria.',
            'nombre.required'      => 'Ponle un nombre al ajuste.',
            'monto.required'       => 'El valor es obligatorio (negativo si es un descuento).',
        ]);

        $empleado = Usuario::findOrFail($data['usuario_id']);
        $fecha    = CicloNomina::fecha($data['fecha']);

        // Si esa fecha ya quedó dentro de un ciclo cobrado, el ajuste no
        // tendría dónde aplicarse: se pagó de más o de menos y no hay cómo
        // arreglarlo por este lado.
        if ($pago = $this->pagoQueCubre($empleado->id, $fecha)) {
            return response()->json([
                'message' => "Esa fecha ya se le pagó a {$empleado->nombre} ({$pago->nombreCiclo()}). " .
                             'Anótalo en una fecha del ciclo actual o deshaz ese pago.',
            ], 422);
        }

        $ajuste = NominaAjuste::create([
            'usuario_id' => $empleado->id,
            'fecha'       => $fecha,
            'nombre'      => $data['nombre'],
            'monto'       => $data['monto'],
        ]);

        return response()->json($this->comoJson($ajuste), 201);
    }

    /** DELETE /api/nomina/ajustes/{id} */
    public function destroy(int $id)
    {
        $ajuste = NominaAjuste::with('pago')->findOrFail($id);

        if ($ajuste->estaPagado()) {
            return response()->json([
                'message' => 'Ese ajuste ya se pagó (' . $ajuste->pago->nombreCiclo() . ') y no se puede quitar.',
            ], 422);
        }

        $ajuste->delete();

        return response()->json(['ok' => true]);
    }

    private function pagoQueCubre(int $empleadoId, Carbon $fecha): ?NominaPago
    {
        return NominaPago::where('usuario_id', $empleadoId)
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->first();
    }
}
