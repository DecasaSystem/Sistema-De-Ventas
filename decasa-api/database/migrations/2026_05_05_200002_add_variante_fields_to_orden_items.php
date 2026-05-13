<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            // Variante de tela/color seleccionada
            $table->foreignId('variante_id')
                  ->nullable()
                  ->after('producto_id')
                  ->constrained('producto_variantes')
                  ->onDelete('set null');

            // Tienda de donde viene el stock cuando es de otra tienda
            $table->foreignId('tienda_origen_id')
                  ->nullable()
                  ->after('variante_id')
                  ->constrained('tiendas')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropForeign(['variante_id']);
            $table->dropForeign(['tienda_origen_id']);
            $table->dropColumn(['variante_id', 'tienda_origen_id']);
        });
    }
};
