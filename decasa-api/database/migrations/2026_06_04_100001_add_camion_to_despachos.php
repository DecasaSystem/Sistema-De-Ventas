<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->foreignId('camion_id')->nullable()->after('id')->constrained('camiones')->nullOnDelete();
            $table->date('fecha_despacho')->nullable()->after('camion_id');
        });
    }

    public function down(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->dropForeign(['camion_id']);
            $table->dropColumn(['camion_id', 'fecha_despacho']);
        });
    }
};
