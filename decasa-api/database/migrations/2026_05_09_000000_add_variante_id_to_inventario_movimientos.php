<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventario_movimientos', function (Blueprint $table) {
            $table->foreignId('variante_id')->nullable()->constrained('producto_variantes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventario_movimientos', function (Blueprint $table) {
            $table->dropForeign(['variante_id']);
            $table->dropColumn('variante_id');
        });
    }
};
