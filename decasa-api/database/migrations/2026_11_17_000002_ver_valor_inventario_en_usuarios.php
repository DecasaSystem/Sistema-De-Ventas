<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si el supervisor quiere ver la plata del inventario (usuario, 2026-11-17:
 * un interruptor en Inventario "que quede guardado si entro desde el celular,
 * refresco…"). Va en la cuenta, no en el aparato. De fábrica se ve. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'ver_valor_inventario')) return;

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('ver_valor_inventario')->default(true);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('usuarios', 'ver_valor_inventario')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('ver_valor_inventario'));
        }
    }
};
