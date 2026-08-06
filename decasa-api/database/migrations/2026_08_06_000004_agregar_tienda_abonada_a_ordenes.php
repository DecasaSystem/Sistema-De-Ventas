<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una venta de un independiente que se le abona a medias a una tienda.
 *
 * Un independiente va a un almacén, le pasan el contacto, cierra la venta. La
 * venta es suya, pero el almacén ayudó: la mitad se le abona a la tienda para
 * su meta y la otra mitad queda para el vendedor.
 *
 * Es la misma idea que una venta compartida entre dos vendedores, solo que la
 * otra mitad no va a una persona sino a una tienda. Por eso NO se reusa
 * tienda_id: esa sigue diciendo de quién es la venta. Si se pusiera aquí la
 * tienda que ayudó, la comisión del vendedor saldría del pool de esa tienda,
 * que es justo lo que no se quiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->foreignId('tienda_abonada_id')->nullable()->after('tienda_id')
                  ->constrained('tiendas');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropForeign(['tienda_abonada_id']);
            $table->dropColumn('tienda_abonada_id');
        });
    }
};
