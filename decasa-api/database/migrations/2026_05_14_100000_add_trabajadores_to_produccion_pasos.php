<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion_pasos', function (Blueprint $table) {
            // Array de nombres de trabajadores responsables del paso
            $table->json('trabajadores')->nullable()->after('completado_at');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_pasos', function (Blueprint $table) {
            $table->dropColumn('trabajadores');
        });
    }
};
