<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->unsignedBigInteger('facturacion_tomada_por')->nullable()->after('notas');
            $table->timestamp('facturacion_hecha_at')->nullable()->after('facturacion_tomada_por');

            $table->foreign('facturacion_tomada_por')
                  ->references('id')->on('usuarios')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropForeign(['facturacion_tomada_por']);
            $table->dropColumn(['facturacion_tomada_por', 'facturacion_hecha_at']);
        });
    }
};
