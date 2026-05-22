<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionCostosController extends Controller
{
    public function index()
    {
        $salarios = DB::table('salarios_cargo')
            ->orderBy('cargo')
            ->get()
            ->map(fn($s) => (array) $s + [
                'tarifa_diaria' => round($s->tarifa_hora * 8, 0),
            ]);

        $procesos = DB::table('tarifas_proceso as tp')
            ->leftJoin('salarios_cargo as sc', 'sc.cargo', '=', 'tp.cargo')
            ->orderBy('tp.cargo')
            ->orderBy('tp.proceso')
            ->get([
                'tp.id', 'tp.proceso', 'tp.descripcion', 'tp.unidad',
                'tp.cargo', 'tp.dias_por_unidad', 'tp.tarifa',
                'sc.salario_mensual', 'sc.dias_laborales_mes', 'sc.tarifa_hora',
            ]);

        return response()->json([
            'salarios' => $salarios,
            'procesos' => $procesos,
        ]);
    }

    public function guardar(Request $request)
    {
        $data = $request->validate([
            'salarios'                        => 'required|array',
            'salarios.*.cargo'                => 'required|string',
            'salarios.*.salario_mensual'      => 'required|numeric|min:0',
            'salarios.*.dias_laborales_mes'   => 'required|integer|min:1|max:31',
            'salarios.*.tarifa_hora'          => 'required|numeric|min:0',
            'procesos'                        => 'required|array',
            'procesos.*.id'                   => 'required|integer|exists:tarifas_proceso,id',
            'procesos.*.dias_por_unidad'      => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($data) {
            // 1. Actualizar salarios (salario_mensual y tarifa_hora son independientes)
            foreach ($data['salarios'] as $s) {
                DB::table('salarios_cargo')
                    ->where('cargo', $s['cargo'])
                    ->update([
                        'salario_mensual'    => $s['salario_mensual'],
                        'dias_laborales_mes' => $s['dias_laborales_mes'],
                        'tarifa_hora'        => $s['tarifa_hora'],
                        'updated_at'         => now(),
                    ]);
            }

            // 2. Reindexar salarios para cálculo
            $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');

            // 3. Actualizar días_por_unidad y recalcular tarifa usando tarifa_hora (incentivo)
            foreach ($data['procesos'] as $p) {
                $proceso = DB::table('tarifas_proceso')->find($p['id']);
                $salario = $proceso->cargo ? $salarios->get($proceso->cargo) : null;

                // tarifa por pieza = tarifa_hora × horas_por_pieza (dias_por_unidad × 8)
                $nuevaTarifa = $salario && $salario->tarifa_hora > 0
                    ? round($salario->tarifa_hora * 8 * $p['dias_por_unidad'], 0)
                    : $proceso->tarifa;

                DB::table('tarifas_proceso')
                    ->where('id', $p['id'])
                    ->update([
                        'dias_por_unidad' => $p['dias_por_unidad'],
                        'tarifa'          => $nuevaTarifa,
                        'updated_at'      => now(),
                    ]);

                // Propagar al ficha_tecnica_items vinculados usando tarifa_hora del incentivo
                $tarifaHora = $salario ? round($salario->tarifa_hora, 2) : 0;

                if ($tarifaHora > 0) {
                    $itemsVinculados = DB::table('ficha_tecnica_items')
                        ->where('tarifa_proceso_id', $p['id'])
                        ->get(['id', 'cantidad']);

                    foreach ($itemsVinculados as $item) {
                        $nuevoSubtotal = round($item->cantidad * $tarifaHora, 2);
                        DB::table('ficha_tecnica_items')
                            ->where('id', $item->id)
                            ->update([
                                'precio_unitario' => $tarifaHora,
                                'subtotal'        => $nuevoSubtotal,
                                'updated_at'      => now(),
                            ]);
                    }

                    // Recalcular totales de las fichas afectadas
                    $fichaIds = DB::table('ficha_tecnica_items')
                        ->where('tarifa_proceso_id', $p['id'])
                        ->distinct()->pluck('ficha_tecnica_id');

                    foreach ($fichaIds as $fichaId) {
                        $allItems = DB::table('ficha_tecnica_items')
                            ->where('ficha_tecnica_id', $fichaId)->get();
                        $costoMat = $allItems->where('es_mano_obra', false)->sum('subtotal');
                        $costoMO  = $allItems->where('es_mano_obra', true)->sum('subtotal');
                        DB::table('fichas_tecnicas')->where('id', $fichaId)->update([
                            'costo_materiales' => round($costoMat, 2),
                            'costo_mano_obra'  => round($costoMO,  2),
                            'costo_total'      => round($costoMat + $costoMO, 2),
                            'updated_at'       => now(),
                        ]);
                    }
                }
            }
        });

        return $this->index();
    }
}
