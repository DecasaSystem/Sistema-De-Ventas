<?php

namespace App\Http\Controllers;

use App\Models\Gasto;
use App\Models\GastoRecurrente;
use App\Services\Finanzas\Cierres;
use App\Services\Finanzas\ObligacionesRecurrentes;
use App\Services\Finanzas\Periodo;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los gastos de la empresa: los sueltos (un flete, una reparación) y el pago
 * de cada periodo de una plantilla (el internet de octubre).
 *
 * Nada se borra: un error se ANULA con motivo y queda en la bitácora. Un
 * periodo de plantilla que ese mes no se cobró se OMITE (para que deje de
 * salir como vencido).
 */
class GastoController extends Controller
{
    /** Los canales de venta (ordenes.canal) a los que se le puede atar un gasto. */
    public const CANALES = ['fisica', 'whatsapp', 'instagram', 'facebook', 'pagina', 'red_social', 'otro'];

    /** Un gasto no se registra, corrige ni anula en un mes cerrado (Cierres). */
    private function exigirMesesAbiertos(string $desde, string $hasta): void
    {
        foreach (Periodo::meses(Periodo::mesDe($desde), Periodo::mesDe($hasta)) as $mes) {
            Cierres::exigirAbierto($mes);
        }
    }

    /** GET /api/finanzas/gastos?mes=&categoria_id=&tienda_id=&incluir_anulados=1 */
    public function index(Request $request)
    {
        $mes = Periodo::valido($request->query('mes')) ? $request->query('mes') : Periodo::mesActual();
        [$ini, $fin] = Periodo::limites($mes);

        $q = Gasto::with(['categoria:id,nombre,icono,naturaleza', 'tienda:id,nombre', 'recurrente:id,nombre', 'registradoPor:id,nombre'])
            ->whereDate('fecha_pago', '>=', $ini->toDateString())->whereDate('fecha_pago', '<=', $fin->toDateString())
            ->when($request->query('categoria_id'), fn ($q, $id) => $q->where('categoria_gasto_id', $id))
            ->when($request->query('tienda_id'), fn ($q, $id) => $id === 'general' ? $q->whereNull('tienda_id') : $q->where('tienda_id', $id))
            ->orderByDesc('fecha_pago')->orderByDesc('id');

        if (! $request->boolean('incluir_anulados')) {
            $q->where('estado', Gasto::PAGADO);
        }

        return response()->json($q->get()->map(fn (Gasto $g) => $this->comoJson($g)));
    }

    /** GET /api/finanzas/gastos/pendientes?dias=45 — lo que deben las plantillas. */
    public function pendientes(Request $request)
    {
        $dias = min(120, max(0, (int) $request->query('dias', 45)));

        return response()->json(ObligacionesRecurrentes::pendientes($dias));
    }

