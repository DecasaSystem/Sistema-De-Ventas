<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// La migración genérica 'vincular_items_mano_obra_genericos' vinculó TODOS los ítems
// de mano de obra sin sección/con sección=nombre del producto a esqueleteria_*, incluyendo
// los de PINTURA. Aquí detectamos esos grupos (ficha + sección) donde todos los ítems
// apuntan al mismo proceso de esqueletería, y re-vinculamos el de menor subtotal a pintura,
// que es el patrón consistente del Excel original: 1 estructura (mayor costo) + 1 pintura (menor costo).
return new class extends Migration
{
    public function up(): void
    {
        $tarifaPintura = DB::table('tarifas_proceso')->where('proceso', 'pintura')->first();
        $salarios      = DB::table('salarios_cargo')->get()->keyBy('cargo');

        if (!$tarifaPintura) return;

        $salarioLacador = $salarios->get($tarifaPintura->cargo);
        $tarifaHoraLacador = $salarioLacador && $salarioLacador->dias_laborales_mes > 0
            ? round($salarioLacador->salario_mensual / $salarioLacador->dias_laborales_mes / 8, 2)
            : 0;

        // Traer todos los ítems de mano de obra vinculados a esqueleteria
        $items = DB::table('ficha_tecnica_items as i')
            ->join('tarifas_proceso as tp', 'tp.id', '=', 'i.tarifa_proceso_id')
            ->where('i.es_mano_obra', true)
            ->where('tp.proceso', 'LIKE', 'esqueleteria%')
            ->select('i.id', 'i.ficha_tecnica_id', 'i.seccion', 'i.subtotal', 'i.tarifa_proceso_id')
            ->get();

        // Agrupar por (ficha_id, seccion)
        $grupos = $items->groupBy(fn($i) => $i->ficha_tecnica_id . '::' . ($i->seccion ?? '__NULL__'));

        foreach ($grupos as $grupo) {
            // Solo actuar cuando hay más de 1 ítem en el grupo
            if ($grupo->count() < 2) continue;

            // Verificar que todos estén vinculados al MISMO proceso de esqueletería
            $procesos = $grupo->pluck('tarifa_proceso_id')->unique();
            if ($procesos->count() !== 1) continue;

            $sortedBySubtotal = $grupo->sortBy('subtotal')->values();
            $menor = $sortedBySubtotal->first();
            $mayor = $sortedBySubtotal->last();

            // Si el menor y el mayor tienen el mismo subtotal, no podemos distinguir — dejar como está
            if ((float) $menor->subtotal === (float) $mayor->subtotal) continue;

            // Re-vincular el ítem de MENOR subtotal a pintura
            $horas = $tarifaHoraLacador > 0
                ? round($menor->subtotal / $tarifaHoraLacador, 2)
                : round($menor->subtotal, 2);

            DB::table('ficha_tecnica_items')
                ->where('id', $menor->id)
                ->update([
                    'tarifa_proceso_id' => $tarifaPintura->id,
                    'precio_unitario'   => $tarifaHoraLacador,
                    'cantidad'          => $horas,
                    'unidad'            => 'Pintura',
                    'descripcion'       => $tarifaPintura->descripcion,
                ]);
        }
    }

    public function down(): void {}
};
