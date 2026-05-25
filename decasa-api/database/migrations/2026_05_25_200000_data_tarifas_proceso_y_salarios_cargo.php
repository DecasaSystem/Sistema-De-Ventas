<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('tarifas_proceso')->count() > 0) return;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('tarifas_proceso')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $now = now();

        DB::table('tarifas_proceso')->insert([
            ['proceso' => 'esqueleteria_silla',        'descripcion' => 'Armar esqueleto de silla (auxiliar, comedor o barra)',              'unidad' => 'pieza',  'tarifa' => 22000, 'aplica_a' => 'sillas_aux,sillas_comedor,sillas_barra',                    'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'esqueleteria_sofa',         'descripcion' => 'Armar esqueleto de sofá por puesto',                               'unidad' => 'puesto', 'tarifa' => 38000, 'aplica_a' => 'sofas,sofa_camas,sofas_modulares',                          'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'esqueleteria_cama',         'descripcion' => 'Armar base y cabecero de cama (sencilla, doble, queen o king)',     'unidad' => 'pieza',  'tarifa' => 65000, 'aplica_a' => 'camas',                                                     'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'esqueleteria_mesa_comedor', 'descripcion' => 'Fabricar estructura de mesa de comedor',                           'unidad' => 'pieza',  'tarifa' => 95000, 'aplica_a' => 'comedores',                                                  'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'esqueleteria_mesa_aux',     'descripcion' => 'Fabricar estructura de mesa auxiliar / centro / TV / noche',       'unidad' => 'pieza',  'tarifa' => 45000, 'aplica_a' => 'mesas_aux,mesas_centro,mesas_tv,mesas_noche,escritorios',   'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'esqueleteria_cajonero',     'descripcion' => 'Fabricar estructura de cajonero o cómoda',                         'unidad' => 'pieza',  'tarifa' => 75000, 'aplica_a' => 'cajoneros',                                                  'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'tapizado',                  'descripcion' => 'Tapizado por metro cuadrado de superficie (asientos, respaldos, costados)', 'unidad' => 'm2',   'tarifa' => 20000, 'aplica_a' => 'general',                                          'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'corte_costura',             'descripcion' => 'Corte y costura de forro de tela por metro lineal',                'unidad' => 'ml',     'tarifa' =>  5000, 'aplica_a' => 'general',                                                    'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'laca',                      'descripcion' => 'Lacado por metro cuadrado de superficie de madera',                'unidad' => 'm2',     'tarifa' => 12000, 'aplica_a' => 'general',                                                    'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'pintura',                   'descripcion' => 'Pintura por metro cuadrado de superficie',                         'unidad' => 'm2',     'tarifa' =>  9000, 'aplica_a' => 'general',                                                    'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'acabados_silla',            'descripcion' => 'Acabados finales por silla o pieza pequeña',                       'unidad' => 'pieza',  'tarifa' =>  8000, 'aplica_a' => 'sillas_aux,sillas_comedor,sillas_barra',                    'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'acabados_sofa',             'descripcion' => 'Acabados finales de sofá por puesto',                              'unidad' => 'puesto', 'tarifa' => 15000, 'aplica_a' => 'sofas,sofa_camas,sofas_modulares',                          'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'acabados_cama',             'descripcion' => 'Acabados finales de cama completa',                                'unidad' => 'pieza',  'tarifa' => 30000, 'aplica_a' => 'camas',                                                     'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'acabados_mesa_comedor',     'descripcion' => 'Acabados finales de mesa de comedor',                              'unidad' => 'pieza',  'tarifa' => 20000, 'aplica_a' => 'comedores',                                                  'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'acabados_mesa_aux',         'descripcion' => 'Acabados finales de mesa auxiliar / centro / TV / noche',          'unidad' => 'pieza',  'tarifa' => 12000, 'aplica_a' => 'mesas_aux,mesas_centro,mesas_tv,mesas_noche,escritorios',   'created_at' => $now, 'updated_at' => $now],
            ['proceso' => 'acabados_cajonero',         'descripcion' => 'Acabados finales de cajonero o cómoda',                            'unidad' => 'pieza',  'tarifa' => 22000, 'aplica_a' => 'cajoneros',                                                  'created_at' => $now, 'updated_at' => $now],
        ]);

        if (DB::table('salarios_cargo')->count() === 0) {
            DB::table('salarios_cargo')->insert([
                ['cargo' => 'carpintero', 'descripcion' => 'Ebanista / carpintero — arma esqueletos y estructuras de madera', 'salario_mensual' => 3000000, 'dias_laborales_mes' => 26, 'created_at' => $now, 'updated_at' => $now],
                ['cargo' => 'tapicero',   'descripcion' => 'Tapicero — aplica espuma, relleno y tela a los muebles',          'salario_mensual' => 3000000, 'dias_laborales_mes' => 26, 'created_at' => $now, 'updated_at' => $now],
                ['cargo' => 'costurera',  'descripcion' => 'Costurera — corta y cose forros de tela',                         'salario_mensual' => 2500000, 'dias_laborales_mes' => 26, 'created_at' => $now, 'updated_at' => $now],
                ['cargo' => 'lacador',    'descripcion' => 'Lacador / pintor — aplica laca, pintura y acabados a la madera',  'salario_mensual' => 3000000, 'dias_laborales_mes' => 26, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        $updates = [
            ['proceso' => 'esqueleteria_silla',        'cargo' => 'carpintero', 'dias_por_unidad' => 0.200],
            ['proceso' => 'esqueleteria_sofa',         'cargo' => 'carpintero', 'dias_por_unidad' => 0.500],
            ['proceso' => 'esqueleteria_cama',         'cargo' => 'carpintero', 'dias_por_unidad' => 1.000],
            ['proceso' => 'esqueleteria_mesa_comedor', 'cargo' => 'carpintero', 'dias_por_unidad' => 1.500],
            ['proceso' => 'esqueleteria_mesa_aux',     'cargo' => 'carpintero', 'dias_por_unidad' => 0.500],
            ['proceso' => 'esqueleteria_cajonero',     'cargo' => 'carpintero', 'dias_por_unidad' => 1.000],
            ['proceso' => 'tapizado',                  'cargo' => 'tapicero',   'dias_por_unidad' => 0.250],
            ['proceso' => 'corte_costura',             'cargo' => 'costurera',  'dias_por_unidad' => 0.050],
            ['proceso' => 'laca',                      'cargo' => 'lacador',    'dias_por_unidad' => 0.125],
            ['proceso' => 'pintura',                   'cargo' => 'lacador',    'dias_por_unidad' => 0.100],
            ['proceso' => 'acabados_silla',            'cargo' => 'carpintero', 'dias_por_unidad' => 0.080],
            ['proceso' => 'acabados_sofa',             'cargo' => 'tapicero',   'dias_por_unidad' => 0.150],
            ['proceso' => 'acabados_cama',             'cargo' => 'carpintero', 'dias_por_unidad' => 0.250],
            ['proceso' => 'acabados_mesa_comedor',     'cargo' => 'carpintero', 'dias_por_unidad' => 0.200],
            ['proceso' => 'acabados_mesa_aux',         'cargo' => 'carpintero', 'dias_por_unidad' => 0.120],
            ['proceso' => 'acabados_cajonero',         'cargo' => 'carpintero', 'dias_por_unidad' => 0.220],
        ];

        foreach ($updates as $u) {
            DB::table('tarifas_proceso')->where('proceso', $u['proceso'])->update([
                'cargo'           => $u['cargo'],
                'dias_por_unidad' => $u['dias_por_unidad'],
            ]);
        }

        $salarios = DB::table('salarios_cargo')->get()->keyBy('cargo');
        foreach (DB::table('tarifas_proceso')->whereNotNull('cargo')->whereNotNull('dias_por_unidad')->get() as $p) {
            $salario = $salarios->get($p->cargo);
            if (!$salario) continue;
            DB::table('tarifas_proceso')->where('id', $p->id)->update([
                'tarifa' => round(($salario->salario_mensual / $salario->dias_laborales_mes) * $p->dias_por_unidad),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('tarifas_proceso')->truncate();
        DB::table('salarios_cargo')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
