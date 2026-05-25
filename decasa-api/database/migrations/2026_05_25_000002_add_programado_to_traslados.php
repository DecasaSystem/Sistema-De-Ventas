<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traslados', function (Blueprint $table) {
            $table->timestamp('programado_para')->nullable()->after('notas');
            // 'completado' para traslados inmediatos/ya ejecutados, 'programado' en espera, 'fallido' si el job falla
            $table->string('estado', 20)->default('completado')->after('programado_para');
        });

        // Marcar todos los traslados existentes como completados
        \Illuminate\Support\Facades\DB::table('traslados')->whereNull('programado_para')->update(['estado' => 'completado']);
    }

    public function down(): void
    {
        Schema::table('traslados', function (Blueprint $table) {
            $table->dropColumn(['programado_para', 'estado']);
        });
    }
};
