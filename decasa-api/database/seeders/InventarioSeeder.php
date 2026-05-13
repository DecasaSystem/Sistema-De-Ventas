<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventarioSeeder extends Seeder
{
    public function run(): void
    {
        $tiendas   = DB::table('tiendas')->pluck('id');
        $productos = DB::table('productos')->pluck('id');

        $rows = [];
        foreach ($tiendas as $tid) {
            foreach ($productos as $pid) {
                $rows[] = [
                    'producto_id'         => $pid,
                    'tienda_id'           => $tid,
                    'cantidad_disponible' => 0,
                    'cantidad_reservada'  => 0,
                    'stock_minimo'        => 1,
                ];
            }
        }

        // Insert in chunks to avoid hitting MySQL's max_allowed_packet
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inventario')->insertOrIgnore($chunk);
        }
    }
}