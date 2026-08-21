<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\NominaAusencia;
use App\Models\NominaPago;
use App\Services\CicloNomina;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

/**
 * Una falta con fecha real (y horas, para faltas parciales).
 *
 * Se guarda contra el trabajador y la fecha, y cae sola en el ciclo que
 * contenga esa fecha. Eso incluye ciclos futuros: si alguien avisa hoy que
 * va a faltar la quincena que viene, la falta espera y se descuenta cuando
 * esa quincena se cobre — sin que nadie tenga que acordarse.
 *
 * Lo único que se bloquea es anotar una falta en una fecha que ya se pagó:
 * ahí la plata ya salió y el descuento no tendría dónde aplicarse.
 */
class NominaAusenciaController extends Controller
{
    private function comoJson(NominaAusencia $a, ?float $valorHora = null): array
    {
        $pago  = $a->relationLoaded('pago') ? $a->pago : null;
        $valor = $valorHora ?? ($pago ? (float) $pago->valor_hora : $a->trabajador?->valorHoraEfectivo() ?? 0.0);

        return [
            'id'            => $a->id,
            'usuario_id'   => $a->usuario_id,
            'fecha'         => $a->fecha->toDateString(),
            'horas'         => (float) $a->horas,
            'motivo'        => $a->motivo,
            'pagada'        => $a->estaPagada(),
            'registrada_en' => $a->created_at->toIso8601String(),
            'pago_id'       => $a->nomina_pago_id,
            'ciclo'         => $pago?->nombreCiclo() ?? $this->cicloDe($a),
            'monto'         => round((float) $a->horas * $valor),
        ];
    }

    /** En qué ciclo va a caer una falta que todavía no se ha cobrado. */
    private function cicloDe(NominaAusencia $a): ?string
    {
        $empleado = $a->relationLoaded('trabajador') ? $a->trabajador : null;
        if (! $empleado) {
            return null;
        }

        [$inicio, $fin] = CicloNomina::rango($empleado->periodicidad, $a->fecha);

        return CicloNomina::nombre($empleado->periodicidad, $inicio, $fin);
    }

    /** GET /api/nomina/ausencias?usuario_id=&pendientes=1 */
    public function index(Request $request)
    {
        $q = NominaAusencia::query()->with('pago', 'trabajador.sueldo')->orderByDesc('fecha');

        if ($empleadoId = $request->query('usuario_id')) {
            $q->where('usuario_id', $empleadoId);
        }
        if ($request->boolean('pendientes')) {
            $q->whereNull('nomina_pago_id');
        }

        return response()->json($q->get()->map(fn (NominaAusencia $a) => $this->comoJson($a)));
    }

    /**
     * POST /api/nomina/ausencias
     *
     * Un rango de fechas (o una sola, dejando fecha_fin vacío) se expande
     * en una fila por día. Si el rango cruza varios ciclos, cada fecha se
     * descuenta en el suyo.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'  => 'required|exists:usuarios,id',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
            'horas'        => 'nullable|numeric|min:0.25|max:24',
            'motivo'       => 'nullable|string|max:160',
        ], [
            'usuario_id.required'  => 'Elige el trabajador.',
            'fecha_inicio.required' => 'La fecha es obligatoria.',
        ]);

        $empleado = Usuario::with('sueldo')->findOrFail($data['usuario_id']);
        $horas    = $data['horas'] ?? $empleado->horasDiaEfectivo();

        $inicio = CicloNomina::fecha($data['fecha_inicio']);
        $fin    = isset($data['fecha_fin']) ? CicloNomina::fecha($data['fecha_fin']) : $inicio->copy();

        // Un rango largo por error (un año) no debería crear 365 filas.
        if ($inicio->diffInDays($fin) > 92) {
            return response()->json(['message' => 'El rango es de más de tres meses. Revisa las fechas.'], 422);
        }

        // Los ciclos ya cobrados de esta persona, para saber qué fechas
        // están cerradas — una sola consulta en vez de una por día.
        $pagados = NominaPago::where('usuario_id', $empleado->id)
            ->where('fecha_fin', '>=', $inicio->toDateString())
            ->where('fecha_inicio', '<=', $fin->toDateString())
            ->get();

        $valorHora   = $empleado->valorHoraEfectivo();
        $guardadas   = [];
        $noAplicadas = [];

        foreach (CarbonPeriod::create($inicio, $fin) as $fecha) {
            $yaPagada = $pagados->first(
                fn (NominaPago $p) => $fecha->greaterThanOrEqualTo(CicloNomina::fecha($p->fecha_inicio))
                    && $fecha->lessThanOrEqualTo(CicloNomina::fecha($p->fecha_fin))
            );

            if ($yaPagada) {
                $noAplicadas[] = $fecha->toDateString();
                continue;
            }

            $ausencia = NominaAusencia::updateOrCreate(
                ['usuario_id' => $empleado->id, 'fecha' => $fecha->toDateString()],
                ['horas' => $horas, 'motivo' => $data['motivo'] ?? null, 'nomina_pago_id' => null]
            );
            $ausencia->setRelation('trabajador', $empleado);
            $ausencia->setRelation('pago', null);

            $guardadas[] = $this->comoJson($ausencia, $valorHora);
        }

        return response()->json([
            'guardadas'    => $guardadas,
            'no_aplicadas' => $noAplicadas,
        ], 201);
    }

    /** DELETE /api/nomina/ausencias/{id} */
    public function destroy(int $id)
    {
        $ausencia = NominaAusencia::with('pago')->findOrFail($id);

        if ($ausencia->estaPagada()) {
            return response()->json([
                'message' => 'Esa falta ya se descontó en un pago (' . $ausencia->pago->nombreCiclo() . ') y no se puede quitar.',
            ], 422);
        }

        $ausencia->delete();

        return response()->json(['ok' => true]);
    }
}
