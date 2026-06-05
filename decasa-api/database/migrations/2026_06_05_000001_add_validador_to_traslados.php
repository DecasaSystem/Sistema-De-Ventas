<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('traslados', 'vendedor_validador_id')) return;
        Schema::table('traslados', function (Blueprint $table) {
            $table->unsignedBigInteger('vendedor_validador_id')->nullable()->after('supervisor_id');
            $table->foreign('vendedor_validador_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('traslados', function (Blueprint $table) {
            $table->dropForeign(['vendedor_validador_id']);
            $table->dropColumn('vendedor_validador_id');
        });
    }
};
