<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalarioCargoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('salarios_cargo')->truncate();

        // Salarios mensuales reales del taller — ajustar según nómina actual
        DB::table('salarios_cargo')->insert([
            [
                'cargo'             => 'carpintero',
                'descripcion'       => 'Ebanista / carpintero — arma esqueletos y estructuras de madera',
                'salario_mensual'   => 3000000,
                'dias_laborales_mes'=> 26,
                'created_at'        => now(), 'updated_at' => now(),
            ],
            [
                'cargo'             => 'tapicero',
                'descripcion'       => 'Tapicero — aplica espuma, relleno y tela a los muebles',
                'salario_mensual'   => 3000000,
                'dias_laborales_mes'=> 26,
                'created_at'        => now(), 'updated_at' => now(),
            ],
            [
                'cargo'             => 'costurera',
                'descripcion'       => 'Costurera — corta y cose forros de tela',
                'salario_mensual'   => 2500000,
                'dias_laborales_mes'=> 26,
                'created_at'        => now(), 'updated_at' => now(),
            ],
            [
                'cargo'             => 'lacador',
                'descripcion'       => 'Lacador / pintor — aplica laca, pintura y acabados a la madera',
                'salario_mensual'   => 3000000,
                'dias_laborales_mes'=> 26,
                'created_at'        => now(), 'updated_at' => now(),
            ],
        ]);

        // Actualizar tarifas_proceso con cargo y días por unidad
        // tarifa se recalcula automáticamente: salario_mensual / dias_laborales × dias_por_unidad
        $updates = [
            // ── Esqueletería / Carpintería ──────────────────────────────────────
            // Un carpintero hace ~5 sillas/día → 0.20 días/silla
            ['proceso' => 'esqueleteria_silla',        'cargo' => 'carpintero', 'dias_por_unidad' => 0.200],
            // Un carpintero hace ~1 sofá de 1 puesto cada 0.5 días
            ['proceso' => 'esqueleteria_sofa',         'cargo' => 'carpintero', 'dias_por_unidad' => 0.500],
            // Una cama completa (base + cabecero) le toma 1 día
            ['proceso' => 'esqueleteria_cama',         'cargo' => 'carpintero', 'dias_por_unidad' => 1.000],
            // Mesa de comedor: 1.5 días de trabajo
            ['proceso' => 'esqueleteria_mesa_comedor', 'cargo' => 'carpintero', 'dias_por_unidad' => 1.500],
            // Mesa auxiliar / noche / TV: 0.5 días
            ['proceso' => 'esqueleteria_mesa_aux',     'cargo' => 'carpintero', 'dias_por_unidad' => 0.500],
            // Cajonero: 1 día
            ['proceso' => 'esqueleteria_cajonero',     'cargo' => 'carpintero', 'dias_por_unidad' => 1.000],

            // ── Tapizado ────────────────────────────────────────────────────────
            // Tapicero hace ~6 sillas/día → 0.167 días/silla pero acá la unidad es m²
            // 1 m² de tapizado le toma ~0.25 días al tapicero
            ['proceso' => 'tapizado',                  'cargo' => 'tapicero',   'dias_por_unidad' => 0.250],

            // ── Corte y costura ──────────────────────────────────────────────
            // 1 metro lineal de costura: ~0.05 días de costurera (hace ~20 ml/día)
            ['proceso' => 'corte_costura',             'cargo' => 'costurera',  'dias_por_unidad' => 0.050],

            // ── Laca y Pintura ───────────────────────────────────────────────
            // 1 m² lacado: ~0.125 días de lacador (hace ~8 m²/día)
            ['proceso' => 'laca',                      'cargo' => 'lacador',    'dias_por_unidad' => 0.125],
            ['proceso' => 'pintura',                   'cargo' => 'lacador',    'dias_por_unidad' => 0.100],

            // ── Acabados ─────────────────────────────────────────────────────
            // Acabados: trabajo mixto de carpintero, tarifa equivalente
            ['proceso' => 'acabados_silla',            'cargo' => 'carpintero', 'dias_por_unidad' => 0.080],
            ['proceso' => 'acabados_sofa',             'cargo' => 'tapicero',   'dias_por_unidad' => 0.150],
            ['proceso' => 'acabados_cama',             'cargo' => 'carpintero', 'dias_por_unidad' => 0.250],
            ['proceso' => 'acabados_mesa_comedor',     'cargo' => 'carpintero', 'dias_por_unidad' => 0.200],
            ['proceso' => 'acabados_mesa_aux',         'cargo' => 'carpintero', 'dias_por_unidad' => 0.120],
            ['proceso' => 'acabados_cajonero',         'cargo' => 'carpintero', 'dias_por_unidad' => 0.220],
        ];

        foreach ($updates as $u) {
            DB::table('tarifas_proceso')
                ->where('proceso', $u['proceso'])
                ->update([
                    'cargo'           => $u['cargo'],
                    'dias_por_unidad' => $u['dias_por_unidad'],
                ]);
        }

        // Recalcular tarifas automáticamente desde salarios
        $this->recalcularTarifas();
    }

    private function recalcularTarifas(): void
    {
        $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');

        $procesos = DB::table('tarifas_proceso')
            ->whereNotNull('cargo')
            ->whereNotNull('dias_por_unidad')
            ->get();

        foreach ($procesos as $p) {
            $salario = $salarios->get($p->cargo);
            if (!$salario) continue;

            $tarifaDiaria = $salario->salario_mensual / $salario->dias_laborales_mes;
            $nuevaTarifa  = round($tarifaDiaria * $p->dias_por_unidad, 0);

            DB::table('tarifas_proceso')
                ->where('id', $p->id)
                ->update(['tarifa' => $nuevaTarifa]);
        }
    }
}
