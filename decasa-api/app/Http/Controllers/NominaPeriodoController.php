<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaItem;
use App\Models\NominaPeriodo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NominaPeriodoController extends Controller
{
    private function conTotales(NominaPeriodo $p): array
    {
        $items = $p->items()->with(['empleado', 'ajustes'])->get();

        return [
            'id'            => $p->id,
            'nombre'        => $p->nombre,
            'fecha_inicio'  => $p->fecha_inicio->toDateString(),
            'fecha_fin'     => $p->fecha_fin->toDateString(),
            'dias_periodo'  => $p->dias_periodo,
            'pagado_at'     => $p->pagado_at,
            'total_general' => $items->sum(fn (NominaItem $i) => $i->total()),
            'items'         => $items->map(fn (NominaItem $i) => $this->itemComoJson($i))->values(),
        ];
    }

    private function itemComoJson(NominaItem $i): array
    {
        return [
            'id'              => $i->id,
            'empleado_id'     => $i->empleado_id,
            'empleado_nombre' => $i->empleado?->nombre,
            'empleado_cargo'  => $i->empleado?->cargo,
            'valor_label'     => $i->valor_label,
            'valor_dia'       => (float) $i->valor_dia,
            'dias_trabajados' => (float) $i->dias_trabajados,
            'observaciones'   => $i->observaciones,
            'subtotal'        => $i->subtotal(),
            'total'           => $i->total(),
            'ajustes'         => $i->ajustes->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre, 'monto' => (float) $a->monto]),
        ];
    }

    /** GET /api/nomina/periodos */
    public function index()
    {
        $periodos = NominaPeriodo::with('items.ajustes')->orderByDesc('fecha_inicio')->get();

        return response()->json($periodos->map(function (NominaPeriodo $p) {
            return [
                'id'            => $p->id,
                'nombre'        => $p->nombre,
                'fecha_inicio'  => $p->fecha_inicio->toDateString(),
                'fecha_fin'     => $p->fecha_fin->toDateString(),
                'dias_periodo'  => $p->dias_periodo,
                'pagado_at'     => $p->pagado_at,
                'total_general' => $p->items->sum(fn (NominaItem $i) => $i->total()),
                'num_empleados' => $p->items->count(),
            ];
        }));
    }

    /** GET /api/nomina/periodos/{id} */
    public function show(int $id)
    {
        $periodo = NominaPeriodo::findOrFail($id);

        return response()->json($this->conTotales($periodo));
    }

    /**
     * POST /api/nomina/periodos
     *
     * Crea el período y siembra un item por cada empleado activo, con su
     * valor de plantilla — arrancar una quincena queda como hoy: aparece la
     * lista completa y de ahí se ajusta lo que cambió.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'       => 'required|string|max:100',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
        ], [
            'nombre.required'    => 'El nombre del período es obligatorio.',
            'fecha_fin.after_or_equal' => 'La fecha final no puede ser antes de la inicial.',
        ]);

        $inicio = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fin    = Carbon::parse($data['fecha_fin'])->startOfDay();
        $dias   = $inicio->diffInDays($fin) + 1;

        $periodo = DB::transaction(function () use ($data, $inicio, $fin, $dias) {
            $periodo = NominaPeriodo::create([
                'nombre'       => $data['nombre'],
                'fecha_inicio' => $inicio,
                'fecha_fin'    => $fin,
                'dias_periodo' => $dias,
            ]);

            $activos = Empleado::with('sueldo')->where('activo', true)->get();
            foreach ($activos as $empleado) {
                NominaItem::create([
                    'nomina_periodo_id' => $periodo->id,
                    'empleado_id'       => $empleado->id,
                    'valor_label'       => $empleado->labelEfectivo(),
                    'valor_dia'         => $empleado->valorDiaEfectivo(),
                    'dias_trabajados'   => $dias,
                ]);
            }

            return $periodo;
        });

        return response()->json($this->conTotales($periodo), 201);
    }

    /** PATCH /api/nomina/periodos/{id} — solo nombre; las fechas no se tocan una vez tiene items. */
    public function update(Request $request, int $id)
    {
        $periodo = NominaPeriodo::findOrFail($id);
        $this->bloquearSiPagado($periodo);

        $data = $request->validate([
            'nombre' => 'required|string|max:100',
        ]);

        $periodo->update($data);

        return response()->json($this->conTotales($periodo));
    }

    /** DELETE /api/nomina/periodos/{id} — solo si no está pagado. */
    public function destroy(int $id)
    {
        $periodo = NominaPeriodo::findOrFail($id);
        $this->bloquearSiPagado($periodo);

        $periodo->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/nomina/periodos/{id}/pagar — congela el período. */
    public function marcarPagado(int $id)
    {
        $periodo = NominaPeriodo::findOrFail($id);
        $this->bloquearSiPagado($periodo);

        $periodo->update(['pagado_at' => now()]);

        return response()->json($this->conTotales($periodo));
    }

    /**
     * POST /api/nomina/periodos/{id}/empleados — agrega un empleado que
     * faltaba en la siembra inicial (llegó tarde, estaba inactivo, etc.).
     */
    public function agregarEmpleado(Request $request, int $id)
    {
        $periodo = NominaPeriodo::findOrFail($id);
        $this->bloquearSiPagado($periodo);

        $data = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
        ]);

        if (NominaItem::where('nomina_periodo_id', $periodo->id)->where('empleado_id', $data['empleado_id'])->exists()) {
            return response()->json(['message' => 'Ese empleado ya está en este período.'], 422);
        }

        $empleado = Empleado::with('sueldo')->findOrFail($data['empleado_id']);
        $item = NominaItem::create([
            'nomina_periodo_id' => $periodo->id,
            'empleado_id'       => $empleado->id,
            'valor_label'       => $empleado->labelEfectivo(),
            'valor_dia'         => $empleado->valorDiaEfectivo(),
            'dias_trabajados'   => $periodo->dias_periodo,
        ]);

        return response()->json($this->itemComoJson($item), 201);
    }

    private function bloquearSiPagado(NominaPeriodo $periodo): void
    {
        if ($periodo->estaPagado()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['message' => 'Este período ya está pagado y quedó congelado.'], 422)
            );
        }
    }
}
