<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Restaura la descripción de los ítems de mano de obra vinculados a tarifas_proceso
// copiando la descripción del proceso (ej. "Tapizado de una pieza de mueble…") para que
// el usuario sepa qué tipo de trabajo representa cada ítem.
return new class extends Migration
{
    public function up(): void
    {
        $tarifas = DB::table('tarifas_proceso')->get()->keyBy('id');

        $items = DB::table('ficha_tecnica_items')
            ->where('es_mano_obra', true)
            ->whereNotNull('tarifa_proceso_id')
            ->get(['id', 'tarifa_proceso_id']);

        foreach ($items as $item) {
            $tarifa = $tarifas->get($item->tarifa_proceso_id);
            if (!$tarifa) continue;

            DB::table('ficha_tecnica_items')
                ->where('id', $item->id)
                ->update(['descripcion' => $tarifa->descripcion]);
        }
    }

    public function down(): void {}
};