    /**
     * POST /api/finanzas/gastos — un gasto suelto, o el pago de un periodo de
     * plantilla (`gasto_recurrente_id` + `periodo`).
     *
     * A qué meses pertenece (estado de resultados):
     * - de plantilla: lo que cubre su periodo;
     * - suelto: el mes de `corresponde_a` si se dice (la luz de septiembre
     *   pagada en octubre), repartido en `prorratear_meses` si es de varios
     *   meses (un seguro anual), o si no el mes en que se pagó.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'gasto_recurrente_id' => 'nullable|exists:gastos_recurrentes,id',
            'periodo'             => 'nullable|required_with:gasto_recurrente_id|date_format:Y-m-d',
            'categoria_gasto_id'  => 'required_without:gasto_recurrente_id|nullable|exists:categorias_gasto,id',
            'concepto'            => 'required_without:gasto_recurrente_id|nullable|string|max:160',
            'monto'               => 'required|numeric|min:1',
            'fecha_pago'          => 'nullable|date',
            'tienda_id'           => 'nullable|exists:tiendas,id',
            'proveedor_id'        => 'nullable|exists:proveedores,id',
            'metodo_pago'         => ['nullable', Rule::in(Gasto::METODOS)],
            'corresponde_a'       => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'prorratear_meses'    => 'nullable|integer|min:1|max:36',
            'comprobante_url'     => 'nullable|string|max:500',
            'comprobante_fotos'   => 'nullable|array|max:10',
            'comprobante_fotos.*' => 'string|max:500',
            'notas'               => 'nullable|string|max:2000',
            // De qué canal es (publicidad en Instagram, WhatsApp…): rentabilidad por canal.
            'canal'               => ['nullable', Rule::in(self::CANALES)],
        ], [
            'monto.required'  => 'Falta el monto.',
            'monto.min'       => 'El monto tiene que ser mayor que cero.',
            'categoria_gasto_id.required_without' => 'Elige la categoría.',
            'concepto.required_without' => 'Escribe qué se pagó.',
        ]);

        $fechaPago = Carbon::parse($data['fecha_pago'] ?? Periodo::hoy()->toDateString())->toDateString();
        $fotos = $data['comprobante_fotos'] ?? (! empty($data['comprobante_url']) ? [$data['comprobante_url']] : null);

        $base = [
            'monto'             => $data['monto'],
            'estado'            => Gasto::PAGADO,
            'fecha_pago'        => $fechaPago,
            'proveedor_id'      => $data['proveedor_id'] ?? null,
            'metodo_pago'       => $data['metodo_pago'] ?? null,
            'comprobante_url'   => $fotos[0] ?? null,
            'comprobante_fotos' => $fotos,
            'notas'             => $data['notas'] ?? null,
            'canal'             => $data['canal'] ?? null,
            'registrado_por'    => $request->user()->id,
        ];

        if (! empty($data['gasto_recurrente_id'])) {
            $plantilla = GastoRecurrente::findOrFail($data['gasto_recurrente_id']);
            $p = $this->periodoValido($plantilla, $data['periodo']);
            $atributos = $base + [
                'gasto_recurrente_id' => $plantilla->id,
                'periodo'             => $p['periodo'],
                'categoria_gasto_id'  => $data['categoria_gasto_id'] ?? $plantilla->categoria_gasto_id,
                'tienda_id'           => array_key_exists('tienda_id', $data) ? $data['tienda_id'] : $plantilla->tienda_id,
                'proveedor_id'        => $data['proveedor_id'] ?? $plantilla->proveedor_id,
                'metodo_pago'         => $data['metodo_pago'] ?? $plantilla->metodo_pago,
                'concepto'            => $data['concepto'] ?? $plantilla->nombre,
                'cubre_desde'         => $p['cubre_desde'],
                'cubre_hasta'         => $p['cubre_hasta'],
            ];
        } else {
            [$cubreDesde, $cubreHasta] = $this->cubre($fechaPago, $data['corresponde_a'] ?? null, $data['prorratear_meses'] ?? null);
            $atributos = $base + [
                'categoria_gasto_id' => $data['categoria_gasto_id'],
                'tienda_id'          => $data['tienda_id'] ?? null,
                'concepto'           => $data['concepto'],
                'cubre_desde'        => $cubreDesde,
                'cubre_hasta'        => $cubreHasta,
            ];
        }

        $gasto = $this->crear($atributos, $request->user()->id);

        return response()->json($this->comoJson($gasto->load(['categoria', 'tienda', 'recurrente'])), 201);
    }

    /** POST /api/finanzas/gastos/omitir — ese periodo de la plantilla no se cobró. */
    public function omitir(Request $request)
    {
        $data = $request->validate([
            'gasto_recurrente_id' => 'required|exists:gastos_recurrentes,id',
            'periodo'             => 'required|date_format:Y-m-d',
            'notas'               => 'nullable|string|max:500',
        ]);

        $plantilla = GastoRecurrente::findOrFail($data['gasto_recurrente_id']);
        $p = $this->periodoValido($plantilla, $data['periodo']);

        $gasto = $this->crear([
            'gasto_recurrente_id' => $plantilla->id,
            'periodo'             => $p['periodo'],
            'categoria_gasto_id'  => $plantilla->categoria_gasto_id,
            'tienda_id'           => $plantilla->tienda_id,
            'concepto'            => $plantilla->nombre,
            'monto'               => 0,
            'estado'              => Gasto::OMITIDO,
            'fecha_pago'          => $p['vence'],
            'cubre_desde'         => $p['cubre_desde'],
            'cubre_hasta'         => $p['cubre_hasta'],
            'notas'               => $data['notas'] ?? 'No se cobró este periodo',
            'registrado_por'      => $request->user()->id,
        ], $request->user()->id, 'omitir');

        return response()->json($this->comoJson($gasto), 201);
    }

