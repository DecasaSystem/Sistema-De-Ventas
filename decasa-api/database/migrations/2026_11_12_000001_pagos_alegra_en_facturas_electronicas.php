<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los pagos del cliente se registran en Alegra DESPUÉS de crear la factura y
 * con el total exacto que dice Alegra (antes iban dentro de la factura y, por
 * centavos de redondeo, podían pasarse del total y hacerla rechazar). Aquí se
 * anota qué pago quedó registrado allá y cuál falta, para reintentar solo esos.
 * Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facturas_electronicas') && ! Schema::hasColumn('facturas_electronicas', 'pagos_alegra')) {
            Schema::table('facturas_electronicas', function (Blueprint $t) {
                $t->json('pagos_alegra')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('facturas_electronicas', 'pagos_alegra')) {
            Schema::table('facturas_electronicas', fn (Blueprint $t) => $t->dropColumn('pagos_alegra'));
        }
    }
};
