<?php

namespace App\Http\Controllers;

use App\Models\Gasto;
use App\Models\GastoRecurrente;
use App\Services\Finanzas\ObligacionesRecurrentes;
use App\Services\Finanzas\Periodo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Las plantillas de gastos que se repiten (gastos fijos): arriendo, internet,
 * licencias, servicios. Se crean una vez y cada periodo aparece solo como
 * "por pagar". No se borran: se desactivan (los pagos viejos las nombran).
 */
class GastoRecurrenteController extends Controller
{
    /** GET /api/finanzas/recurrentes?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = GastoRecurrente::with(['categoria:id,nombre,icono,naturaleza', 'tienda:id,nombre', 'proveedor:id,nombre'])
            ->orderByDesc('activo')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) $q->where('activo', true);

        $plantillas = $q->get();
        $sugeridos = ObligacionesRecurrentes::montosSugeridos($plantillas);
        $proximos = ObligacionesRecurrentes::pendientes(400)->groupBy('gasto_recurrente_id');
        $ultimos = Gasto::whereIn('gasto_recurrente_id', $plantillas->pluck('id'))->where('estado', Gasto::PAGADO)
            ->selectRaw('gasto_recurrente_id, MAX(fecha_pago) AS ultimo')->groupBy('gasto_recurrente_id')
            ->pluck('ultimo', 'gasto_recurrente_id');

        return response()->json($plantillas->map(fn (GastoRecurrente $g) => $this->comoJson($g) + [
            'monto_sugerido' => $sugeridos[$g->id] ?? (float) $g->monto,
            'proximo'        => ($proximos[$g->id] ?? collect())->first(),
            'por_pagar'      => ($proximos[$g->id] ?? collect())->whereIn('estado', ['vencida', 'por_vencer'])->count(),
            'ultimo_pago'    => isset($ultimos[$g->id]) ? substr((string) $ultimos[$g->id], 0, 10) : null,
            'equivalente_mes' => $this->equivalenteMes($g, $sugeridos[$g->id] ?? (float) $g->monto),
        ]));
    }

    /** POST /api/finanzas/recurrentes */
    public function store(Request $request)
    {
        $data = $this->validar($request, true);
        $data['creado_por'] = $request->user()->id;
        $data['activo'] = true;

        $g = DB::transaction(function () use ($data, $request) {
            $g = GastoRecurrente::create($data);
            $this->bitacora($g->id, 'crear', null, $data, $request->user()->id);

            return $g;
        });

        return response()->json($this->comoJson($g->load(['categoria', 'tienda'])), 201);
    }

    /**
     * PATCH /api/finanzas/recurrentes/{id}
     *
     * Cambiar el monto (el arriendo sube en enero) mueve lo que no se ha
     * pagado; lo pagado no se toca (cada gasto guardó su monto).
     */
    public function update(Request $request, int $id)
    {
        $g = GastoRecurrente::findOrFail($id);
        $data = $this->validar($request, false);

        DB::transaction(function () use ($g, $data, $request) {
            $antes = $g->only(array_keys($data));
            $g->update($data);
            $this->bitacora($g->id, 'editar', $antes, $data, $request->user()->id);
        });

        return response()->json($this->comoJson($g->fresh(['categoria', 'tienda'])));
    }

    private function validar(Request $request, bool $nuevo): array
    {
        $req = $nuevo ? 'required' : 'sometimes';

        return $request->validate([
            'nombre'             => "$req|string|max:120",
            'categoria_gasto_id' => "$req|exists:categorias_gasto,id",
            'tienda_id'          => 'sometimes|nullable|exists:tiendas,id',
            'proveedor_id'       => 'sometimes|nullable|exists:proveedores,id',
            'monto'              => "$req|numeric|min:0",
            'monto_estimado'     => 'sometimes|boolean',
            'frecuencia'         => [$req, Rule::in(GastoRecurrente::FRECUENCIAS)],
            'dia_pago'           => 'sometimes|nullable|integer|min:1|max:31',
            'desde'              => "$req|date",
            'hasta'              => 'sometimes|nullable|date|after_or_equal:desde',
            'prorratear'         => 'sometimes|boolean',
            'metodo_pago'        => ['sometimes', 'nullable', Rule::in(Gasto::METODOS)],
            'avisar_dias_antes'  => 'sometimes|integer|min:0|max:30',
            'notas'              => 'sometimes|nullable|string|max:2000',
            'activo'             => 'sometimes|boolean',
        ], [
            'nombre.required'             => 'Ponle un nombre (ej. "Internet Norte").',
            'categoria_gasto_id.required' => 'Elige la categoría.',
            'monto.required'              => 'Falta el monto.',
            'desde.required'              => '¿Desde cuándo se paga?',
        ]);
    }

    /** Cuánto pesa al mes: la licencia anual de $1.200.000 son $100.000. */
    private function equivalenteMes(GastoRecurrente $g, float $monto): float
    {
        return round(match ($g->frecuencia) {
            'semanal'    => $monto * 52 / 12,
            'quincenal'  => $monto * 2,
            'mensual'    => $monto,
            'bimestral'  => $monto / 2,
            'trimestral' => $monto / 3,
            'semestral'  => $monto / 6,
            'anual'      => $monto / 12,
            default      => $monto,
        });
    }

    private function bitacora(int $id, string $accion, ?array $antes, ?array $despues, int $usuarioId): void
    {
        DB::table('gastos_bitacora')->insert([
            'entidad' => 'recurrente', 'entidad_id' => $id, 'accion' => $accion,
            'antes' => $antes ? json_encode($antes) : null, 'despues' => $despues ? json_encode($despues) : null,
            'usuario_id' => $usuarioId, 'created_at' => now(),
        ]);
    }

    private function comoJson(GastoRecurrente $g): array
    {
        return [
            'id'                 => $g->id,
            'nombre'             => $g->nombre,
            'categoria_gasto_id' => $g->categoria_gasto_id,
            'categoria'          => $g->categoria?->nombre,
            'icono'              => $g->categoria?->icono,
            'naturaleza'         => $g->categoria?->naturaleza,
            'tienda_id'          => $g->tienda_id,
            'tienda'             => $g->tienda?->nombre,
            'proveedor_id'       => $g->proveedor_id,
            'monto'              => (float) $g->monto,
            'monto_estimado'     => (bool) $g->monto_estimado,
            'frecuencia'         => $g->frecuencia,
            'dia_pago'           => $g->dia_pago,
            'desde'              => $g->desde?->toDateString(),
            'hasta'              => $g->hasta?->toDateString(),
            'prorratear'         => (bool) $g->prorratear,
            'metodo_pago'        => $g->metodo_pago,
            'avisar_dias_antes'  => $g->avisar_dias_antes,
            'notas'              => $g->notas,
            'activo'             => (bool) $g->activo,
        ];
    }
}
