<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\NominaAjuste;
use App\Models\NominaAusencia;
use App\Models\NominaPago;
use App\Models\NominaPrestamo;
use App\Models\NominaPrestamoCuota;
use App\Models\NominaProduccion;
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

        // Solo se avisa por los de fábrica: ellos cobran por nómina sí o sí,
        // así que sin sueldo asignado es un pendiente real. Un vendedor sin
        // sueldo normalmente vive de comisión, y contarlo llenaría el aviso
        // de ruido que nadie tiene que atender.
        $sinSueldo = Usuario::where('activo', true)
            ->where('no_usa_programa', true)
            ->whereNull('nomina_sueldo_id')
            ->count();

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
            'usuario_id'   => 'required|exists:usuarios,id',
            'fecha_inicio'  => 'required|date',
            'observaciones' => 'nullable|string|max:2000',
        ], [
            'usuario_id.required'  => 'Elige el trabajador.',
            'fecha_inicio.required' => 'Falta el ciclo que se está pagando.',
        ]);

        $empleado = $this->trabajadorLiquidable($data['usuario_id']);

        $pago = DB::transaction(fn () => $this->registrar(
            $empleado,
            CicloNomina::fecha($data['fecha_inicio']),
            $data['observaciones'] ?? null
        ));

        return response()->json($this->comoJson($pago->load('trabajador', 'ausencias', 'ajustes', 'producciones')), 201);
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
            'pagos.*.usuario_id'  => 'required|exists:usuarios,id',
            'pagos.*.fecha_inicio' => 'required|date',
        ], [
            'pagos.required' => 'No hay nada que pagar.',
        ]);

        $pagados  = [];
        $omitidos = [];

        foreach ($data['pagos'] as $fila) {
            try {
                $empleado = $this->trabajadorLiquidable($fila['usuario_id']);
                $pago = DB::transaction(fn () => $this->registrar(
                    $empleado,
                    CicloNomina::fecha($fila['fecha_inicio']),
                    null
                ));
                $pagados[] = $this->comoJson($pago->load('trabajador', 'ausencias', 'ajustes', 'producciones'));
            } catch (ValidationException $e) {
                $omitidos[] = [
                    'usuario_id' => $fila['usuario_id'],
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

    /** GET /api/nomina/pagos?usuario_id=&limite= — el historial de lo pagado. */
    public function index(Request $request)
    {
        $q = NominaPago::with('trabajador', 'ausencias', 'ajustes', 'producciones')
            ->orderByDesc('fecha_fin')
            ->orderByDesc('id');

        if ($empleadoId = $request->query('usuario_id')) {
            $q->where('usuario_id', $empleadoId);
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
            NominaProduccion::where('nomina_pago_id', $pago->id)->update(['nomina_pago_id' => null]);
            // Se borran, no se desligan: una cuota sin pago no significa nada,
            // y dejarla suelta descontaria dos veces al volver a pagar.
            NominaPrestamoCuota::where('nomina_pago_id', $pago->id)->delete();
            $pago->delete();
        });

        return response()->json(['ok' => true, 'message' => 'Pago deshecho: el ciclo vuelve a quedar pendiente.']);
    }

    /**
     * Congela el desglose del ciclo y engancha las faltas y ajustes que
     * caen adentro. Corre dentro de una transacción.
     */
    private function registrar(Usuario $empleado, Carbon $fechaInicio, ?string $observaciones): NominaPago
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

        if (NominaPago::where('usuario_id', $empleado->id)->whereDate('fecha_inicio', $inicio->toDateString())->exists()) {
            throw ValidationException::withMessages([
                'fecha_inicio' => ["Ese ciclo de {$empleado->nombre} ya estaba pagado."],
            ]);
        }

        $l = NominaLiquidador::liquidar($empleado, $inicio, $fin, $hoy);

        $pago = NominaPago::create([
            'usuario_id'      => $empleado->id,
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
            'produccion_total' => $l['produccion_total'],
            'bonificacion'     => $l['bonificacion'],
            // Se guarda de dónde salió el bono ("Bonos del mínimo · de
            // $2.800.000 a $2.900.000"), porque el esquema puede cambiar
            // después y el pago tiene que poder explicarse solo.
            'bonificacion_nombre' => $l['bono']['monto'] > 0
                ? $l['bono']['bonificacion_nombre'] . ' · ' . $l['bono']['meta']
                : null,
            // Sobre qué se midió y en qué rango: un bono mensual cobrado con
            // la segunda quincena tiene que poder explicarse solo.
            'bonificacion_detalle' => $this->detalleBono($l['bono']),
            'total'            => $l['total'],
            'observaciones'    => $observaciones,
            'pagado_at'        => now(),
        ]);

        // Mismo rango que usó la liquidación (el ciclo está cerrado, así
        // que su `hasta` es `fin`): lo que se descontó es exactamente lo
        // que queda enganchado.
        $rango = [$inicio->toDateString(), $fin->toDateString()];

        NominaAusencia::where('usuario_id', $empleado->id)
            ->whereNull('nomina_pago_id')
            ->whereBetween('fecha', $rango)
            ->update(['nomina_pago_id' => $pago->id]);

        NominaAjuste::where('usuario_id', $empleado->id)
            ->whereNull('nomina_pago_id')
            ->whereBetween('fecha', $rango)
            ->update(['nomina_pago_id' => $pago->id]);

        // La cuota del préstamo se anota como una fila propia, atada a ESTE
        // pago: así el saldo sale de sumarlas, y deshacer el pago devuelve la
        // deuda sola en vez de dejarla mal para siempre.
        foreach (NominaPrestamo::with('cuotasPagadas')
                     ->where('usuario_id', $empleado->id)->where('activo', true)->get() as $prestamo) {
            $cuota = $prestamo->cuotaDelProximoPago();
            if ($cuota <= 0) continue;

            NominaPrestamoCuota::create([
                'prestamo_id'    => $prestamo->id,
                'nomina_pago_id' => $pago->id,
                'monto'          => $cuota,
                'fecha'          => $pago->fecha ?? now()->toDateString(),
            ]);
        }

        NominaProduccion::where('usuario_id', $empleado->id)
            ->whereNull('nomina_pago_id')
            ->whereBetween('fecha', $rango)
            ->update(['nomina_pago_id' => $pago->id]);

        return $pago;
    }

    /**
     * En texto, de dónde salió el bono: "Mensual · Agosto de 2026 · produjo
     * $3.450.000". Con varias ventanas (mensual que cobra dos quincenas) se
     * listan todas.
     */
    private function detalleBono(array $bono): ?string
    {
        if (! $bono['aplica'] || empty($bono['ventanas'])) {
            return null;
        }

        $partes = array_map(
            fn (array $v) => $v['nombre'] . ': produjo $' . number_format($v['produccion'], 0, ',', '.') .
                ($v['monto'] > 0 ? ' → $' . number_format($v['monto'], 0, ',', '.') : ' → sin bono'),
            $bono['ventanas']
        );

        return mb_substr($bono['periodo_label'] . ' · ' . implode(' · ', $partes), 0, 255);
    }

    /** El trabajador con todo lo que la liquidación necesita cargado. */
    private function trabajadorLiquidable(int|string $id): Usuario
    {
        $empleado = Usuario::with(NominaLiquidador::relaciones())->findOrFail($id);

        if (! $empleado->nomina_sueldo_id) {
            throw ValidationException::withMessages([
                'usuario_id' => ["{$empleado->nombre} todavía no tiene un sueldo asignado."],
            ]);
        }

        return $empleado;
    }

    private function comoJson(NominaPago $p): array
    {
        return [
            'id'                 => $p->id,
            'usuario_id'        => $p->usuario_id,
            'empleado_nombre'    => $p->trabajador?->nombre,
            'empleado_cargo'     => $p->trabajador?->rolAsignado?->nombre,
            'periodicidad'       => $p->periodicidad,
            'periodicidad_label' => $p->labelPeriodicidad(),
            'nombre'             => $p->nombreCiclo(),
            'fecha_inicio'       => $p->fecha_inicio->toDateString(),
            'fecha_fin'          => $p->fecha_fin->toDateString(),
            'sueldo_nombre'      => $p->sueldo_nombre,
            'valor_dia'          => (float) $p->valor_dia,
            'valor_hora'         => (float) $p->valor_hora,
            'dias'               => (float) $p->dias,
            'subtotal'            => (float) $p->subtotal,
            'descuento_faltas'    => (float) $p->descuento_faltas,
            'total_ajustes'       => (float) $p->total_ajustes,
            'produccion_total'    => (float) $p->produccion_total,
            'bonificacion'         => (float) $p->bonificacion,
            'bonificacion_nombre'  => $p->bonificacion_nombre,
            'bonificacion_detalle' => $p->bonificacion_detalle,
            'total'               => (float) $p->total,
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
            'producciones' => $p->producciones->map(fn (NominaProduccion $pr) => [
                'id'             => $pr->id,
                'fecha'          => $pr->fecha->toDateString(),
                'concepto'       => $pr->concepto,
                'valor_unitario' => (float) $pr->valor_unitario,
                'cantidad'       => (float) $pr->cantidad,
                'total'          => (float) $pr->total,
            ])->values(),
        ];
    }
}