    /** PATCH /api/finanzas/gastos/{id} — corregir un gasto pagado. */
    public function update(Request $request, int $id)
    {
        $gasto = Gasto::findOrFail($id);
        if ($gasto->estado !== Gasto::PAGADO) {
            throw ValidationException::withMessages(['estado' => ['Solo se corrige un gasto pagado.']]);
        }
        $this->exigirMesesAbiertos($gasto->cubre_desde->toDateString(), $gasto->cubre_hasta->toDateString());
        if ($request->filled('cubre_desde') || $request->filled('cubre_hasta')) {
            $this->exigirMesesAbiertos($request->input('cubre_desde', $gasto->cubre_desde->toDateString()),
                $request->input('cubre_hasta', $gasto->cubre_hasta->toDateString()));
        }

        $data = $request->validate([
            'categoria_gasto_id' => 'sometimes|exists:categorias_gasto,id',
            'concepto'           => 'sometimes|string|max:160',
            'monto'              => 'sometimes|numeric|min:1',
            'fecha_pago'         => 'sometimes|date',
            'cubre_desde'        => 'sometimes|date',
            'cubre_hasta'        => 'sometimes|date|after_or_equal:cubre_desde',
            'tienda_id'          => 'sometimes|nullable|exists:tiendas,id',
            'proveedor_id'       => 'sometimes|nullable|exists:proveedores,id',
            'metodo_pago'        => ['sometimes', 'nullable', Rule::in(Gasto::METODOS)],
            'comprobante_fotos'  => 'sometimes|nullable|array|max:10',
            'notas'              => 'sometimes|nullable|string|max:2000',
        ]);
        if (array_key_exists('comprobante_fotos', $data)) {
            $data['comprobante_url'] = $data['comprobante_fotos'][0] ?? null;
        }

        DB::transaction(function () use ($gasto, $data, $request) {
            $antes = $gasto->only(array_keys($data));
            $gasto->update($data);
            $this->bitacora('gasto', $gasto->id, 'editar', $antes, $gasto->only(array_keys($data)), $request->user()->id);
        });

        return response()->json($this->comoJson($gasto->fresh(['categoria', 'tienda', 'recurrente'])));
    }

    /** POST /api/finanzas/gastos/{id}/anular — un error: deja de contar, pero queda. */
    public function anular(Request $request, int $id)
    {
        $data = $request->validate(['motivo' => 'required|string|max:200'], ['motivo.required' => '¿Por qué se anula?']);
        $gasto = Gasto::findOrFail($id);

        if ($gasto->estado === Gasto::ANULADO) {
            throw ValidationException::withMessages(['estado' => ['Ya estaba anulado.']]);
        }
        $this->exigirMesesAbiertos($gasto->cubre_desde->toDateString(), $gasto->cubre_hasta->toDateString());

        DB::transaction(function () use ($gasto, $data, $request) {
            $antes = ['estado' => $gasto->estado, 'periodo' => $gasto->periodo];
            $gasto->update([
                'estado'           => Gasto::ANULADO,
                'motivo_anulacion' => $data['motivo'],
                'anulado_por'      => $request->user()->id,
                'anulado_at'       => now(),
                // Suelta el periodo de la plantilla: vuelve a salir por pagar.
                'periodo'          => $gasto->periodo ? $gasto->periodo . '#' . $gasto->id : null,
            ]);
            $this->bitacora('gasto', $gasto->id, 'anular', $antes, ['estado' => Gasto::ANULADO, 'motivo' => $data['motivo']], $request->user()->id);
        });

        return response()->json($this->comoJson($gasto->fresh(['categoria', 'tienda', 'recurrente'])));
    }

