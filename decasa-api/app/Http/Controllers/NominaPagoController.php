<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaAjuste;
use App\Models\NominaAusencia;
use App\Models\NominaPago;
use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La pantalla de Pagos: quién está esperando cobrar y el acto de pagarle.
 *
 * No hay períodos que crear — los ciclos salen del calendario según con qué
 * frecuencia cobra cada quien (CicloNomina), y un ciclo aparece aquí el día
 * que termina: la quincena el 15 y el último del mes, la semana el domingo,
 * el diario ese mismo día.
 *
 * Los montos SIEMPRE se recalculan en el servidor al momento de pagar: el
 * front manda a quién y qué ciclo, nunca cuánto.
 */
class NominaPagoController extends Controller
{
    private function hoy(): Carbon
    {
        return CicloNomina::hoy();
    }

    /**
     * GET /api/nomina/pagos/pendientes
     *
     * Todo lo que está esperando que alguien lo cobre, ordenado por
     * frecuencia y nombre, más el aviso de cuántos trabajadores quedaron
     * fuera por no tener sueldo asignado.
     */
    public function pendientes()
    {
        $pendientes = NominaLiquidador::pendientes($this->hoy());

        $sinSueldo = Empleado::where('activo', true)->whereNull('nomina_sueldo_id')->count();

        return response()->json([
            'pendientes'    => $pendientes,
            'total_general' => array_sum(array_column($pendientes, 'total')),
            'sin_sueldo'    => $sinSueldo,
        ]);
    }

    /**
     * POST /api/nomina/pagos — cobrarle un ciclo a un trabajador.
     *
     * Se manda el trabajador y el día en que arranca el ciclo; el resto
     * (fecha fin, días, descuentos, total) lo calcula el servidor.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'empleado_id'   => 'required|exists:empleados,id',
            'fecha_inicio'  => 'required|date',
            'observaciones' => 'nullable|string|max:2000',
        ], [
            'empleado_id.required'  => 'Elige el trabajador.',
            'fecha_inicio.required' => 'Falta el ciclo que se está pagando.',
        ]);

        $empleado = $this->empleadoLiquidable($data['empleado_id']);

        $pago = DB::transaction(fn () => $this->registrar(
            $empleado,
            CicloNomina::fecha($data['fecha_inicio']),
            $data['observaciones'] ?? null
        ));

        return response()->json($this->comoJson($pago->load('empleado', 'ausencias', 'ajustes')), 201);
    }

    /**
     * POST /api/nomina/pagos/lote — el botón de "pagar todos".
     *
     * El front manda la lista exacta que tiene en pantalla, no un "paga
     * todo lo pendiente": así nunca se cobra algo que el usuario no llegó a
     * ver. Lo que ya se hubiera pagado desde otro lado se reporta como
     * omitido en vez de tumbar toda la operación.
     */
    public function lote(Request $request)
    {
        $data = $request->validate([
            'pagos'                => 'required|array|min:1',
            'pagos.*.empleado_id'  => 'required|exists:empleados,id',
            'pagos.*.fecha_inicio' => 'required|date',
        ], [
            'pagos.required' => 'No hay nada que pagar.',
        ]);

        $pagados  = [];
        $omitidos = [];

        foreach ($data['pagos'] as $fila) {
            try {
                $empleado = $this->empleadoLiquidable($fila['empleado_id']);
                $pago = DB::transaction(fn () => $this->registrar(
                    $empleado,
                    CicloNomina::fecha($fila['fecha_inicio']),
                    null
                ));
                $pagados[] = $this->comoJson($pago->load('empleado', 'ausencias', 'ajustes'));
            } catch (ValidationException $e) {
                $omitidos[] = [
                    'empleado_id' => $fila['empleado_id'],
                    'motivo'      => collect($e->errors())->flatten()->first(),
                ];
            }
        }

        return response()->json([
            'pagados'  => $pagados,
            'omitidos' => $omitidos,
            'total'    => array_sum(array_column($pagados, 'total')),
        ], 201);
    }

    /** GET /api/nomina/pagos?empleado_id=&limite= — el historial de lo pagado. */
    public function index(Request $request)
    {
        $q = NominaPago::with('empleado', 'ausencias', 'ajustes')
            ->orderByDesc('fecha_fin')
            ->orderByDesc('id');

        if ($empleadoId = $request->query('empleado_id')) {
            $q->where('empleado_id', $empleadoId);
        }

        $pagos = $q->limit((int) $request->query('limite', 100))->get();

        return response()->json($pagos->map(fn (NominaPago $p) => $this->comoJson($p)));
    }

    /**
     * DELETE /api/nomina/pagos/{id} — deshacer un pago hecho por error.
     *
     * Las faltas y ajustes que se habían enganchado vuelven a quedar
     * sueltos, así que el ciclo reaparece en pendientes tal como estaba.
     */
    public function destroy(int $id)
    {
        $pago = NominaPago::findOrFail($id);

        DB::transaction(function () use ($pago) {
            NominaAusencia::where('nomina_pago_id', $pago->id)->update(['nomina_pago_id' => null]);
            NominaAjuste::where('nomina_pago_id', $pago->id)->update(['nomina_pago_id' => null]);
            $pago->delete();
        });

        return response()->json(['ok' => true, 'message' => 'Pago deshecho: el ciclo vuelve a quedar pendiente.']);
    }

