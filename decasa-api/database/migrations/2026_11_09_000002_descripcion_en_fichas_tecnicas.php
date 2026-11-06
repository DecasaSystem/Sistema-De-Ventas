<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Al vincular una ficha con un producto de Inventario, la ficha toma el nombre del producto
 * (pedido del usuario, 2026-11-06). El nombre que tenía —casi siempre el del Excel: "CAMA
 * MACARENA DE 1.40 PINO"— no se pierde: queda aquí como descripción, y el cotizador la sigue
 * leyendo (trae medidas y materiales que el nombre del producto no dice).
 *
 * Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fichas_tecnicas', 'descripcion')) return;

        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fichas_tecnicas', 'descripcion')) return;

        Schema::table('fichas_tecnicas', fn (Blueprint $table) => $table->dropColumn('descripcion'));
    }
};
