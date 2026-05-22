<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TarifaProcesoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tarifas_proceso')->truncate();

        // Tarifas basadas en jornales típicos de talleres colombianos de muebles.
        // Ebanista: ~$100.000/día | Tapicero: ~$90.000/día | Costurera: ~$70.000/día | Lacador: ~$95.000/día
        DB::table('tarifas_proceso')->insert([

            // ── Esqueletería / Ebanistería ─────────────────────────────────────────
            [
                'proceso'     => 'esqueleteria_silla',
                'descripcion' => 'Armar esqueleto de silla (auxiliar, comedor o barra)',
                'unidad'      => 'pieza',
                'tarifa'      => 22000,
                'aplica_a'    => 'sillas_aux,sillas_comedor,sillas_barra',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'esqueleteria_sofa',
                'descripcion' => 'Armar esqueleto de sofá por puesto',
                'unidad'      => 'puesto',
                'tarifa'      => 38000,
                'aplica_a'    => 'sofas,sofa_camas,sofas_modulares',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'esqueleteria_cama',
                'descripcion' => 'Armar base y cabecero de cama (sencilla, doble, queen o king)',
                'unidad'      => 'pieza',
                'tarifa'      => 65000,
                'aplica_a'    => 'camas',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'esqueleteria_mesa_comedor',
                'descripcion' => 'Fabricar estructura de mesa de comedor',
                'unidad'      => 'pieza',
                'tarifa'      => 95000,
                'aplica_a'    => 'comedores',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'esqueleteria_mesa_aux',
                'descripcion' => 'Fabricar estructura de mesa auxiliar / centro / TV / noche',
                'unidad'      => 'pieza',
                'tarifa'      => 45000,
                'aplica_a'    => 'mesas_aux,mesas_centro,mesas_tv,mesas_noche,escritorios',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'esqueleteria_cajonero',
                'descripcion' => 'Fabricar estructura de cajonero o cómoda',
                'unidad'      => 'pieza',
                'tarifa'      => 75000,
                'aplica_a'    => 'cajoneros',
                'created_at'  => now(), 'updated_at' => now(),
            ],

            // ── Tapizado ───────────────────────────────────────────────────────────
            [
                'proceso'     => 'tapizado',
                'descripcion' => 'Tapizado por metro cuadrado de superficie (asientos, respaldos, costados)',
                'unidad'      => 'm2',
                'tarifa'      => 20000,
                'aplica_a'    => 'general',
                'created_at'  => now(), 'updated_at' => now(),
            ],

            // ── Corte y costura ────────────────────────────────────────────────────
            [
                'proceso'     => 'corte_costura',
                'descripcion' => 'Corte y costura de forro de tela por metro lineal',
                'unidad'      => 'ml',
                'tarifa'      => 5000,
                'aplica_a'    => 'general',
                'created_at'  => now(), 'updated_at' => now(),
            ],

            // ── Laca y Pintura ─────────────────────────────────────────────────────
            [
                'proceso'     => 'laca',
                'descripcion' => 'Lacado por metro cuadrado de superficie de madera',
                'unidad'      => 'm2',
                'tarifa'      => 12000,
                'aplica_a'    => 'general',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'pintura',
                'descripcion' => 'Pintura por metro cuadrado de superficie',
                'unidad'      => 'm2',
                'tarifa'      => 9000,
                'aplica_a'    => 'general',
                'created_at'  => now(), 'updated_at' => now(),
            ],

            // ── Acabados finales ───────────────────────────────────────────────────
            [
                'proceso'     => 'acabados_silla',
                'descripcion' => 'Acabados finales por silla o pieza pequeña',
                'unidad'      => 'pieza',
                'tarifa'      => 8000,
                'aplica_a'    => 'sillas_aux,sillas_comedor,sillas_barra',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'acabados_sofa',
                'descripcion' => 'Acabados finales de sofá por puesto',
                'unidad'      => 'puesto',
                'tarifa'      => 15000,
                'aplica_a'    => 'sofas,sofa_camas,sofas_modulares',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'acabados_cama',
                'descripcion' => 'Acabados finales de cama completa',
                'unidad'      => 'pieza',
                'tarifa'      => 30000,
                'aplica_a'    => 'camas',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'acabados_mesa_comedor',
                'descripcion' => 'Acabados finales de mesa de comedor',
                'unidad'      => 'pieza',
                'tarifa'      => 20000,
                'aplica_a'    => 'comedores',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'acabados_mesa_aux',
                'descripcion' => 'Acabados finales de mesa auxiliar / centro / TV / noche',
                'unidad'      => 'pieza',
                'tarifa'      => 12000,
                'aplica_a'    => 'mesas_aux,mesas_centro,mesas_tv,mesas_noche,escritorios',
                'created_at'  => now(), 'updated_at' => now(),
            ],
            [
                'proceso'     => 'acabados_cajonero',
                'descripcion' => 'Acabados finales de cajonero o cómoda',
                'unidad'      => 'pieza',
                'tarifa'      => 22000,
                'aplica_a'    => 'cajoneros',
                'created_at'  => now(), 'updated_at' => now(),
            ],
        ]);
    }
}
