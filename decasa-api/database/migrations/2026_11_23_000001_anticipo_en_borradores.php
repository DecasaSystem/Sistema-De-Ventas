<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el vendedor puso del anticipo, guardado con el borrador (2026-11-23).
 *
 * Un borrador no registra pagos: la pantalla lo manda con el anticipo en $0 y
 * solo quedaba el porcentaje. Al seguirlo (la venta virtual que se guarda
 * mientras el cliente firma, o desde otro aparato) el anticipo volvía a $0, en
 * efectivo, sin el pago partido ni la referencia, y había que volver a
 * llenarlo. Esto es solo la intención del vendedor para rellenar el
 * formulario: no es plata recibida. Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ordenes', 'anticipo_borrador')) {
            Schema::table('ordenes', fn (Blueprint $t) => $t->json('anticipo_borrador')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ordenes', 'anticipo_borrador')) {
            Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('anticipo_borrador'));
        }
    }
};
