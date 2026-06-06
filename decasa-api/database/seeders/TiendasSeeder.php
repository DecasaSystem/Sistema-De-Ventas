<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TiendasSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('tiendas')->count() > 0) return;

        DB::table('tiendas')->insert([
            ['nombre' => 'Decasa Bolívar',         'ciudad' => 'Armenia', 'direccion' => 'Avenida Bolívar # 16 N 26, Armenia, Quindío',   'telefono' => '321 777 0621', 'activa' => true, 'es_fabrica' => true],
            ['nombre' => 'Decasa Vía El Edén',     'ciudad' => 'Armenia', 'direccion' => 'Km 2 vía El Edén, Armenia, Quindío',             'telefono' => '314 604 3527', 'activa' => true, 'es_fabrica' => false],
            ['nombre' => 'Decasa Vía Jardines',    'ciudad' => 'Armenia', 'direccion' => 'Km 1 vía Jardines, Armenia, Quindío',            'telefono' => '320 577 8176', 'activa' => true, 'es_fabrica' => false],
            ['nombre' => 'Decasa Unicentro Pereira','ciudad' => 'Pereira', 'direccion' => 'CC Unicentro, Cra. 14 #11-93, Pereira, Risaralda','telefono' => null,          'activa' => true, 'es_fabrica' => false],
            ['nombre' => 'Decasa Circunvalar',     'ciudad' => 'Pereira', 'direccion' => 'Cra. 14 #11 - 93. Pereira, Risaralda',           'telefono' => '324 611 3825', 'activa' => true, 'es_fabrica' => false],
        ]);
    }
}
