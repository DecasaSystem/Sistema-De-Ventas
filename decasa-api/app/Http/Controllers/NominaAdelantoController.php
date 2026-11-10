<?php

namespace App\Http\Controllers;

use App\Models\NominaAdelanto;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Adelantos de sueldo: "le pago la quincena (o una parte) antes del 15".
 *
 * Se entrega hoy y el próximo pago de nómina lo descuenta solo (ver
 * NominaPagoController::descontarAdelantos). No se anota como ajuste negativo:
 * eso bajaba lo que la nómina costó y Finanzas veía la quincena más barata.
 */
class NominaAdelantoController extends Controller
{
    /**
     * GET /api/nomina/adelantos?usuario_id=
     * Cuánto lleva ganado, cuánto sería el ciclo completo, cuánto ya se le
     * adelantó y cuánto se le puede adelantar todavía.
     */
    public function index(Request $request)
    {
        $request->validate(['usuario_id' => 'required|integer']);
        $empleado = $this->trabajador((int) $request->query('usuario_id'));

        return response()->json($this->resumen($empleado));
    }

    /** POST /api/nomina/adelantos */
    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => 'required|integer',
            'monto'      => 'required|numeric|min:1',
            'nota'       => 'nullable|string|max:160',
        ]);

        $resumen = DB::transaction(function () use ($data, $request) {
            // Bloquea a la persona: dos clics seguidos no adelantan dos veces
            // sobre el mismo "disponible".
            Usuario::whereKey($data['usuario_id'])->lockForUpdate()->first();
            $empleado = $this->trabajador((int) $data['usuario_id']);
            $antes = $this->resumen($empleado);
            $monto = round((float) $data['monto'], 2);

            if ($monto > $antes['disponible']) {
                abort(response()->json([
                    'message' => 'Solo se le puede adelantar hasta $' . number_format($antes['disponible'], 0, ',', '.')
                               . ': es lo que va a ganar en lo que falta por pagarle.',
                ], 422));
            }

            NominaAdelanto::create([
                'usuario_id'     => $empleado->id,
                'fecha'          => CicloNomina::hoy()->toDateString(),
                'monto'          => $monto,
                'nota'           => $data['nota'] ?? null,
                'registrado_por' => $request->user()->id,
            ]);

            return $this->resumen($this->trabajador($empleado->id));
        });

        return response()->json($resumen, 201);
    }

    /**
     * DELETE /api/nomina/adelantos/{id} — se anotó por error.
     * Solo si ningún pago lo ha descontado: si ya se descontó, se deshace ese pago.
     */
    public function destroy(int $id)
    {
        $a = NominaAdelanto::findOrFail($id);

        if ($a->estaDescontado() || $a->resto_de_id) {
            return response()->json([
                'message' => 'Ese adelanto ya se descontó en un pago de nómina: para corregirlo, deshaz ese pago.',
            ], 422);
        }

        $a->delete();

        return response()->json($this->resumen($this->trabajador($a->usuario_id)));
    }

    private function trabajador(int $id): Usuario
    {
        $empleado = Usuario::with(NominaLiquidador::relaciones())->findOrFail($id);

        if (! $empleado->nomina_sueldo_id) {
            abort(response()->json(['message' => "{$empleado->nombre} no tiene sueldo asignado en nómina."], 422));
        }

        return $empleado;
    }

    /**
     * Lo que se le debe de aquí al final del ciclo en curso: los ciclos ya
     * cerrados sin pagar más el ciclo en curso completo (como si trabajara
     * todos los días que faltan, menos las faltas que ya avisó), menos lo que
     * ya se le adelantó. No se le adelanta más que eso.
     */
    private function resumen(Usuario $empleado): array
    {
        $hoy = CicloNomina::hoy();
        $adelantado = NominaLiquidador::adelantosPendientes($empleado);

        // Se muestra como una suma simple: sin pagar + ciclo en curso −
        // adelantado = lo que se le puede adelantar. Un ciclo en negativo no
        // cuenta como plata a favor (el pago no descuenta de él).
        $pendientes = NominaLiquidador::pendientes($hoy, collect([$empleado]));
        $sinPagar = (float) array_sum(array_map(fn ($p) => max(0.0, (float) $p['total']), $pendientes));

        [$inicio, $fin] = CicloNomina::rango($empleado->periodicidad, $hoy);
        $yaEnPendientes = collect($pendientes)->contains('fecha_inicio', $inicio->toDateString());
        $ganadoHoy = NominaLiquidador::liquidar($empleado, $inicio, $fin, $hoy);
        $completo  = NominaLiquidador::liquidar($empleado, $inicio, $fin, $fin);

        $actual = $yaEnPendientes ? 0.0 : max(0.0, (float) $completo['total']);
        $disponible = max(0.0, round($sinPagar + $actual - $adelantado, 2));

        return [
            'usuario_id'      => $empleado->id,
            'nombre'          => $empleado->nombre,
            'ciclo'           => [
                'nombre'       => $completo['nombre'],
                'fecha_inicio' => $inicio->toDateString(),
                'fecha_fin'    => $fin->toDateString(),
                'ganado_hoy'   => round((float) $ganadoHoy['total'], 2),
                'completo'     => round((float) $completo['total'], 2),
                'dias_hoy'     => $ganadoHoy['dias'],
                'dias_ciclo'   => $completo['dias'],
            ],
            // Ciclos ya cerrados que todavía no se le han pagado (completos).
            'sin_pagar'       => round($sinPagar, 2),
            // Si el ciclo en curso ya cerró hoy, va en `sin_pagar`, no dos veces.
            'ciclo_en_curso'  => round($actual, 2),
            'adelantado'      => $adelantado,
            'disponible'      => $disponible,
            'adelantos'       => NominaAdelanto::where('usuario_id', $empleado->id)
                ->with('pago:id,fecha_inicio,fecha_fin,periodicidad')
                ->orderByDesc('fecha')->orderByDesc('id')->limit(20)->get()
                ->map(fn (NominaAdelanto $a) => [
                    'id'         => $a->id,
                    'fecha'      => $a->fecha->toDateString(),
                    'monto'      => (float) $a->monto,
                    'nota'       => $a->nota,
                    'descontado' => $a->estaDescontado(),
                    'es_resto'   => (bool) $a->resto_de_id,
                    'en_pago'    => $a->pago?->nombreCiclo(),
                ])->values(),
        ];
    }
}
