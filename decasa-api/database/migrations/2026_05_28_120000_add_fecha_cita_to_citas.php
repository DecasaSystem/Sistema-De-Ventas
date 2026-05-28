<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->date('fecha_cita')->nullable()->after('hora');
            $table->index(['asesor_id', 'fecha_cita']);
        });
    }

    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->dropIndex(['asesor_id', 'fecha_cita']);
            $table->dropColumn('fecha_cita');
        });
    }
};
