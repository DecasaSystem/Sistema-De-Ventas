<?php

namespace App\Http\Controllers;

use App\Models\NominaHoraExtra;
use App\Models\NominaPago;
use App\Models\NominaTipoHoraExtra;
use App\Models\NominaTipoHoraExtraTarifa;
use App\Models\Usuario;
use App\Services\CicloNomina;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Horas extra y recargos (Nómina → Novedades → Horas extra).
 *
 * Se anotan horas con su tipo; el valor lo calcula la liquidación del ciclo
 * (valor hora × factor del tipo ese día) y se congela al pagar. Igual que las
 * faltas: cae en el ciclo que contiene la fecha y no se puede anotar en una
 * fecha ya pagada.
 *
 * Los tipos y sus porcentajes por fecha se manejan aquí mismo (Sueldos →
 * Horas extra): un cambio de ley se carga "desde" y no mueve lo ya pagado.
 */
class NominaHoraExtraController extends Controller
{
    /** GET /api/nomina/horas-extra?usuario_id=&pendientes=1 */
    public function index(Request $request)
    {
        $q = NominaHoraExtra::with(['tipo.tarifas', 'pago', 'trabajador.sueldo'])
            ->orderByDesc('fecha')->orderByDesc('id');

        if ($id = $request->query('usuario_id')) {
            $q->where('usuario_id', $id);
        }
        if ($request->boolean('pendientes')) {
            $q->whereNull('nomina_pago_id');
        }

        return response()->json($q->limit(300)->get()->map(fn (NominaHoraExtra $h) => $this->comoJson($h)));
    }

    /**
     * POST /api/nomina/horas-extra
     *
     * Los topes de ley (2 al día, 12 a la semana) se avisan en `aviso` pero no
     * frenan: hay días de entrega en que se quedan más, y eso lo decide la
     * persona que lo anota.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => 'required|exists:usuarios,id',
            'tipo_id'    => ['required', Rule::exists('nomina_tipos_hora_extra', 'id')->where('activo', true)],
            'fecha'      => 'required|date',
            'horas'      => 'required|numeric|min:0.25|max:24',
            'nota'       => 'nullable|string|max:160',
        ], [
            'tipo_id.required' => 'Escoge el tipo de hora extra.',
            'tipo_id.exists'   => 'Ese tipo de hora extra no existe o está apagado.',
            'horas.required'   => '¿Cuántas horas?',
            'horas.min'        => 'Mínimo un cuarto de hora (0,25).',
        ]);

        $empleado = Usuario::with('sueldo')->findOrFail($data['usuario_id']);
        $fecha = CicloNomina::fecha($data['fecha']);

        if ($pago = $this->pagoQueCubre($empleado->id, $fecha)) {
            return response()->json([
                'message' => "Esa fecha ya se le pagó a {$empleado->nombre} ({$pago->nombreCiclo()}). " .
                             'Anótala en el ciclo actual o deshaz ese pago.',
            ], 422);
        }

        $hora = NominaHoraExtra::create([
            'usuario_id'     => $empleado->id,
            'tipo_id'        => $data['tipo_id'],
            'fecha'          => $fecha->toDateString(),
            'horas'          => $data['horas'],
            'nota'           => $data['nota'] ?? null,
            'registrado_por' => $request->user()->id,
        ]);
        $hora->load('tipo.tarifas');
        $hora->setRelation('trabajador', $empleado);
        $hora->setRelation('pago', null);

        return response()->json($this->comoJson($hora) + ['aviso' => $this->avisoDeTopes($empleado->id, $fecha)], 201);
    }

    /** DELETE /api/nomina/horas-extra/{id} — solo si no se ha pagado. */
    public function destroy(int $id)
    {
        $h = NominaHoraExtra::with('pago')->findOrFail($id);

        if ($h->estaPagada()) {
            return response()->json([
                'message' => 'Esas horas ya se pagaron (' . $h->pago->nombreCiclo() . ') y no se pueden quitar.',
            ], 422);
        }

        $h->delete();

        return response()->json(['ok' => true]);
    }

    // ── Tipos y porcentajes ──────────────────────────────────────────────────

