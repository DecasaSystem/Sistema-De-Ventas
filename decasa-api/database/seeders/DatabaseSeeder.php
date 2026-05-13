<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TiendasSeeder::class,
            UsuariosSeeder::class,
            ProductosSeeder::class,
            InventarioSeeder::class,
        ]);
    }
}