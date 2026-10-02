<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos que se venden en juego: unas mesas de noche que vienen de a 2, un
 * comedor de 6 sillas.
 *
 * El inventario de esos productos se cuenta por PIEZAS: es lo que físicamente
 * hay y lo único que no cambia si el cliente se lleva el juego completo o una
 * sola. "1 juego" es cómo se vende y se muestra, no cómo se guarda. Así todo
 * lo que ya mueve stock (apartar, entregar, devolver, trasladar) sigue
 * funcionando sin tocarlo, y una mesa suelta deja media pareja en la tienda
 * en vez de un número imposible.
 *
 * productos:
 *   piezas_por_juego  null = se vende por unidad, como siempre. N = juego de N.
 *   precio_pieza      Precio de una pieza suelta. null = el del juego entre N.
 *
 * orden_items (lo que se vendió, para mostrarlo y compararlo después):
 *   piezas_juego      N si se vendió de un producto en juego (la cantidad va
 *                     en piezas; las de juego completo son cantidad / N).
 *   es_pieza_suelta   true si se vendieron piezas sueltas y no juegos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedSmallInteger('piezas_por_juego')->nullable()->after('tiene_tallas');
            $table->decimal('precio_pieza', 12, 2)->nullable()->after('piezas_por_juego');
        });

        Schema::table('orden_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('piezas_juego')->nullable()->after('variante_detalle');
            $table->boolean('es_pieza_suelta')->default(false)->after('piezas_juego');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn(['piezas_juego', 'es_pieza_suelta']);
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['piezas_por_juego', 'precio_pieza']);
        });
    }
};