    private function crear(array $atributos, int $usuarioId, string $accion = 'crear'): Gasto
    {
        $this->exigirMesesAbiertos((string) $atributos['cubre_desde'], (string) $atributos['cubre_hasta']);

        try {
            return DB::transaction(function () use ($atributos, $usuarioId, $accion) {
                $gasto = Gasto::create($atributos);
                $this->bitacora('gasto', $gasto->id, $accion, null, $gasto->only(['concepto', 'monto', 'estado', 'periodo']), $usuarioId);

                return $gasto;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['periodo' => ['Ese periodo ya estaba pagado u omitido.']]);
        }
    }

    private function periodoValido(GastoRecurrente $plantilla, string $periodo): array
    {
        $p = ObligacionesRecurrentes::buscar($plantilla, $periodo);
        if (! $p) {
            throw ValidationException::withMessages(['periodo' => ['Ese no es un periodo de esta plantilla.']]);
        }

        return $p;
    }

    /** @return array{0: string, 1: string} */
    private function cubre(string $fechaPago, ?string $correspondeA, ?int $meses): array
    {
        if ($correspondeA) {
            [$ini] = Periodo::limites($correspondeA);
        } elseif ($meses && $meses > 1) {
            $ini = Carbon::parse($fechaPago, Periodo::TZ)->startOfMonth();
        } else {
            return [$fechaPago, $fechaPago];
        }

        $fin = $ini->copy()->addMonthsNoOverflow(max(1, $meses ?? 1))->subDay();

        return [$ini->toDateString(), $fin->toDateString()];
    }

    private function bitacora(string $entidad, int $id, string $accion, ?array $antes, ?array $despues, ?int $usuarioId): void
    {
        DB::table('gastos_bitacora')->insert([
            'entidad' => $entidad, 'entidad_id' => $id, 'accion' => $accion,
            'antes' => $antes ? json_encode($antes) : null, 'despues' => $despues ? json_encode($despues) : null,
            'usuario_id' => $usuarioId, 'created_at' => now(),
        ]);
    }

    private function comoJson(Gasto $g): array
    {
        return [
            'id'                  => $g->id,
            'concepto'            => $g->concepto,
            'monto'               => (float) $g->monto,
            'estado'              => $g->estado,
            'categoria_gasto_id'  => $g->categoria_gasto_id,
            'categoria'           => $g->categoria?->nombre,
            'icono'               => $g->categoria?->icono,
            'naturaleza'          => $g->categoria?->naturaleza,
            'tienda_id'           => $g->tienda_id,
            'tienda'              => $g->tienda?->nombre,
            'gasto_recurrente_id' => $g->gasto_recurrente_id,
            'plantilla'           => $g->recurrente?->nombre,
            'periodo'             => $g->periodo,
            'fecha_pago'          => $g->fecha_pago?->toDateString(),
            'cubre_desde'         => $g->cubre_desde?->toDateString(),
            'cubre_hasta'         => $g->cubre_hasta?->toDateString(),
            'metodo_pago'         => $g->metodo_pago,
            'comprobante_fotos'   => $g->comprobante_fotos ?? ($g->comprobante_url ? [$g->comprobante_url] : []),
            'notas'               => $g->notas,
            'motivo_anulacion'    => $g->motivo_anulacion,
            'registrado_por'      => $g->registradoPor?->nombre,
        ];
    }
}
