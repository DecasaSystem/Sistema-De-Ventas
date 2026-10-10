<?php

namespace App\Http\Controllers;

use App\Models\CategoriaGasto;
use App\Models\PresupuestoGasto;
use App\Services\Finanzas\FuenteGastos;
use App\Services\Finanzas\Periodo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Las categorías de gasto y su presupuesto mensual. Las categorías se
 * desactivan, no se borran (los gastos viejos las nombran).
 */
class CategoriaGastoController extends Controller
{
    /** GET /api/finanzas/categorias?incluir_inactivas=1 */
    public function index(Request $request)
    {
        $q = CategoriaGasto::orderBy('orden')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivas')) $q->where('activo', true);

        return response()->json($q->get());
    }

    /** POST /api/finanzas/categorias */
    public function store(Request $request)
    {
        $data = $this->validar($request, true);
        $data['orden'] = (int) CategoriaGasto::max('orden') + 1;

        return response()->json(CategoriaGasto::create($data), 201);
    }

    /** PATCH /api/finanzas/categorias/{id} */
    public function update(Request $request, int $id)
    {
        $c = CategoriaGasto::findOrFail($id);
        $c->update($this->validar($request, false, $id));

        return response()->json($c->fresh());
    }

    /**
     * GET /api/finanzas/presupuesto?mes=YYYY-MM — lo presupuestado contra lo
     * gastado, por categoría. Avisa cuando una pasa del 90 %.
     */
    public function presupuesto(Request $request)
    {
        $mes = Periodo::valido($request->query('mes')) ? $request->query('mes') : Periodo::mesActual();
        $real = collect(FuenteGastos::porMes($mes, $mes)[$mes]['por_categoria'] ?? [])->keyBy('id');
        $plan = PresupuestoGasto::where('mes', $mes)->pluck('monto', 'categoria_gasto_id');

        $filas = CategoriaGasto::where('activo', true)->orderBy('orden')->get()->map(function ($c) use ($real, $plan) {
            $gastado = (float) ($real[$c->id]['monto'] ?? 0);
            $presupuesto = isset($plan[$c->id]) ? (float) $plan[$c->id] : null;

            return [
                'categoria_gasto_id' => $c->id,
                'nombre'      => $c->nombre,
                'icono'       => $c->icono,
                'presupuesto' => $presupuesto,
                'gastado'     => round($gastado),
                'avance'      => $presupuesto ? round($gastado / $presupuesto, 3) : null,
            ];
        })->filter(fn ($f) => $f['presupuesto'] !== null || $f['gastado'] > 0)->values();

        return response()->json([
            'mes'          => $mes,
            'categorias'   => $filas,
            'presupuesto'  => round($filas->sum('presupuesto')),
            'gastado'      => round($filas->sum('gastado')),
        ]);
    }

    /**
     * PUT /api/finanzas/presupuesto — el presupuesto de un mes. Con
     * `copiar_de` se trae el de otro mes (el presupuesto casi no cambia).
     */
    public function guardarPresupuesto(Request $request)
    {
        $data = $request->validate([
            'mes'                         => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'copiar_de'                   => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'items'                       => 'array',
            'items.*.categoria_gasto_id'  => 'required|exists:categorias_gasto,id',
            'items.*.monto'               => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            if (! empty($data['copiar_de'])) {
                $items = PresupuestoGasto::where('mes', $data['copiar_de'])->get()
                    ->map(fn ($p) => ['categoria_gasto_id' => $p->categoria_gasto_id, 'monto' => (float) $p->monto])->all();
            }
            foreach ($items as $i) {
                if ($i['monto'] === null || $i['monto'] === '') {
                    PresupuestoGasto::where('mes', $data['mes'])->where('categoria_gasto_id', $i['categoria_gasto_id'])->delete();
                    continue;
                }
                PresupuestoGasto::updateOrCreate(
                    ['mes' => $data['mes'], 'categoria_gasto_id' => $i['categoria_gasto_id']],
                    ['monto' => $i['monto']]
                );
            }
        });

        return $this->presupuesto(new Request(['mes' => $data['mes']]));
    }

    private function validar(Request $request, bool $nueva, ?int $id = null): array
    {
        $req = $nueva ? 'required' : 'sometimes';

        return $request->validate([
            'nombre'           => [$req, 'string', 'max:80', Rule::unique('categorias_gasto', 'nombre')->ignore($id)],
            'grupo'            => 'sometimes|string|max:30',
            'naturaleza'       => ['sometimes', Rule::in(['fijo', 'variable'])],
            'varia_con_ventas' => 'sometimes|boolean',
            'area'             => ['sometimes', Rule::in(CategoriaGasto::AREAS)],
            'codigo_puc'       => 'sometimes|nullable|string|max:12',
            'icono'            => 'sometimes|nullable|string|max:60',
            'activo'           => 'sometimes|boolean',
        ], ['nombre.unique' => 'Ya hay una categoría con ese nombre.']);
    }
}
