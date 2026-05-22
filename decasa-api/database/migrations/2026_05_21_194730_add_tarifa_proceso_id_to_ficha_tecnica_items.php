<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ficha_tecnica_items', function (Blueprint $table) {
            $table->foreignId('tarifa_proceso_id')
                  ->nullable()
                  ->after('es_mano_obra')
                  ->constrained('tarifas_proceso')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ficha_tecnica_items', function (Blueprint $table) {
            $table->dropForeign(['tarifa_proceso_id']);
            $table->dropColumn('tarifa_proceso_id');
        });
    }
};
