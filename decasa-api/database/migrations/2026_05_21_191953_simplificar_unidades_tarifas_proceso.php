<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cambiar tapizado, laca, pintura y corte_costura de unidades de área/longitud
        // a "pieza" para que el cálculo sea uniforme: horas × tarifa_horaria = costo.
        $updates = [
            [
                'proceso'         => 'tapizado',
                'descripcion'     => 'Tapizado de una pieza de mueble (asientos, respaldos, costados)',
                'unidad'          => 'pieza',
                'dias_por_unidad' => 0.25,    // ~2 h por pieza típica
            ],
            [
                'proceso'         => 'corte_costura',
                'descripcion'     => 'Corte y costura del forro de tela por pieza de mueble',
                'unidad'          => 'pieza',
                'dias_por_unidad' => 0.25,    // ~2 h por pieza
            ],
            [
                'proceso'         => 'laca',
                'descripcion'     => 'Lacado de una pieza de mueble de madera',
                'unidad'          => 'pieza',
                'dias_por_unidad' => 0.375,   // ~3 h por pieza
            ],
            [
                'proceso'         => 'pintura',
                'descripcion'     => 'Pintura de una pieza de mueble',
                'unidad'          => 'pieza',
                'dias_por_unidad' => 0.3125,  // ~2.5 h por pieza
            ],
        ];

        $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');

        foreach ($updates as $u) {
            $proceso = DB::table('tarifas_proceso')->where('proceso', $u['proceso'])->first();
            if (!$proceso) continue;

            $salario     = $salarios->get($proceso->cargo);
            $nuevaTarifa = $salario
                ? round(($salario->salario_mensual / $salario->dias_laborales_mes) * $u['dias_por_unidad'], 0)
                : $proceso->tarifa;

            DB::table('tarifas_proceso')
                ->where('proceso', $u['proceso'])
                ->update([
                    'descripcion'     => $u['descripcion'],
                    'unidad'          => $u['unidad'],
                    'dias_por_unidad' => $u['dias_por_unidad'],
                    'tarifa'          => $nuevaTarifa,
                    'updated_at'      => now(),
                ]);
        }
    }

    public function down(): void
    {
        $reverts = [
            ['proceso' => 'tapizado',      'descripcion' => 'Tapizado por metro cuadrado de superficie (asientos, respaldos, costados)', 'unidad' => 'm2',  'dias_por_unidad' => 0.250],
            ['proceso' => 'corte_costura', 'descripcion' => 'Corte y costura de forro de tela por metro lineal',                         'unidad' => 'ml',  'dias_por_unidad' => 0.050],
            ['proceso' => 'laca',          'descripcion' => 'Lacado por metro cuadrado de superficie de madera',                         'unidad' => 'm2',  'dias_por_unidad' => 0.125],
            ['proceso' => 'pintura',       'descripcion' => 'Pintura por metro cuadrado de superficie',                                  'unidad' => 'm2',  'dias_por_unidad' => 0.100],
        ];

        $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');

        foreach ($reverts as $u) {
            $proceso = DB::table('tarifas_proceso')->where('proceso', $u['proceso'])->first();
            if (!$proceso) continue;

            $salario     = $salarios->get($proceso->cargo);
            $nuevaTarifa = $salario
                ? round(($salario->salario_mensual / $salario->dias_laborales_mes) * $u['dias_por_unidad'], 0)
                : $proceso->tarifa;

            DB::table('tarifas_proceso')
                ->where('proceso', $u['proceso'])
                ->update([
                    'descripcion'     => $u['descripcion'],
                    'unidad'          => $u['unidad'],
                    'dias_por_unidad' => $u['dias_por_unidad'],
                    'tarifa'          => $nuevaTarifa,
                    'updated_at'      => now(),
                ]);
        }
    }
};
