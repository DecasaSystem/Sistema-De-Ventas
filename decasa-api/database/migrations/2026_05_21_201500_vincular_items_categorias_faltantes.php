<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Vincula los ítems de mano de obra que no se pudieron enlazar en las pasadas anteriores
// por pertenecer a categorías no contempladas (BIFET, BUTACOS, CAJONERAS, COMBO PLATINO,
// CONSOLAS Y MARCO ESPEJO, MATERAS, MUEBLE BAR, VITRINAS, ZAPATEROS).
// Usa el campo `unidad` original (EBANISTERIA, PINTURA, TAPIZADA, etc.) para determinar el proceso.
return new class extends Migration
{
    public function up(): void
    {
        $tarifas  = DB::table('tarifas_proceso')->get()->keyBy('proceso');
        $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');

        $categoriaFamilia = [
            'BIFET'                   => 'cajonero',
            'BUTACOS'                 => 'silla',
            'CAJONERAS'               => 'cajonero',
            'COMBO PLATINO'           => 'cama',
            'CONSOLAS Y MARCO ESPEJO' => 'mesa_aux',
            'MATERAS'                 => 'mesa_aux',
            'MUEBLE BAR'              => 'cajonero',
            'VITRINAS'                => 'cajonero',
            'ZAPATEROS'               => 'cajonero',
        ];

        $items = DB::table('ficha_tecnica_items as i')
            ->join('fichas_tecnicas as f', 'f.id', '=', 'i.ficha_tecnica_id')
            ->where('i.es_mano_obra', true)
            ->whereNull('i.tarifa_proceso_id')
            ->whereIn('f.categoria', array_keys($categoriaFamilia))
            ->select('i.id', 'i.subtotal', 'i.unidad as tipo_proceso', 'f.categoria')
            ->get();

        foreach ($items as $item) {
            $familia = $categoriaFamilia[$item->categoria] ?? null;
            if (!$familia) continue;

            $proceso = $this->resolverProceso($item->tipo_proceso, $familia);
            if (!$proceso || !isset($tarifas[$proceso])) continue;

            $tarifa = $tarifas[$proceso];

            $salario    = $salarios->get($tarifa->cargo);
            $tarifaHora = $salario && $salario->dias_laborales_mes > 0
                ? round($salario->salario_mensual / $salario->dias_laborales_mes / 8, 2)
                : 0;

            if ($tarifaHora <= 0) continue;

            $horas = round($item->subtotal / $tarifaHora, 2);

            DB::table('ficha_tecnica_items')
                ->where('id', $item->id)
                ->update([
                    'tarifa_proceso_id' => $tarifa->id,
                    'precio_unitario'   => $tarifaHora,
                    'cantidad'          => $horas,
                    'unidad'            => 'horas',
                    'descripcion'       => $tarifa->descripcion,
                    // subtotal se conserva
                ]);
        }
    }

    public function down(): void {}

    private function resolverProceso(string $tipo, string $familia): ?string
    {
        $t = strtoupper(trim($tipo));

        return match(true) {
            in_array($t, ['EBANISTERIA', 'CARPINTERIA', 'ESQUELETO']) => "esqueleteria_{$familia}",
            $t === 'TAPIZADA' || $t === 'TAPICERIA'                  => 'tapizado',
            $t === 'CORTE Y COSTURA'                                  => 'corte_costura',
            $t === 'PINTURA'                                           => 'pintura',
            str_contains($t, 'LACA')                                  => 'laca',
            str_contains($t, 'ACABAD')                                => "acabados_{$familia}",
            default                                                    => null,
        };
    }
};
