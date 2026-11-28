<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "El cliente lo recoge en la tienda" (usuario, 2026-11-28).
 *
 * El cliente compró algo que ya existe (no se fabrica) y queda de ir por él
 * en unos días. No es "se lo lleva ahora" (eso se entrega al vender) ni va en
 * el camión: la orden no entra a la cola de Despacho y, cuando el cliente
 * llega, la entrega quien está en la tienda con la entrega directa de siempre.
 *
 * Solo agrega la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ordenes', 'recoge_en_tienda')) return;

        Schema::table('ordenes', function (Blueprint $table) {
            $table->boolean('recoge_en_tienda')->default(false)->after('entrega_inmediata');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ordenes', 'recoge_en_tienda')) return;

        Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('recoge_en_tienda'));
    }
};
