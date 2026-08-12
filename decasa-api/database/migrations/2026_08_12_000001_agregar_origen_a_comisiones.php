<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde sale una comisión.
 *
 * Hasta ahora todas eran lo mismo: la venta de una persona. Ahora hay otra
 * cosa — cuando un independiente comparte una venta con un almacén, ese
 * almacén cobra un 5% que se reparte entre su gente. Eso se calculaba al
 * vuelo y no tenía fila propia, así que no había forma de marcarlo pagado:
 * se le pagaba a la persona y el mes siguiente la pantalla lo volvía a pedir.
 *
 * Con su fila se marca pagada como cualquier otra y queda el registro de
 * quién y cuándo. Va marcada aparte porque NO puede contarle a la meta de la
 * tienda ni entrar al pool: esa venta no es de ellos, solo pasaron el
 * contacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comisiones', function (Blueprint $table) {
            $table->string('origen', 20)->default('venta')->after('tienda_id');
            $table->index(['origen', 'mes_venta']);
        });
    }

    public function down(): void
    {
        Schema::table('comisiones', function (Blueprint $table) {
            $table->dropIndex(['origen', 'mes_venta']);
            $table->dropColumn('origen');
        });
    }
};
