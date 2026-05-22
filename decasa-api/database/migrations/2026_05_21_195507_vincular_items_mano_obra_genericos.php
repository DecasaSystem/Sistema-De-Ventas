<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Vincula los ítems genéricos "MANO DE OBRA" (sin sección) a la tarifa principal
// de la familia del producto, usando esqueleteria_* como referencia de costo.
return new class extends Migration
{
    public function up(): void
    {
        $tarifas = DB::table('tarifas_proceso')->get()->keyBy('proceso');

        // Mapa categoria → proceso principal de referencia
        $mapaFamilia = [
            'silla'        => 'esqueleteria_silla',
            'sofa'         => 'esqueleteria_sofa',
            'cama'         => 'esqueleteria_cama',
            'mesa_comedor' => 'esqueleteria_mesa_comedor',
            'mesa_aux'     => 'esqueleteria_mesa_aux',
            'cajonero'     => 'esqueleteria_cajonero',
        ];

        $fichas = DB::table('fichas_tecnicas')->get();

        foreach ($fichas as $ficha) {
            $familia = $this->detectarFamilia($ficha->categoria);
            if (!$familia || !isset($mapaFamilia[$familia])) continue;

            $proceso = $mapaFamilia[$familia];
            if (!isset($tarifas[$proceso])) continue;

            $tarifa = $tarifas[$proceso];
            if ($tarifa->tarifa <= 0) continue;

            $items = DB::table('ficha_tecnica_items')
                ->where('ficha_tecnica_id', $ficha->id)
                ->where('es_mano_obra', true)
                ->whereNull('tarifa_proceso_id')
                ->get();

            foreach ($items as $item) {
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
            str_contains($c, 'SILLA DE COMEDOR') || str_contains($c, 'SILLAS DE COMEDOR') => 'silla',
            str_contains($c, 'SILLA DE SALA')    || str_contains($c, 'SILLAS DE SALA')    => 'sofa',
            str_contains($c, 'SILLA DE BARRA')   || str_contains($c, 'SILLAS DE BARRA')   => 'silla',
            str_contains($c, 'SILLA')                                                      => 'silla',
            str_contains($c, 'MODULAR')                                                    => 'sofa',
            str_contains($c, 'SOFA CAMA') || str_contains($c, 'SOFACAMA')                 => 'sofa',
            str_contains($c, 'RECLINOMATIC')                                               => 'sofa',
            str_contains($c, 'SOFA') || str_contains($c, 'SOFÁ')                          => 'sofa',
            str_contains($c, 'CAMA') || str_contains($c, 'ALCOBA') || str_contains($c, 'CAMILLA') => 'cama',
            str_contains($c, 'COMEDOR')                                                    => 'mesa_comedor',
            str_contains($c, 'ESCRITORIO')                                                 => 'mesa_aux',
            str_contains($c, 'MESA NOCHE') || str_contains($c, 'NOCHERO')                 => 'mesa_aux',
            str_contains($c, 'MESA')                                                       => 'mesa_aux',
            str_contains($c, 'CAJONERO') || str_contains($c, 'COMODA') || str_contains($c, 'GAVETA') => 'cajonero',
            default => null,
        };
    }
};
