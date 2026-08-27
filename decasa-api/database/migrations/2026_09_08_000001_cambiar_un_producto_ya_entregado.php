<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cambiar un producto que ya se entregó.
 *
 * La señora recibió la mesa, a los dos días la devolvió y quiere otra que
 * cuesta más. Como ya pagó la primera, esa plata se le abona: no vuelve a
 * pagar desde cero, solo la diferencia.
 *
 * Hasta ahora no había por dónde: una orden entregada es un estado final y no
 * se puede editar, así que tocaba crear otra orden a mano y la plata que ya
 * había pagado quedaba colgando en la orden vieja.
 *
 * El ítem devuelto NO se borra: se marca. Borrarlo dejaría la orden diciendo
 * que solo se vendió lo nuevo, y lo que de verdad pasó —que se entregó una
 * cosa y se cambió por otra— es justo lo que hay que poder mirar después. Deja
 * de sumar al total, y el saldo se recalcula solo contra lo ya pagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            // El día en que el cliente lo devolvió. Null = sigue vivo en la orden.
            $table->date('devuelto_en')->nullable()->after('fecha_entrega_prom');
            $table->text('motivo_devolucion')->nullable()->after('devuelto_en');
        });

        // Una devolución por cambio no espera decisión de nadie: ya está
        // resuelta en el momento en que se registra —se cambia por otro
        // producto—, a diferencia de la que llega dañada en el camión.
        DB::statement("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','a_produccion','reembolsada','cambio') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn(['devuelto_en', 'motivo_devolucion']);
        });

        DB::statement("UPDATE devoluciones SET estado = 'reembolsada' WHERE estado = 'cambio'");
        DB::statement("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','a_produccion','reembolsada') NOT NULL DEFAULT 'pendiente'");
    }
};
