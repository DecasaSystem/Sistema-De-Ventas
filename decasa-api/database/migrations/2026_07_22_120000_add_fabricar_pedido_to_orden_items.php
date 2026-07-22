<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue los ítems personalizados que son "para fabricar" (un producto del
     * catálogo que no tiene stock en ninguna tienda y se manda a producción) de un
     * "personalizado" normal (producto existente al que se le cambian cositas).
     * Junto con producto_id permite derivar el tipo de ítem:
     *   - !es_personalizado           => catálogo (sale de inventario)
     *   - producto_id === null        => diseño especial (no está en catálogo)
     *   - fabricar_pedido             => para fabricar (catálogo sin stock)
     *   - resto                       => personalizado
     */
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('fabricar_pedido')->default(false)->after('es_personalizado');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn('fabricar_pedido');
        });
    }
};
