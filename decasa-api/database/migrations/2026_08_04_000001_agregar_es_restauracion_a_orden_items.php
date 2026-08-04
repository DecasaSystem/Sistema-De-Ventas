<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca la restauración en el ÍTEM, no en la orden.
 *
 * Antes una orden era entera de venta o entera de restauración (`ordenes.tipo`),
 * así que un cliente que quería restaurar un mueble y de paso comprar un comedor
 * obligaba a hacer dos órdenes: dos consecutivos, dos PDF y el anticipo partido.
 *
 * Un ítem de restauración es un personalizado sin producto_id, igual que un
 * "diseño especial", así que hacía falta una marca propia para distinguirlos y
 * poder listar en el módulo de Restauración las órdenes que tengan al menos uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('es_restauracion')->default(false)->after('fabricar_pedido');
        });

        // Las órdenes que ya existen quedan marcadas ítem por ítem, para que el
        // módulo de Restauración las siga viendo cuando deje de mirar ordenes.tipo.
        DB::table('orden_items')
            ->whereIn('orden_id', DB::table('ordenes')->where('tipo', 'restauracion')->pluck('id'))
            ->update(['es_restauracion' => true]);
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn('es_restauracion');
        });
    }
};