    /** GET /api/nomina/horas-extra/tipos?incluir_inactivos=1 */
    public function tipos(Request $request)
    {
        $hoy = CicloNomina::hoy();

        return response()->json(NominaTipoHoraExtra::with('tarifas')
            ->when(! $request->boolean('incluir_inactivos'), fn ($q) => $q->where('activo', true))
            ->orderBy('orden')->orderBy('id')->get()
            ->map(fn (NominaTipoHoraExtra $t) => $this->tipoComoJson($t, $hoy)));
    }

    /** POST /api/nomina/horas-extra/tipos */
    public function crearTipo(Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:80',
            'calculo'    => ['required', Rule::in(NominaTipoHoraExtra::CALCULOS)],
            'porcentaje' => 'required|numeric|min:0|max:500',
            'desde'      => 'nullable|date',
        ]);

        $tipo = NominaTipoHoraExtra::create([
            'clave'   => 'tipo_' . substr(md5($data['nombre'] . microtime()), 0, 10),
            'nombre'  => $data['nombre'],
            'calculo' => $data['calculo'],
            'activo'  => true,
            'orden'   => (int) NominaTipoHoraExtra::max('orden') + 1,
        ]);
        // Sin fecha rige para todo lo que se anote (también lo de antes).
        NominaTipoHoraExtraTarifa::create([
            'tipo_id' => $tipo->id, 'porcentaje' => $data['porcentaje'], 'desde' => $data['desde'] ?? '2000-01-01',
        ]);

        return response()->json($this->tipoComoJson($tipo->load('tarifas'), CicloNomina::hoy()), 201);
    }

    /** PATCH /api/nomina/horas-extra/tipos/{id} — nombre o apagarlo. El % va por tarifas. */
    public function editarTipo(Request $request, int $id)
    {
        $tipo = NominaTipoHoraExtra::findOrFail($id);
        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:80',
            'activo' => 'sometimes|boolean',
        ]);
        $tipo->update($data);

        return response()->json($this->tipoComoJson($tipo->load('tarifas'), CicloNomina::hoy()));
    }

    /**
     * POST /api/nomina/horas-extra/tipos/{id}/tarifas — "desde tal fecha es X %".
     * Una tarifa con la misma fecha se reemplaza.
     */
    public function agregarTarifa(Request $request, int $id)
    {
        $tipo = NominaTipoHoraExtra::findOrFail($id);
        $data = $request->validate([
            'porcentaje' => 'required|numeric|min:0|max:500',
            'desde'      => 'required|date',
        ]);

        NominaTipoHoraExtraTarifa::updateOrCreate(
            ['tipo_id' => $tipo->id, 'desde' => CicloNomina::fecha($data['desde'])->toDateString()],
            ['porcentaje' => $data['porcentaje']],
        );

        return response()->json($this->tipoComoJson($tipo->load('tarifas'), CicloNomina::hoy()), 201);
    }

    /**
     * DELETE /api/nomina/horas-extra/tarifas/{id}. Solo una que todavía no rige
     * (como en el costo del empleador): quitar una que ya rigió cambiaría las
     * horas sin pagar de esas fechas. No se deja sin ninguna.
     */
    public function quitarTarifa(int $id)
    {
        $t = NominaTipoHoraExtraTarifa::findOrFail($id);
        if ($t->desde->toDateString() <= CicloNomina::hoy()->toDateString()) {
            return response()->json(['message' => 'Ese porcentaje ya rige: para cambiarlo, pon otro con la fecha desde la que vale.'], 422);
        }
        if (NominaTipoHoraExtraTarifa::where('tipo_id', $t->tipo_id)->count() <= 1) {
            return response()->json(['message' => 'Es el único porcentaje de ese tipo: cámbialo con otro "desde" o apaga el tipo.'], 422);
        }
        $t->delete();

        return response()->json($this->tipoComoJson(NominaTipoHoraExtra::with('tarifas')->findOrFail($t->tipo_id), CicloNomina::hoy()));
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────

    /** Si con estas horas pasa de 2 ese día o de 12 esa semana (lunes a domingo). */
    private function avisoDeTopes(int $usuarioId, $fecha): ?string
    {
        $dia = (float) NominaHoraExtra::where('usuario_id', $usuarioId)
            ->whereDate('fecha', $fecha->toDateString())->sum('horas');
        [$lunes, $domingo] = CicloNomina::rango('semanal', $fecha);
        $semana = (float) NominaHoraExtra::where('usuario_id', $usuarioId)
            ->whereDate('fecha', '>=', $lunes->toDateString())
            ->whereDate('fecha', '<=', $domingo->toDateString())->sum('horas');

        $f = fn ($n) => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        if ($dia > NominaHoraExtra::TOPE_DIA) {
            return "Ese día lleva {$f($dia)} horas extra: la ley pone un tope de " . NominaHoraExtra::TOPE_DIA . ' al día.';
        }
        if ($semana > NominaHoraExtra::TOPE_SEMANA) {
            return "Esa semana lleva {$f($semana)} horas extra: la ley pone un tope de " . NominaHoraExtra::TOPE_SEMANA . ' a la semana.';
        }

        return null;
    }

    private function pagoQueCubre(int $usuarioId, $fecha): ?NominaPago
    {
        return NominaPago::where('usuario_id', $usuarioId)
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->first();
    }

    private function comoJson(NominaHoraExtra $h): array
    {
        $pago = $h->relationLoaded('pago') ? $h->pago : null;

        // Pagada: lo que quedó congelado en el pago. Sin pagar: con el sueldo
        // de hoy (puede cambiar si le cambian el sueldo antes de pagar).
        $congelada = $pago ? collect($pago->horas_extra_detalle ?? [])->firstWhere('id', $h->id) : null;
        $valorHora = $congelada['valor_hora'] ?? ($h->trabajador?->valorHoraEfectivo() ?? 0.0);
        $empleado  = $h->relationLoaded('trabajador') ? $h->trabajador : null;
        $ciclo = $pago?->nombreCiclo();
        if (! $ciclo && $empleado) {
            [$i, $f] = CicloNomina::rango($empleado->periodicidad, $h->fecha);
            $ciclo = CicloNomina::nombre($empleado->periodicidad, $i, $f);
        }

        return [
            'id'          => $h->id,
            'usuario_id'  => $h->usuario_id,
            'fecha'       => $h->fecha->toDateString(),
            'tipo_id'     => $h->tipo_id,
            'tipo'        => $congelada['tipo'] ?? $h->tipo?->nombre,
            'calculo'     => $congelada['calculo'] ?? $h->tipo?->calculo,
            'porcentaje'  => $congelada['porcentaje'] ?? ($h->tipo ? $h->tipo->porcentajeEn($h->fecha) : 0),
            'horas'       => (float) $h->horas,
            'nota'        => $h->nota,
            'valor_hora'  => (float) $valorHora,
            'monto'       => (float) ($congelada['monto'] ?? $h->valorCon((float) $valorHora)),
            'pagada'      => $h->estaPagada(),
            'ciclo'       => $ciclo,
            'registrada_en' => $h->created_at?->toIso8601String(),
        ];
    }

    private function tipoComoJson(NominaTipoHoraExtra $t, $hoy): array
    {
        $hoyStr = $hoy->toDateString();
        $proxima = $t->tarifas->first(fn ($x) => $x->desde->toDateString() > $hoyStr);

        return [
            'id'         => $t->id,
            'clave'      => $t->clave,
            'nombre'     => $t->nombre,
            'calculo'    => $t->calculo,
            'activo'     => (bool) $t->activo,
            'porcentaje' => $t->porcentajeEn($hoy),
            // Un cambio de ley ya cargado que todavía no rige.
            'proximo'    => $proxima ? ['porcentaje' => (float) $proxima->porcentaje, 'desde' => $proxima->desde->toDateString()] : null,
            'tarifas'    => $t->tarifas->map(fn ($x) => [
                'id' => $x->id, 'porcentaje' => (float) $x->porcentaje, 'desde' => $x->desde->toDateString(),
            ])->values(),
        ];
    }
}
