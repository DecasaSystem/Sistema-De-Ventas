<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vender un mueble que está en la tienda pero cambiándole la tela.
 *
 * El cliente quiere el sofá que está en el local, pero no en ese color. Lo
 * que se hace es apartar ESE sofá, llevarlo a la fábrica y retapizarlo. Es
 * una venta como cualquiera —suma a la meta, tiene número y comisión— y a la
 * vez es un trabajo del taller.
 *
 * Ninguna de las marcas que había lo describía: "personalizado" no toca
 * inventario (y aquí el sofá existe y hay que apartarlo para que nadie más lo
 * venda), y "catálogo" no pasa por el taller (y aquí hay que cambiarle la
 * tela). Por eso es una bandera aparte sobre un ítem de stock: reserva como
 * catálogo y produce como personalizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('retapizar')->default(false)->after('producto_unico');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', fn (Blueprint $t) => $t->dropColumn('retapizar'));
    }
};
