<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaAusencia;
use App\Models\NominaItem;
use App\Models\NominaPeriodo;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

/**
 * Una falta registrada con fecha real (y horas, para faltas parciales).
 * Se guarda contra el empleado y la fecha — no contra un item — porque la
 * fecha puede caer en una quincena que todavía no existe: en ese caso
 * queda pendiente y NominaPeriodoService la engancha sola cuando ese
 * período se crea.
 */
class NominaAusenciaController extends Controller
{
    /**
     * Trae también cuándo se registró (no solo la fecha en que faltó — se
     * pueden loguear días después) y a qué período quedó ligada, para poder
     * mostrar el rastro completo de por qué se descontó algo al pagar.
     */
    private function comoJson(NominaAusencia $a): array
    {
        $item = $a->relationLoaded('item') ? $a->item : null;

        return [
            'id'             => $a->id,
            'empleado_id'    => $a->empleado_id,
            'fecha'          => $a->fecha->toDateString(),
            'horas'          => (float) $a->horas,
            'motivo'         => $a->motivo,
            'pendiente'      => $a->estaPendiente(),
            'registrada_en'  => $a->created_at->toIso8601String(),
            'periodo_id'     => $item?->nomina_periodo_id,
            'periodo_nombre' => $item?->periodo?->nombre,
            'pagado'         => $item?->periodo?->estaPagado() ?? false,
            'monto'          => $item ? round((float) $a->horas * $item->valorHora()) : null,
        ];
    }

    /** GET /api/nomina/ausencias?empleado_id=&pendientes=1 */
    public function index(Request $request)
    {
        $q = NominaAusencia::query()->with('item.periodo')->orderByDesc('fecha');
        if ($empleadoId = $request->query('empleado_id')) {
            $q->where('empleado_id', $empleadoId);
        }
        if ($request->boolean('pendientes')) {
            $q->whereNull('nomina_item_id');
        }

        return response()->json($q->get()->map(fn ($a) => $this->comoJson($a)));
    }

    /**
     * POST /api/nomina/ausencias
     *
     * Un rango de fechas (o una sola, dejando fecha_fin vacío) se expande
     * en una fila por día. Cada fecha se vincula sola al período que la
     * cubra si ya existe; si no, queda pendiente hasta que se cree.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'empleado_id'  => 'required|exists:empleados,id',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
            'horas'        => 'nullable|numeric|min:0|max:24',
            'motivo'       => 'nullable|string|max:160',
        ], [
            'empleado_id.required'  => 'Elige el trabajador.',
            'fecha_inicio.required' => 'La fecha es obligatoria.',
        ]);

        $empleado = Empleado::with('sueldo')->findOrFail($data['empleado_id']);
        $horas    = $data['horas'] ?? $empleado->horasDiaEfectivo();

        $inicio = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fin    = isset($data['fecha_fin']) ? Carbon::parse($data['fecha_fin'])->startOfDay() : $inicio->copy();

        $guardadas   = [];
        $noAplicadas = [];

        foreach (CarbonPeriod::create($inicio, $fin) as $fecha) {
            $periodo = NominaPeriodo::whereDate('fecha_inicio', '<=', $fecha->toDateString())
                ->whereDate('fecha_fin', '>=', $fecha->toDateString())
                ->first();

            if ($periodo && $periodo->estaPagado()) {
                $noAplicadas[] = $fecha->toDateString();
                continue;
            }

            $item = $periodo
                ? NominaItem::where('nomina_periodo_id', $periodo->id)->where('empleado_id', $empleado->id)->first()
                : null;
            $item?->setRelation('periodo', $periodo);

            $ausencia = NominaAusencia::updateOrCreate(
                ['empleado_id' => $empleado->id, 'fecha' => $fecha->toDateString()],
                ['horas' => $horas, 'motivo' => $data['motivo'] ?? null, 'nomina_item_id' => $item?->id]
            );
            $ausencia->setRelation('item', $item);

            $guardadas[] = $this->comoJson($ausencia);
        }

        return response()->json([
            'guardadas'    => $guardadas,
            'no_aplicadas' => $noAplicadas,
        ], 201);
    }

    /** DELETE /api/nomina/ausencias/{id} */
    public function destroy(int $id)
    {
        $ausencia = NominaAusencia::with('item.periodo')->findOrFail($id);

        if ($ausencia->item && $ausencia->item->periodo->estaPagado()) {
            return response()->json(['message' => 'Este período ya está pagado y quedó congelado.'], 422);
        }

        $ausencia->delete();

        return response()->json(['ok' => true]);
    }
}
