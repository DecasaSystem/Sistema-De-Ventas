<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar divisor de asesores a metas_tienda
        if (Schema::hasTable('metas_tienda') && ! Schema::hasColumn('metas_tienda', 'divisor_asesores')) {
            Schema::table('metas_tienda', function (Blueprint $table) {
                $table->unsignedTinyInteger('divisor_asesores')->default(1)->after('meta');
            });
        }

        // Seed metas julio 2026
        $mes = '2026-07';
        $configs = [
            ['like' => '%Eden%',      'meta' => 40000000, 'divisor' => 2],
            ['like' => '%Circunvalar%','meta' => 40000000, 'divisor' => 1],
            ['like' => '%Unicentro%',  'meta' => 40000000, 'divisor' => 1],
            ['like' => '%Norte%',      'meta' => 40000000, 'divisor' => 3],
        ];

        foreach ($configs as $cfg) {
            $tienda = DB::table('tiendas')->where('nombre', 'LIKE', $cfg['like'])->first();
            if (! $tienda) continue;

            DB::table('metas_tienda')->updateOrInsert(
                ['tienda_id' => $tienda->id, 'mes' => $mes],
                [
                    'meta'             => $cfg['meta'],
                    'divisor_asesores' => $cfg['divisor'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('metas_tienda', 'divisor_asesores')) {
            Schema::table('metas_tienda', function (Blueprint $table) {
                $table->dropColumn('divisor_asesores');
            });
        }
    }
};
