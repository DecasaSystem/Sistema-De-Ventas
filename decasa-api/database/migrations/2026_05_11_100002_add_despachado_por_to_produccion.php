<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion', function (Blueprint $table) {
            $table->unsignedBigInteger('despachado_por')->nullable()->after('motivo_retraso');
            $table->foreign('despachado_por')->references('id')->on('usuarios');
        });
    }

    public function down(): void
    {
        Schema::table('produccion', function (Blueprint $table) {
            $table->dropForeign(['despachado_por']);
            $table->dropColumn('despachado_por');
        });
    }
};
