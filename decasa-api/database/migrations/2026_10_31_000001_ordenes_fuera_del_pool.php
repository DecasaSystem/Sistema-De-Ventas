<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una orden que no suma a la meta ni al pool de su tienda y no paga comisión.
 *
 * Dueño, 2026-10-23 (conciliación de Decasa Norte): las ventas de Jessica
 * Rincón ya se le pagaron por fuera y las FV2 de Margarita no son del equipo;
 * ninguna de las dos debe armar el pool de Norte. La orden sigue siendo una
 * venta de la tienda (reportes, caja, inventario): solo sale de la cuenta de
 * comisiones. La marca la pone un supervisor, con el motivo.
 *
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $t) {
            if (! Schema::hasColumn('ordenes', 'fuera_del_pool')) {
                $t->boolean('fuera_del_pool')->default(false);
                $t->string('fuera_del_pool_motivo', 200)->nullable();
                $t->unsignedBigInteger('fuera_del_pool_por')->nullable();
                $t->timestamp('fuera_del_pool_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $t) {
            $t->dropColumn(['fuera_del_pool', 'fuera_del_pool_motivo', 'fuera_del_pool_por', 'fuera_del_pool_at']);
        });
    }
};
