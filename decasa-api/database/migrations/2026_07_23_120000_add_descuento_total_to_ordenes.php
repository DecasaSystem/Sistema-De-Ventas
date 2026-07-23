<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Descuento aplicado al total de la orden (monto en COP), aparte de los
     * descuentos por ítem. valor_total ya queda con el descuento restado; esta
     * columna guarda cuánto se descontó para mostrarlo en el detalle y el PDF.
     */
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->decimal('descuento_total', 12, 2)->default(0)->after('valor_total');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('descuento_total');
        });
    }
};
