<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costos conectado con Inventario (2026-11-06, pedido del usuario: "meterlo una vez en
 * inventario y que aparezca en costos").
 *
 * La receta (ficha técnica) es de un producto del catálogo y, si hace falta, de una de sus
 * versiones con precio propio: la cama Bali de 1.40 no lleva la misma madera que la de 1.60
 * y no se vende al mismo precio. `variante_config_id` dice de qué versión es la ficha; en
 * null es la receta general del producto (la de un producto sin versiones, o la plantilla de
 * las de un producto con versiones).
 *
 * Solo agrega. Si se borra la versión, la ficha no se borra: queda como receta del producto
 * y la pantalla la muestra repetida para que alguien decida.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fichas_tecnicas', 'variante_config_id')) return;

        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->foreignId('variante_config_id')->nullable()->after('producto_id')
                  ->constrained('producto_variante_configs')->nullOnDelete();
            $table->index(['producto_id', 'variante_config_id'], 'fichas_producto_version_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fichas_tecnicas', 'variante_config_id')) return;

        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->dropForeign(['variante_config_id']);
            $table->dropIndex('fichas_producto_version_idx');
            $table->dropColumn('variante_config_id');
        });
    }
};
