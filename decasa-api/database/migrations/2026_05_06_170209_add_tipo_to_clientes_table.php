<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->enum('tipo', ['oficial', 'interesado'])->default('oficial')->after('canal_pref');
            $table->json('categorias_interes')->nullable()->after('tipo');
            $table->text('notas_interes')->nullable()->after('categorias_interes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'categorias_interes', 'notas_interes']);
        });
    }
};
