<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió un pago: si se cobró al entregar (el conductor en la casa del
 * cliente, o la entrega que se registra desde el detalle de la orden), queda la
 * entrega en `despacho_item_id`.
 *
 * El efectivo cobrado en una entrega no entra a la caja de la tienda: no llegó
 * al cajón de nadie en el mostrador. A la caja solo entra el efectivo que se
 * recibe en la tienda (anticipos, abonos y la venta con entrega inmediata, que
 * se cobra como anticipo al crear la orden).
 *
 * Sin llave foránea a propósito: si la entrega se borra, el pago sigue siendo
 * un cobro de entrega y no debe volver a aparecer en la caja.
 *
 * Los pagos que ya existen quedan en null (siguen en la caja como hasta hoy).
 * Para marcar los viejos: `php artisan caja:cobros-de-entrega`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->unsignedBigInteger('despacho_item_id')->nullable()->after('tienda_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropIndex(['despacho_item_id']);
            $table->dropColumn('despacho_item_id');
        });
    }
};
