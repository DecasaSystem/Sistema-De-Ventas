<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca los ítems personalizados que toman una unidad física del stock de la
     * tienda (ej: una silla existente que se retapiza y se lleva esa misma).
     * Cuando es true, el ítem reserva/descuenta inventario ademas de ir a producción.
     */
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('usa_stock_tienda')->default(false)->after('es_personalizado');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn('usa_stock_tienda');
        });
    }
};
