<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tarifas  = DB::table('tarifas_proceso')->get()->keyBy('proceso');
        $fichas   = DB::table('fichas_tecnicas')->get();

        foreach ($fichas as $ficha) {
            $familia = $this->detectarFamilia($ficha->categoria);

            $items = DB::table('ficha_tecnica_items')
                ->where('ficha_tecnica_id', $ficha->id)
                ->where('es_mano_obra', true)
                ->get();

            foreach ($items as $item) {
                $proceso = $this->matchProceso($item->seccion, $item->descripcion, $familia);
                if (!$proceso || !isset($tarifas[$proceso])) continue;

                $tarifa = $tarifas[$proceso];
                if ($tarifa->tarifa <= 0) continue;

                // Ajustar cantidad para que subtotal quede igual al original
                $nuevaCantidad = round($item->subtotal / $tarifa->tarifa, 4);

                DB::table('ficha_tecnica_items')
                    ->where('id', $item->id)
                    ->update([
                        'tarifa_proceso_id' => $tarifa->id,
                        'precio_unitario'   => $tarifa->tarifa,
                        'cantidad'          => $nuevaCantidad,
                        // subtotal no cambia — cantidad × precio ≈ subtotal original
                    ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('ficha_tecnica_items')
            ->whereNotNull('tarifa_proceso_id')
            ->update(['tarifa_proceso_id' => null]);
    }

    private function detectarFamilia(?string $categoria): ?string
    {
        if (!$categoria) return null;
        return match(true) {
            str_contains($categoria, 'silla')                                              => 'silla',
            in_array($categoria, ['sofas', 'sofa_camas', 'sofas_modulares'])               => 'sofa',
            str_contains($categoria, 'cama')                                               => 'cama',
            str_contains($categoria, 'comedor')                                            => 'mesa_comedor',
            in_array($categoria, ['mesas_aux','mesas_centro','mesas_tv','mesas_noche','escritorios']) => 'mesa_aux',
            in_array($categoria, ['cajoneros'])                                            => 'cajonero',
            default                                                                        => null,
        };
    }

    private function matchProceso(?string $seccion, ?string $descripcion, ?string $familia): ?string
    {
        $s = strtoupper($seccion     ?? '');
        $d = strtoupper($descripcion ?? '');

        // Por sección (nombre exacto del Excel)
        if (str_contains($s, 'ESQUELET') || str_contains($s, 'CARPINT') || str_contains($s, 'EBANIS')) {
            return $familia ? "esqueleteria_{$familia}" : null;
        }
        if (str_contains($s, 'TAPIC'))                              return 'tapizado';
        if (str_contains($s, 'CORTE') || str_contains($s, 'COSTURA')) return 'corte_costura';
        if (str_contains($s, 'LACA'))                               return 'laca';
        if (str_contains($s, 'PINT'))                               return 'pintura';
        if (str_contains($s, 'ACABADO'))                            return $familia ? "acabados_{$familia}" : null;

        // Por descripción del ítem (fallback)
        if (str_contains($d, 'TAPIZ'))                              return 'tapizado';
        if (str_contains($d, 'COSTUR') || str_contains($d, 'CORTE')) return 'corte_costura';
        if (str_contains($d, 'LACA')  || str_contains($d, 'LAQU'))  return 'laca';
        if (str_contains($d, 'PINT'))                               return 'pintura';
        if (str_contains($d, 'ACABAD'))                             return $familia ? "acabados_{$familia}" : null;
        if (str_contains($d, 'ESQUEL') || str_contains($d, 'CARPINT') || str_contains($d, 'EBAN')) {
            return $familia ? "esqueleteria_{$familia}" : null;
        }

        return null;
    }
};
