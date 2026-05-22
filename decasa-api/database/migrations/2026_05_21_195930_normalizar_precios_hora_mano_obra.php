<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Cambia precio_unitario de los ítems de mano de obra vinculados
// de "tarifa por pieza" a "tarifa por hora" (salario_mensual / dias / 8).
// La cantidad pasa a representar horas trabajadas.
// El subtotal se mantiene igual: horas × tarifa_hora ≈ subtotal original.
return new class extends Migration
{
    public function up(): void
    {
        $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');

        $items = DB::table('ficha_tecnica_items as i')
            ->join('tarifas_proceso as tp', 'tp.id', '=', 'i.tarifa_proceso_id')
            ->where('i.es_mano_obra', true)
            ->whereNotNull('i.tarifa_proceso_id')
            ->whereNotNull('tp.cargo')
            ->select('i.id', 'i.subtotal', 'tp.cargo')
            ->get();

        foreach ($items as $item) {
            $salario = $salarios->get($item->cargo);
            if (!$salario || $salario->dias_laborales_mes <= 0) continue;

            $tarifaHora = round($salario->salario_mensual / $salario->dias_laborales_mes / 8, 2);
            if ($tarifaHora <= 0) continue;

            $horas = round($item->subtotal / $tarifaHora, 2);

            DB::table('ficha_tecnica_items')
                ->where('id', $item->id)
                ->update([
                    'precio_unitario' => $tarifaHora,
                    'cantidad'        => $horas,
                    'unidad'          => 'horas',
                    // subtotal no cambia
                ]);
        }
    }

    public function down(): void {}
};
