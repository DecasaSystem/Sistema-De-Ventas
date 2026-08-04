<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fecha que el vendedor le prometió al cliente.
 *
 * No es la fecha de entrega: esa la sigue poniendo el supervisor por ítem en
 * `orden_items.fecha_entrega_prom`. Esta es solo la referencia de lo que se
 * habló en el punto de venta, que hasta ahora se perdía — el vendedor decía
 * "para el 15" y quien asignaba la fecha no tenía cómo enterarse, así que a
 * veces quedaba para un mes después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->date('fecha_sugerida_vendedor')->nullable()->after('notas');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('fecha_sugerida_vendedor');
        });
    }
};
