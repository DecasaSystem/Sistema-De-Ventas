<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué variante se vendió, escrito tal como se eligió.
 *
 * La orden ya tenía dónde guardar la variante (`variante_id` para las telas,
 * `combo_config_id` para las opciones configurables), pero la pantalla nunca
 * mandaba la segunda: al vender una CAMA MIAMI el vendedor escogía "1.60", lo
 * veía en el carrito y ahí se quedaba. De 149 ítems vendidos, ninguno tenía la
 * medida guardada, así que ni la orden ni el PDF podían decir cuál era.
 *
 * Se guarda además el texto, y no sólo el id de la opción, por dos razones:
 * un producto puede llevar dos variantes a la vez (medida y color) y en la FK
 * sólo cabe una; y lo que se le vendió al cliente no debe cambiar porque
 * después alguien renombre la opción en el catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->string('variante_detalle', 200)->nullable()->after('combo_config_id');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn('variante_detalle');
        });
    }
};
