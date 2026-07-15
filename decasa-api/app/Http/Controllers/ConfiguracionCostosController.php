<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'salarios'              => $salarios,
            'procesos'              => $procesos,
            'factor_venta_sugerido' => $this->factorVentaSugerido(),
        ]);
    }

    /** Factor con el que se sugiere el precio de venta (costo × factor). Default ×2.0. */
    private function factorVentaSugerido(): float
    {
        $valor = DB::table('configuracion')->where('clave', 'factor_venta_sugerido')->value('valor');
        return is_numeric($valor) && (float) $valor > 0 ? (float) $valor : 2.0;
    }

    /** PUT /configuracion/costos/factor-venta — ajusta el factor de sugerencia de venta. */
    public function guardarFactorVenta(Request $request)
    {
        $data = $request->validate([
            'factor_venta_sugerido' => 'required|numeric|min:1|max:10',
        ]);

        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'factor_venta_sugerido'],
            ['valor' => (string) $data['factor_venta_sugerido'], 'updated_at' => now()],
        );

        return response()->json(['factor_venta_sugerido' => (float) $data['factor_venta_sugerido']]);
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

    // POST /configuracion/costos/cargos — crear nuevo tipo de operario
    public function crearCargo(Request $request)
    {
        $data = $request->validate([
            'cargo'             => 'required|string|max:50',
            'descripcion'       => 'required|string|max:200',
            'salario_mensual'   => 'required|numeric|min:0',
            'dias_laborales_mes'=> 'nullable|integer|min:1|max:31',
            'tarifa_hora'       => 'nullable|numeric|min:0',
        ]);

        $slug = Str::slug($data['cargo'], '_');

        if (DB::table('salarios_cargo')->where('cargo', $slug)->exists()) {
            return response()->json(['message' => "Ya existe un cargo con ese nombre ({$slug})."], 422);
        }

        DB::table('salarios_cargo')->insert([
            'cargo'             => $slug,
            'descripcion'       => $data['descripcion'],
            'salario_mensual'   => $data['salario_mensual'],
            'dias_laborales_mes'=> $data['dias_laborales_mes'] ?? 26,
            'tarifa_hora'       => $data['tarifa_hora'] ?? 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $this->index();
    }

    // DELETE /configuracion/costos/cargos/{cargo}
    public function eliminarCargo(string $cargo)
    {
        $base = ['carpintero', 'tapicero', 'costurera', 'lacador'];
        if (in_array($cargo, $base)) {
            return response()->json(['message' => 'No se puede eliminar un cargo base del sistema.'], 422);
        }

        if (DB::table('tarifas_proceso')->where('cargo', $cargo)->exists()) {
            return response()->json(['message' => 'Elimina primero los trabajos vinculados a este cargo.'], 422);
        }

        DB::table('salarios_cargo')->where('cargo', $cargo)->delete();
        return $this->index();
    }

    // POST /configuracion/costos/procesos — crear nuevo trabajo dentro de un cargo
    public function crearProceso(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:100',
            'descripcion' => 'nullable|string|max:300',
            'unidad'      => 'required|in:pieza,m2,ml,hora,puesto',
            'cargo'       => 'required|exists:salarios_cargo,cargo',
            'horas'       => 'required|numeric|min:0',
        ]);

        // Generar clave única: cargo_nombre_timestamp si hay colisión
        $base = Str::slug($data['cargo'] . '_' . $data['nombre'], '_');
        $slug = $base;
        $i    = 2;
        while (DB::table('tarifas_proceso')->where('proceso', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        $salario     = DB::table('salarios_cargo')->where('cargo', $data['cargo'])->first();
        $diasPorUnidad = $data['horas'] / 8;
        $tarifa      = ($salario && $salario->tarifa_hora > 0)
            ? round($salario->tarifa_hora * $data['horas'], 0)
            : 0;

        DB::table('tarifas_proceso')->insert([
            'proceso'        => $slug,
            'descripcion'    => $data['descripcion'] ?: $data['nombre'],
            'unidad'         => $data['unidad'],
            'cargo'          => $data['cargo'],
            'aplica_a'       => 'personalizado',
            'dias_por_unidad'=> $diasPorUnidad,
            'tarifa'         => $tarifa,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $this->index();
    }

    // DELETE /configuracion/costos/procesos/{id}
    public function eliminarProceso(int $id)
    {
        if (DB::table('ficha_tecnica_items')->where('tarifa_proceso_id', $id)->exists()) {
            return response()->json(['message' => 'Este trabajo está en uso en fichas técnicas. Desvinculado primero.'], 422);
        }

        DB::table('tarifas_proceso')->where('id', $id)->delete();
        return $this->index();
    }
}
