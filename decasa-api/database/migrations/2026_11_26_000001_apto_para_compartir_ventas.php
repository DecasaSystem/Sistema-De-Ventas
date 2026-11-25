<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Apto para compartir ventas" (usuario, 2026-11-25).
 *
 * En Decasa los conductores y la gente del taller a veces ayudan a cerrar una
 * venta. Hasta hoy la lista de co-vendedor traía solo vendedores y
 * supervisores, así que con ellos no se podía compartir, y si se compartía
 * por otro lado su mitad no la cobraba nadie. Con esta marca salen en la
 * lista de co-vendedor de Nueva orden y Editar orden. "Apto para ventas"
 * (apto_comisiones, que ya existía) además los deja ser el vendedor.
 *
 * Solo agrega la columna, apagada para todos: no cambia nada hasta que un
 * supervisor la prenda en la ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'apto_compartir_ventas')) return;

        Schema::table('usuarios', function (Blueprint $t) {
            $t->boolean('apto_compartir_ventas')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('usuarios', 'apto_compartir_ventas')) return;

        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('apto_compartir_ventas'));
    }
};
