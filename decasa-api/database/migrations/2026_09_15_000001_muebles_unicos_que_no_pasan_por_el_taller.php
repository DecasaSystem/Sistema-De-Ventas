<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vender un mueble que ya existe, es el único, y no está en el catálogo.
 *
 * La empresa guarda modelos que salieron una sola vez. Meterlos al catálogo y
 * al inventario no paga: hay uno, no se va a fabricar más, y en cuanto se
 * venda el registro sobra. Pero tampoco se podían vender como "producto no
 * catalogado", porque todo lo que se crea en el momento sale marcado como
 * personalizado y eso es justo lo que le crea una producción: el mueble ya
 * está hecho y aun así le caía al taller un trabajo que nadie tiene que hacer.
 *
 * Con esta marca el ítem sigue siendo personalizado —no toca inventario,
 * porque no hay ningún registro que descontar— pero no genera producción. Es
 * lo único que lo distingue, y por eso es una bandera y no un tipo nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('producto_unico')->default(false)->after('es_restauracion');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', fn (Blueprint $t) => $t->dropColumn('producto_unico'));
    }
};
