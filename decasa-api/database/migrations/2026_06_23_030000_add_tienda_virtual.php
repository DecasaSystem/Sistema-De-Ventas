<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('tiendas')->where('nombre', 'Tienda Virtual')->exists();
        if ($existe) return;

        DB::table('tiendas')->insert([
            'nombre'     => 'Tienda Virtual',
            'ciudad'     => null,
            'direccion'  => null,
            'telefono'   => null,
            'activa'     => true,
            'es_fabrica' => false,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('tiendas')->where('nombre', 'Tienda Virtual')->delete();
    }
};
