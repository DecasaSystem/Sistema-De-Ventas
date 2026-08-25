<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando el mueble llega a la casa golpeado y se devuelve en el mismo camión.
 *
 * Hasta ahora el acta del conductor solo distinguía "recibí conforme" de
 * "recibí con novedad" —las dos con el mueble quedándose en la casa—. El caso
 * de que el cliente NO se lo quede no existía: el conductor tenía que marcar
 * la orden como entregada igual, y la pieza que volvía en el camión no quedaba
 * escrita en ninguna parte.
 *
 * Va por producto y no por orden: en un camión pueden ir la cama y dos mesas
 * de noche y volver solo la cama. Devolver la orden entera diría que el cliente
 * no recibió las mesas, y las mesas se quedaron.
 *
 * Después alguien decide qué se hace, y son dos caminos: casi siempre vuelve al
 * taller a que la arreglen, y de vez en cuando se cancela y se le devuelve la
 * plata. Esa decisión es lo que cierra la devolución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            // Qué producto volvió. La devolución cuelga del ítem, que es lo que
            // vuelve a producción si se decide arreglarlo.
            $table->foreignId('orden_item_id')->constrained('orden_items')->cascadeOnDelete();
            // De qué entrega vino. Null si se registra a mano desde la orden,
            // sin que haya pasado por un camión.
            $table->foreignId('despacho_item_id')->nullable()->constrained('despacho_items')->nullOnDelete();

            // Cuántas unidades vuelven: de dos mesas puede volver una.
            $table->unsignedInteger('cantidad')->default(1);
            // Obligatorio. "Se devolvió" a secas no sirve para nada cuando hay
            // que decidir si se arregla o quién responde por el golpe.
            $table->text('motivo');
            $table->string('foto_url', 500)->nullable();
            // El día en que se devolvió, que no siempre es el día en que
            // alguien lo registra.
            $table->date('fecha');
            $table->foreignId('reportado_por_id')->nullable()->constrained('usuarios');

            // pendiente: esperando que decidan.
            // a_produccion: vuelve al taller a que la arreglen.
            // reembolsada: se canceló y se le devolvió la plata.
            $table->enum('estado', ['pendiente', 'a_produccion', 'reembolsada'])->default('pendiente');
            $table->foreignId('decidido_por_id')->nullable()->constrained('usuarios');
            $table->timestamp('decidido_at')->nullable();
            $table->text('notas_decision')->nullable();

            // Solo cuando se reembolsa. El movimiento de caja queda enlazado
            // para poder ir de la devolución a la plata que salió y al revés.
            $table->decimal('monto_devuelto', 12, 2)->nullable();
            $table->foreignId('caja_movimiento_id')->nullable()
                  ->constrained('caja_movimientos')->nullOnDelete();

            $table->timestamps();

            $table->index(['estado', 'fecha']);
            $table->index('orden_id');
        });

        // La orden necesita poder decir que se devolvió algo y todavía no se
        // decide qué hacer. Sin un estado propio habría que marcarla entregada
        // —mentira, el camión se regresó con la mercancía— o dejarla en camino,
        // que es donde nadie la vuelve a mirar.
        // La lista completa sale de la tabla real, no de las migraciones: en
        // producción el enum trae además 'cotizacion', que se agregó por fuera.
        // Reescribirlo sin ese valor dejaría la cotización que hay viva sin
        // estado válido.
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('cotizacion','borrador','pendiente_cotizacion','pendiente_anticipo','en_produccion','listo_entrega','en_camino','devuelto','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");

        // El renglón de la ruta también: la entrega de esa orden no se
        // completó, se devolvió.
        DB::statement("ALTER TABLE despacho_items MODIFY COLUMN estado ENUM('pendiente','entregado','devuelto') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones');

        DB::statement("UPDATE ordenes SET estado = 'en_camino' WHERE estado = 'devuelto'");
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('cotizacion','borrador','pendiente_cotizacion','pendiente_anticipo','en_produccion','listo_entrega','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");

        DB::statement("UPDATE despacho_items SET estado = 'pendiente' WHERE estado = 'devuelto'");
        DB::statement("ALTER TABLE despacho_items MODIFY COLUMN estado ENUM('pendiente','entregado') NOT NULL DEFAULT 'pendiente'");
    }
};
