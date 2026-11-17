<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo mismo que ver_valor_inventario, pero para la Reserva de fábrica, con su
 * propio interruptor (usuario, 2026-11-17: "separados"). De fábrica se ve.
 * Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'ver_valor_reserva')) return;

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('ver_valor_reserva')->default(true);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('usuarios', 'ver_valor_reserva')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('ver_valor_reserva'));
        }
    }
};
