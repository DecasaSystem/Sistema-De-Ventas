<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tarifas = DB::table('tarifas_proceso')->get()->keyBy('proceso');
        $fichas  = DB::table('fichas_tecnicas')->get();

        foreach ($fichas as $ficha) {
            $familia = $this->detectarFamilia($ficha->categoria);

            // Solo items de mano de obra aún no vinculados
            $items = DB::table('ficha_tecnica_items')
                ->where('ficha_tecnica_id', $ficha->id)
                ->where('es_mano_obra', true)
                ->whereNull('tarifa_proceso_id')
                ->get();

            foreach ($items as $item) {
                $proceso = $this->matchProceso($item->seccion, $item->descripcion, $familia);
                if (!$proceso || !isset($tarifas[$proceso])) continue;

                $tarifa = $tarifas[$proceso];
                if ($tarifa->tarifa <= 0) continue;

                $nuevaCantidad = round($item->subtotal / $tarifa->tarifa, 4);

                DB::table('ficha_tecnica_items')
                    ->where('id', $item->id)
                    ->update([
                        'tarifa_proceso_id' => $tarifa->id,
                        'precio_unitario'   => $tarifa->tarifa,
                        'cantidad'          => $nuevaCantidad,
                    ]);
            }
        }
    }

    public function down(): void {}

    private function detectarFamilia(?string $categoria): ?string
    {
        if (!$categoria) return null;
        $c = strtoupper($categoria);

        return match(true) {
            // Sillas (comedor, barra, auxiliar, sala — las de sala son sillones tipo sofá pero con marco silla)
            str_contains($c, 'SILLA DE COMEDOR') || str_contains($c, 'SILLAS DE COMEDOR') => 'silla',
            str_contains($c, 'SILLA DE SALA')    || str_contains($c, 'SILLAS DE SALA')    => 'sofa',
            str_contains($c, 'SILLA DE BARRA')   || str_contains($c, 'SILLAS DE BARRA')   => 'silla',
            str_contains($c, 'SILLA AUX')        || str_contains($c, 'SILLAS AUX')        => 'silla',
            str_contains($c, 'SILLA')                                                      => 'silla',

            // Sofás y modulares
            str_contains($c, 'MODULAR')                                                    => 'sofa',
            str_contains($c, 'SOFA CAMA')  || str_contains($c, 'SOFACAMA')                => 'sofa',
            str_contains($c, 'RECLINOMATIC')                                               => 'sofa',
            str_contains($c, 'SOFA')       || str_contains($c, 'SOFÁ')                    => 'sofa',

            // Camas
            str_contains($c, 'CAMA BAUL')  || str_contains($c, 'CAMAS BAUL')              => 'cama',
            str_contains($c, 'CAMILLA')                                                    => 'cama',
            str_contains($c, 'CAMA')       || str_contains($c, 'ALCOBA')                  => 'cama',

            // Comedores
            str_contains($c, 'COMEDOR')                                                    => 'mesa_comedor',

            // Mesas auxiliares y variantes
            str_contains($c, 'ESCRITORIO')                                                 => 'mesa_aux',
            str_contains($c, 'MESA NOCHE') || str_contains($c, 'NOCHERO')                 => 'mesa_aux',
            str_contains($c, 'MESA')                                                       => 'mesa_aux',

            // Cajoneros
            str_contains($c, 'CAJONERO') || str_contains($c, 'CÓMODA') || str_contains($c, 'COMODA') || str_contains($c, 'GAVETA') => 'cajonero',

            default => null,
        };
    }

    private function matchProceso(?string $seccion, ?string $descripcion, ?string $familia): ?string
    {
        $s = strtoupper($seccion     ?? '');
        $d = strtoupper($descripcion ?? '');

        // Por sección
        if (str_contains($s, 'ESQUELET') || str_contains($s, 'CARPINT') || str_contains($s, 'EBANIS')) {
            return $familia ? "esqueleteria_{$familia}" : null;
        }
        if (str_contains($s, 'TAPIC'))                                 return 'tapizado';
        if (str_contains($s, 'CORTE') || str_contains($s, 'COSTURA')) return 'corte_costura';
        if (str_contains($s, 'LACA'))                                  return 'laca';
        if (str_contains($s, 'PINT'))                                  return 'pintura';
        if (str_contains($s, 'ACABADO'))                               return $familia ? "acabados_{$familia}" : null;

        // Por descripción
        if (str_contains($d, 'TAPIZ'))                                 return 'tapizado';
        if (str_contains($d, 'COSTUR') || str_contains($d, 'CORTE'))  return 'corte_costura';
        if (str_contains($d, 'LACA')   || str_contains($d, 'LAQU'))   return 'laca';
        if (str_contains($d, 'PINT'))                                  return 'pintura';
        if (str_contains($d, 'ACABAD'))                                return $familia ? "acabados_{$familia}" : null;
        if (str_contains($d, 'ESQUEL') || str_contains($d, 'CARPINT') || str_contains($d, 'EBAN')) {
            return $familia ? "esqueleteria_{$familia}" : null;
        }

        return null;
    }
};
