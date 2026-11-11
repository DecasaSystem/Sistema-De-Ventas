<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La retención que le hace al cliente empresa (contador, 2026-11-11: "compró
 * un millón, la retención son 25.000, el cliente paga 975.000 y al pagar la
 * factura se hace el cruce de retención"). Se anota al facturar para que la
 * factura no salga "a crédito" por esos 25.000 y para decirle al facturador
 * qué cruzar en Alegra. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facturas_electronicas') && ! Schema::hasColumn('facturas_electronicas', 'retencion_cliente')) {
            Schema::table('facturas_electronicas', function (Blueprint $t) {
                $t->decimal('retencion_cliente', 14, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('facturas_electronicas', 'retencion_cliente')) {
            Schema::table('facturas_electronicas', fn (Blueprint $t) => $t->dropColumn('retencion_cliente'));
        }
    }
};
