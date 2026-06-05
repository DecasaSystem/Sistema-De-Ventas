<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->boolean('es_fabrica')->default(false)->after('activa');
        });

        DB::table('tiendas')->insertOrIgnore([
            'nombre'     => 'Fábrica',
            'ciudad'     => 'Fábrica',
            'activa'     => true,
            'es_fabrica' => true,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->dropColumn('es_fabrica');
        });
    }
};
