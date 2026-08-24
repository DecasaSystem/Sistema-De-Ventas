<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Que un borrador se acuerde de que era una entrega inmediata.
 *
 * La marca se leía de la petición y se descartaba si la orden se guardaba como
 * borrador —`! $guardarBorrador && ...`—, y no se guardaba en ninguna parte.
 * Resultado: el vendedor la marcaba, guardaba el borrador, y al completarlo la
 * orden nacía como una venta normal: pendiente de anticipo, con el stock
 * apenas reservado y esperando un despacho que nunca iba a pasar, aunque el
 * cliente ya se hubiera llevado los muebles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->boolean('entrega_inmediata')->default(false)->after('canal');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('entrega_inmediata'));
    }
};
