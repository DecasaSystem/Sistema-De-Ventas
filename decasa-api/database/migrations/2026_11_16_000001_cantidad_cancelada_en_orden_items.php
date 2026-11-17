<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas unidades de un producto salieron de la orden porque se canceló su
 * fabricación y el cliente ya no lo quiere (usuario, 2026-11-17).
 *
 * Sirve para poder descancelarlo si el cliente cambia de opinión: se sabe
 * cuántas vuelven. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orden_items', 'cantidad_cancelada')) return;

        Schema::table('orden_items', function (Blueprint $t) {
            $t->unsignedInteger('cantidad_cancelada')->default(0)->after('cantidad_entregada');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orden_items', 'cantidad_cancelada')) return;

        Schema::table('orden_items', function (Blueprint $t) {
            $t->dropColumn('cantidad_cancelada');
        });
    }
};