    /**
     * Congela el desglose del ciclo y engancha las faltas y ajustes que
     * caen adentro. Corre dentro de una transacción.
     */
    private function registrar(Empleado $empleado, Carbon $fechaInicio, ?string $observaciones): NominaPago
    {
        $hoy = $this->hoy();
        [$inicio, $fin] = CicloNomina::rango($empleado->periodicidad, $fechaInicio);

        // El inicio tiene que ser el de un ciclo real de esta frecuencia:
        // si no, una fecha cualquiera armaría un rango que no le
        // corresponde y se pagaría de más o de menos.
        if (! $inicio->isSameDay($fechaInicio)) {
            throw ValidationException::withMessages([
                'fecha_inicio' => ["Ese no es el inicio de un ciclo {$empleado->labelPeriodicidad()} de {$empleado->nombre}."],
            ]);
        }

        if ($fin->greaterThan($hoy)) {
            throw ValidationException::withMessages([
                'fecha_inicio' => ["El ciclo de {$empleado->nombre} todavía no termina: se puede cobrar el {$fin->day}."],
            ]);
        }

        if (NominaPago::where('empleado_id', $empleado->id)->whereDate('fecha_inicio', $inicio->toDateString())->exists()) {
            throw ValidationException::withMessages([
                'fecha_inicio' => ["Ese ciclo de {$empleado->nombre} ya estaba pagado."],
            ]);
        }

        $l = NominaLiquidador::liquidar($empleado, $inicio, $fin, $hoy);

        $pago = NominaPago::create([
            'empleado_id'      => $empleado->id,
            'periodicidad'     => $empleado->periodicidad,
            'fecha_inicio'     => $inicio,
            'fecha_fin'        => $fin,
            'sueldo_nombre'    => $l['sueldo_nombre'],
            'valor_dia'        => $l['valor_dia'],
            'valor_hora'       => $l['valor_hora'],
            'horas_dia'        => $l['horas_dia'],
            'dias'             => $l['dias'],
            'subtotal'         => $l['subtotal'],
            'descuento_faltas' => $l['descuento_faltas'],
            'total_ajustes'    => $l['total_ajustes'],
            'total'            => $l['total'],
            'observaciones'    => $observaciones,
            'pagado_at'        => now(),
        ]);

        // Mismo rango que usó la liquidación (el ciclo está cerrado, así
        // que su `hasta` es `fin`): lo que se descontó es exactamente lo
        // que queda enganchado.
        $rango = [$inicio->toDateString(), $fin->toDateString()];

        NominaAusencia::where('empleado_id', $empleado->id)
            ->whereNull('nomina_pago_id')
            ->whereBetween('fecha', $rango)
            ->update(['nomina_pago_id' => $pago->id]);

        NominaAjuste::where('empleado_id', $empleado->id)
            ->whereNull('nomina_pago_id')
            ->whereBetween('fecha', $rango)
            ->update(['nomina_pago_id' => $pago->id]);

        return $pago;
    }

    /** El trabajador con todo lo que la liquidación necesita cargado. */
    private function empleadoLiquidable(int|string $id): Empleado
    {
        $empleado = Empleado::with([
            'sueldo',
            'ausencias' => fn ($q) => $q->whereNull('nomina_pago_id')->orderBy('fecha'),
            'ajustes'   => fn ($q) => $q->whereNull('nomina_pago_id')->orderBy('fecha'),
        ])->findOrFail($id);

        if (! $empleado->nomina_sueldo_id) {
            throw ValidationException::withMessages([
                'empleado_id' => ["{$empleado->nombre} todavía no tiene un sueldo asignado."],
            ]);
        }

        return $empleado;
    }

    private function comoJson(NominaPago $p): array
    {
        return [
            'id'                 => $p->id,
            'empleado_id'        => $p->empleado_id,
            'empleado_nombre'    => $p->empleado?->nombre,
            'empleado_cargo'     => $p->empleado?->cargo,
            'periodicidad'       => $p->periodicidad,
            'periodicidad_label' => $p->labelPeriodicidad(),
            'nombre'             => $p->nombreCiclo(),
            'fecha_inicio'       => $p->fecha_inicio->toDateString(),
            'fecha_fin'          => $p->fecha_fin->toDateString(),
            'sueldo_nombre'      => $p->sueldo_nombre,
            'valor_dia'          => (float) $p->valor_dia,
            'valor_hora'         => (float) $p->valor_hora,
            'dias'               => (float) $p->dias,
            'subtotal'           => (float) $p->subtotal,
            'descuento_faltas'   => (float) $p->descuento_faltas,
            'total_ajustes'      => (float) $p->total_ajustes,
            'total'              => (float) $p->total,
            'observaciones'      => $p->observaciones,
            'pagado_at'          => $p->pagado_at?->toIso8601String(),
            'faltas'             => $p->ausencias->map(fn (NominaAusencia $a) => [
                'id'     => $a->id,
                'fecha'  => $a->fecha->toDateString(),
                'horas'  => (float) $a->horas,
                'motivo' => $a->motivo,
                'monto'  => round((float) $a->horas * (float) $p->valor_hora),
            ])->values(),
            'ajustes' => $p->ajustes->map(fn (NominaAjuste $a) => [
                'id'     => $a->id,
                'fecha'  => $a->fecha->toDateString(),
                'nombre' => $a->nombre,
                'monto'  => (float) $a->monto,
            ])->values(),
        ];
    }
}
