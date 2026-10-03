<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de lo entregado, por producto.
 *
 * La entrega tenía UNA foto del producto. Con un sofá, dos poltronas y seis
 * sillas en la misma entrega, esa foto no respaldaba nada: si después el
 * cliente reclama una silla rayada, no hay foto de esa silla.
 *
 * Ahora cada producto que va en la entrega trae sus fotos:
 * [{orden_item_id, url}, ...]. `foto_producto` se queda con la primera, para
 * lo que ya la usa (la regla de "se puede cerrar", las miniaturas).
 *
 * En una entrega parcial cada entrega guarda las fotos de lo que llevó ese
 * día: el sofá hoy, las sillas cuando salgan del taller.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('despacho_items', 'fotos_producto')) {
            Schema::table('despacho_items', function (Blueprint $t) {
                $t->json('fotos_producto')->nullable()->after('foto_producto');
            });
        }
    }

    public function down(): void
    {
        Schema::table('despacho_items', function (Blueprint $t) {
            $t->dropColumn('fotos_producto');
        });
    }
};
