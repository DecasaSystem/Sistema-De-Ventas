<?php

namespace App\Http\Controllers;

use App\Models\NominaLiquidacion;
use App\Models\NominaPrestacionPago;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\LiquidacionContrato;
use App\Services\NominaLiquidador;
use App\Services\Prestaciones;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Prestaciones sociales (prima, cesantías, intereses, vacaciones) y
 * liquidaciones de quien se retira. Acceso ya validado por
 * `permiso:acceso_nomina` en las rutas.
 *
 * Como en los pagos de nómina, los montos SIEMPRE los calcula el servidor:
 * el front manda a quién y de qué periodo, nunca cuánto (salvo las
 * vacaciones, donde se puede ajustar, y lo que se acuerde en una liquidación).
 */
class NominaPrestacionController extends Controller
{
    /** GET /api/nomina/prestaciones?tipo=prima&fecha=2026-12-01 — el periodo que contiene la fecha. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'tipo'  => ['required', Rule::in(['prima', 'cesantias', 'intereses_cesantias'])],
            'fecha' => 'nullable|date',
        ]);
        [$desde, $hasta] = Prestaciones::periodo($data['tipo'], CicloNomina::fecha($data['fecha'] ?? CicloNomina::hoy()));

        return response()->json(Prestaciones::resumen($data['tipo'], $desde, $hasta));
    }

    /** GET /api/nomina/prestaciones/vacaciones */
    public function vacaciones()
    {
        return response()->json(Prestaciones::vacaciones());
    }

    /** GET /api/nomina/prestaciones/historial?usuario_id=&tipo= */
    public function historial(Request $request)
    {
        $q = NominaPrestacionPago::with('trabajador:id,nombre')->orderByDesc('fecha_pago')->orderByDesc('id')
            ->when($request->query('usuario_id'), fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('tipo', $t))
            ->limit(200);

        return response()->json($q->get()->map(fn ($p) => [
            'id' => $p->id, 'usuario_id' => $p->usuario_id, 'nombre' => $p->trabajador?->nombre,
            'tipo' => $p->tipo, 'tipo_nombre' => Prestaciones::NOMBRES[$p->tipo] ?? $p->tipo,
            'desde' => $p->periodo_desde->toDateString(), 'hasta' => $p->periodo_hasta->toDateString(),
            'dias' => $p->dias !== null ? (float) $p->dias : null, 'monto' => (float) $p->monto,
            'forma' => $p->forma, 'destino' => $p->destino, 'fecha_pago' => $p->fecha_pago->toDateString(),
            'estado' => $p->estado, 'liquidacion_id' => $p->liquidacion_id, 'notas' => $p->notas,
        ]));
    }

    /**
     * POST /api/nomina/prestaciones/pagar
     *
     * Prima, cesantías o intereses de un periodo, a varios de una: se paga el
     * saldo que calcula el servidor. Las cesantías normalmente se CONSIGNAN al
     * fondo (`forma = consignacion`, `destino` = el fondo de cada uno).
     */
    public function pagar(Request $request)
    {
        $data = $request->validate([
            'tipo'        => ['required', Rule::in(['prima', 'cesantias', 'intereses_cesantias'])],
            'fecha'       => 'required|date',
            'usuarios'    => 'required|array|min:1',
            'usuarios.*'  => 'integer|exists:usuarios,id',
            'forma'       => ['nullable', Rule::in(['pago', 'consignacion'])],
            'fecha_pago'  => 'nullable|date',
            'notas'       => 'nullable|string|max:500',
        ]);

        [$desde, $hasta] = Prestaciones::periodo($data['tipo'], CicloNomina::fecha($data['fecha']));
        $resumen = collect(Prestaciones::resumen($data['tipo'], $desde, $hasta)['trabajadores'])->keyBy('usuario_id');

        $creados = DB::transaction(function () use ($data, $desde, $hasta, $resumen, $request) {
            $creados = [];
            foreach ($data['usuarios'] as $id) {
                $saldo = $resumen[$id]['saldo'] ?? 0;
                if ($saldo <= 0) continue;
                $creados[] = NominaPrestacionPago::create([
                    'usuario_id'     => $id,
                    'tipo'           => $data['tipo'],
                    'periodo_desde'  => $desde->toDateString(),
                    'periodo_hasta'  => $hasta->toDateString(),
                    'monto'          => $saldo,
                    'forma'          => $data['forma'] ?? ($data['tipo'] === 'cesantias' ? 'consignacion' : 'pago'),
                    'destino'        => $data['tipo'] === 'cesantias' ? ($resumen[$id]['fondo'] ?? null) : null,
                    'fecha_pago'     => $data['fecha_pago'] ?? CicloNomina::hoy()->toDateString(),
                    'notas'          => $data['notas'] ?? null,
                    'registrado_por' => $request->user()->id,
                ]);
            }

            return $creados;
        });

        if (! $creados) {
            throw ValidationException::withMessages(['usuarios' => ['No hay saldo por pagar para quienes elegiste.']]);
        }

        return response()->json([
            'pagados' => count($creados),
            'total'   => array_sum(array_map(fn ($p) => (float) $p->monto, $creados)),
        ], 201);
    }

