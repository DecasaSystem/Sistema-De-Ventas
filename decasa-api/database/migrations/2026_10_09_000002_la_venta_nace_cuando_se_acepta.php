<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una cotización (o un borrador) es venta desde el día en que el cliente la
 * acepta, no desde el día en que se abrió.
 *
 * `created_at` es la fecha de la venta en todo el sistema, así que al
 * aceptarse se le pone la de ese día (Orden::nacerComoVentaHoy) y la fecha en
 * que se cotizó queda guardada aquí. Una cotización de julio aceptada en
 * agosto es venta de agosto, empuja la meta de agosto y se cobra el 20 de
 * septiembre, como todas.
 *
 * No mueve nada de lo ya vendido: aplica a lo que se acepte de aquí en
 * adelante.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ordenes', 'cotizada_en')) {
            Schema::table('ordenes', function (Blueprint $t) {
                $t->timestamp('cotizada_en')->nullable()->after('confirmada_en');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $t) {
            $t->dropColumn('cotizada_en');
        });
    }
};
