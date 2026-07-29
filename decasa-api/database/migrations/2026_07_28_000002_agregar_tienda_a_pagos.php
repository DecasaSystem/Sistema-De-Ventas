<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tienda donde se recibió el dinero.
 *
 * Un cliente puede abonar en una tienda distinta a la que hizo la venta. El
 * efectivo queda físicamente en la caja de donde abonó, no en la de la orden,
 * que es lo que el sistema asumía hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            if (! Schema::hasColumn('pagos', 'tienda_id')) {
                $table->foreignId('tienda_id')->nullable()->after('vendedor_id')->constrained('tiendas');
            }
        });

        // Los pagos ya registrados se atribuyen a la tienda de su orden, que es
        // exactamente lo que se venía asumiendo: así las cajas no cambian de un
        // día para otro por efecto de la migración.
        DB::statement('
            UPDATE pagos p
            JOIN ordenes o ON o.id = p.orden_id
            SET p.tienda_id = o.tienda_id
            WHERE p.tienda_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropForeign(['tienda_id']);
            $table->dropColumn('tienda_id');
        });
    }
};
