<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reemplaza el genérico 'horas' en la columna unidad de los ítems de mano de obra
// por un nombre legible del tipo de proceso (Ebanistería, Tapizado, etc.)
// para que la columna "Descripción" de la tabla de ficha técnica siga siendo informativa.
return new class extends Migration
{
    public function up(): void
    {
        $etiquetaProceso = [
            'esqueleteria_silla'        => 'Ebanistería',
            'esqueleteria_sofa'         => 'Ebanistería',
            'esqueleteria_cama'         => 'Ebanistería',
            'esqueleteria_mesa_comedor' => 'Ebanistería',
            'esqueleteria_mesa_aux'     => 'Ebanistería',
            'esqueleteria_cajonero'     => 'Ebanistería',
            'tapizado'                  => 'Tapizado',
            'corte_costura'             => 'Corte y costura',
            'laca'                      => 'Lacado',
            'pintura'                   => 'Pintura',
            'acabados_silla'            => 'Acabados',
            'acabados_sofa'             => 'Acabados',
            'acabados_cama'             => 'Acabados',
            'acabados_mesa_comedor'     => 'Acabados',
            'acabados_mesa_aux'         => 'Acabados',
            'acabados_cajonero'         => 'Acabados',
        ];

        $tarifas = DB::table('tarifas_proceso')->get()->keyBy('id');

        $items = DB::table('ficha_tecnica_items')
            ->where('es_mano_obra', true)
            ->whereNotNull('tarifa_proceso_id')
            ->get(['id', 'tarifa_proceso_id']);

        foreach ($items as $item) {
            $tarifa = $tarifas->get($item->tarifa_proceso_id);
            if (!$tarifa) continue;

            $etiqueta = $etiquetaProceso[$tarifa->proceso] ?? 'Mano de obra';

            DB::table('ficha_tecnica_items')
                ->where('id', $item->id)
                ->update(['unidad' => $etiqueta]);
        }
    }

    public function down(): void {}
};
