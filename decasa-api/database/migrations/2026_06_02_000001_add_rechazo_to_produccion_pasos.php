<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('produccion_pasos', 'rechazos')) return;
        Schema::table('produccion_pasos', function (Blueprint $table) {
            $table->unsignedTinyInteger('rechazos')->default(0)->after('trabajadores');
            $table->text('ultimo_rechazo')->nullable()->after('rechazos');
            $table->foreignId('rechazado_por_id')->nullable()->after('ultimo_rechazo')
                  ->constrained('usuarios')->nullOnDelete();
            $table->timestamp('rechazado_at')->nullable()->after('rechazado_por_id');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_pasos', function (Blueprint $table) {
            $table->dropForeign(['rechazado_por_id']);
            $table->dropColumn(['rechazos', 'ultimo_rechazo', 'rechazado_por_id', 'rechazado_at']);
        });
    }
};
