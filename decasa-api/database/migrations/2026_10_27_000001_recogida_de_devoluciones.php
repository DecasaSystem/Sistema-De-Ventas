<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dónde va lo que el cliente devuelve en un cambio de producto, y cuándo.
 *
 * La orden cambia en el momento (deja de cobrar lo devuelto y suma lo nuevo),
 * pero el mueble muchas veces sigue en la casa del cliente: se recoge otro
 * día, o cuando le llevan el nuevo. Hasta que llegue no puede entrar al
 * inventario —si no, se vendería algo que no está—.
 *
 *  - destino: `tienda` (a una tienda o a la fábrica/bodega, `tienda_destino_id`)
 *    o `merma` (llegó dañado, no vuelve a la venta).
 *  - recoger_el / recoger_con_entrega: cuándo se va a recoger. Vacíos los dos
 *    y `recibido_at` lleno = ya estaba aquí.
 *  - recibido_at / recibido_por_id: cuándo llegó de verdad (ahí entra al
 *    inventario).
 *
 * Solo agrega columnas: las devoluciones viejas quedan con todo en null y se
 * leen como siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $table) {
            if (! Schema::hasColumn('devoluciones', 'destino')) {
                $table->string('destino', 20)->nullable()->after('estado');
            }
            if (! Schema::hasColumn('devoluciones', 'tienda_destino_id')) {
                $table->unsignedBigInteger('tienda_destino_id')->nullable()->after('destino');
            }
            if (! Schema::hasColumn('devoluciones', 'recoger_el')) {
                $table->date('recoger_el')->nullable()->after('tienda_destino_id');
            }
            if (! Schema::hasColumn('devoluciones', 'recoger_con_entrega')) {
                $table->boolean('recoger_con_entrega')->default(false)->after('recoger_el');
            }
            if (! Schema::hasColumn('devoluciones', 'recibido_at')) {
                $table->timestamp('recibido_at')->nullable()->after('recoger_con_entrega');
            }
            if (! Schema::hasColumn('devoluciones', 'recibido_por_id')) {
                $table->unsignedBigInteger('recibido_por_id')->nullable()->after('recibido_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('devoluciones', function (Blueprint $table) {
            $table->dropColumn(['destino', 'tienda_destino_id', 'recoger_el', 'recoger_con_entrega', 'recibido_at', 'recibido_por_id']);
        });
    }
};