    /**
     * POST /api/nomina/prestaciones/vacaciones — registrar unas vacaciones.
     *
     * `con_nomina`: la persona descansa y el sueldo de esos días ya va en su
     * nómina normal (no sale plata aparte; Finanzas no lo cuenta dos veces).
     * `pago`: se le pagan aparte (por adelantado o en dinero).
     */
    public function registrarVacaciones(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => 'required|exists:usuarios,id',
            'dias'       => 'required|numeric|min:0.5|max:90',
            'desde'      => 'required|date',
            'hasta'      => 'required|date|after_or_equal:desde',
            'forma'      => ['required', Rule::in(['pago', 'con_nomina'])],
            'monto'      => 'nullable|numeric|min:0',
            'fecha_pago' => 'nullable|date',
            'notas'      => 'nullable|string|max:500',
        ]);

        $u = Usuario::with('sueldo')->findOrFail($data['usuario_id']);
        $pendientes = collect(Prestaciones::vacaciones($u->id))->firstWhere('usuario_id', $u->id)['dias_pendientes'] ?? 0;
        if ($data['dias'] > $pendientes + 0.01) {
            throw ValidationException::withMessages(['dias' => ["{$u->nombre} tiene {$pendientes} días pendientes."]]);
        }

        $p = NominaPrestacionPago::create([
            'usuario_id'     => $u->id,
            'tipo'           => 'vacaciones',
            'periodo_desde'  => $data['desde'],
            'periodo_hasta'  => $data['hasta'],
            'dias'           => $data['dias'],
            'monto'          => $data['monto'] ?? round($data['dias'] * $u->valorDiaEfectivo()),
            'forma'          => $data['forma'],
            'fecha_pago'     => $data['fecha_pago'] ?? $data['desde'],
            'notas'          => $data['notas'] ?? null,
            'registrado_por' => $request->user()->id,
        ]);

        return response()->json(['id' => $p->id, 'monto' => (float) $p->monto], 201);
    }

    /** POST /api/nomina/prestaciones/{id}/anular — un pago hecho por error (no se borra). */
    public function anular(Request $request, int $id)
    {
        $data = $request->validate(['motivo' => 'required|string|max:200']);
        $p = NominaPrestacionPago::findOrFail($id);
        if ($p->liquidacion_id) {
            throw ValidationException::withMessages(['id' => ['Es parte de una liquidación: anula la liquidación.']]);
        }
        $p->update(['estado' => 'anulado', 'motivo_anulacion' => $data['motivo']]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/nomina/prestaciones/ajustes */
    public function ajustes()
    {
        return response()->json(Prestaciones::config());
    }

    /** PUT /api/nomina/prestaciones/ajustes — fechas límite, días de vacaciones, indemnización, mínimo. */
    public function guardarAjustes(Request $request)
    {
        $md = ['sometimes', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'];
        $data = $request->validate([
            'prima_limite_1'                    => $md,
            'prima_limite_2'                    => $md,
            'intereses_limite'                  => $md,
            'cesantias_limite'                  => $md,
            'dias_vacaciones_anio'              => 'sometimes|numeric|min:0|max:60',
            'smmlv'                             => 'sometimes|numeric|min:0',
            'indemnizacion_tope_smmlv'          => 'sometimes|numeric|min:0',
            'indemnizacion_primer_anio'         => 'sometimes|numeric|min:0',
            'indemnizacion_anio_adicional'      => 'sometimes|numeric|min:0',
            'indemnizacion_primer_anio_alto'    => 'sometimes|numeric|min:0',
            'indemnizacion_anio_adicional_alto' => 'sometimes|numeric|min:0',
        ], ['regex' => 'Las fechas van como MM-DD (ej. 06-30).']);

        return response()->json(Prestaciones::guardar($data));
    }

    // ── Liquidaciones ────────────────────────────────────────────────────────

    /** GET /api/nomina/liquidaciones */
    public function liquidaciones()
    {
        return response()->json(NominaLiquidacion::with('trabajador:id,nombre')->orderByDesc('fecha_retiro')->limit(100)->get()
            ->map(fn ($l) => [
                'id' => $l->id, 'usuario_id' => $l->usuario_id, 'nombre' => $l->trabajador?->nombre,
                'fecha_ingreso' => $l->fecha_ingreso->toDateString(), 'fecha_retiro' => $l->fecha_retiro->toDateString(),
                'motivo' => NominaLiquidacion::MOTIVOS[$l->motivo] ?? $l->motivo, 'total' => (float) $l->total,
                'estado' => $l->estado, 'fecha_pago' => $l->fecha_pago->toDateString(),
            ]));
    }

    /** POST /api/nomina/liquidaciones/calcular — ver la liquidación antes de registrarla. */
    public function calcular(Request $request)
    {
        [$u, $data] = $this->validarLiquidacion($request);

        return response()->json(LiquidacionContrato::calcular($u, CicloNomina::fecha($data['fecha_retiro']), $data['motivo'], $data['tipo_contrato'] ?? null, $data));
    }

    /** POST /api/nomina/liquidaciones — registrarla (se recalcula aquí: el front no manda montos). */
    public function store(Request $request)
    {
        [$u, $data] = $this->validarLiquidacion($request);
        $calc = LiquidacionContrato::calcular($u, CicloNomina::fecha($data['fecha_retiro']), $data['motivo'], $data['tipo_contrato'] ?? null, $data);
        $liq = LiquidacionContrato::registrar($u, $calc, $data, $request->user()->id);

        return response()->json(['id' => $liq->id, 'total' => (float) $liq->total] + $calc, 201);
    }

    /** POST /api/nomina/liquidaciones/{id}/anular */
    public function anularLiquidacion(Request $request, int $id)
    {
        $data = $request->validate(['motivo' => 'required|string|max:200']);
        LiquidacionContrato::anular(NominaLiquidacion::findOrFail($id), $data['motivo']);

        return response()->json(['ok' => true]);
    }

    /** GET /api/nomina/liquidaciones/{id}/pdf — para firmar. */
    public function pdf(int $id)
    {
        $l = NominaLiquidacion::with('trabajador')->findOrFail($id);

        return Pdf::loadView('pdf.liquidacion', ['l' => $l, 'd' => $l->detalle])
            ->setPaper('letter')
            ->download("liquidacion_{$l->id}.pdf");
    }

    private function validarLiquidacion(Request $request): array
    {
        $data = $request->validate([
            'usuario_id'           => 'required|exists:usuarios,id',
            'fecha_retiro'         => 'required|date',
            'motivo'               => ['required', Rule::in(array_keys(NominaLiquidacion::MOTIVOS))],
            'tipo_contrato'        => ['nullable', Rule::in(array_keys(NominaLiquidacion::CONTRATOS))],
            'indemnizacion_manual' => 'nullable|numeric|min:0',
            'descontar_prestamos'  => 'sometimes|boolean',
            'otros'                => 'nullable|array|max:10',
            'otros.*.nombre'       => 'required|string|max:80',
            'otros.*.monto'        => 'required|numeric',
            'fecha_pago'           => 'nullable|date',
            'notas'                => 'nullable|string|max:2000',
        ], ['fecha_retiro.required' => '¿Qué día se retira?', 'motivo.required' => '¿Por qué se retira?']);

        return [Usuario::with(NominaLiquidador::relaciones())->findOrFail($data['usuario_id']), $data];
    }
}
