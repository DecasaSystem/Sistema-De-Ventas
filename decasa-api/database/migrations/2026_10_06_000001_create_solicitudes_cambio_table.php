<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los cambios de plata de un vendedor pasan por un supervisor.
 *
 * Un vendedor podía cambiar él solo el precio de una orden ya vendida, los
 * descuentos, quitar o agregar productos y corregir el monto o el medio de un
 * abono. Ahora eso queda como una solicitud: qué quiere cambiar, por qué y con
 * qué soporte (una foto). Un supervisor la aprueba —y entonces se aplica por
 * el camino de siempre, con inventario, comisiones y avisos— o la rechaza.
 *
 *   cambios_orden  lo que se le mandaría a PATCH /ordenes/{id} (solo lo de plata)
 *   cambio_pago    {pago_id, monto, metodo, referencia} para PATCH /pagos/{id}
 *   resumen        [{label, antes, despues}] armado por el servidor al pedirla,
 *                  para que el supervisor vea qué cambia sin leer JSON
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_cambio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('solicitante_id')->constrained('usuarios');
            // pendiente → aprobada | rechazada | cancelada (la retiró quien la pidió)
            $table->string('estado', 15)->default('pendiente');
            $table->json('cambios_orden')->nullable();
            $table->json('cambio_pago')->nullable();
            $table->json('resumen');
            $table->text('motivo');
            $table->json('soportes');
            $table->foreignId('revisado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('revisado_at')->nullable();
            $table->text('respuesta')->nullable();
            $table->timestamps();

            $table->index(['orden_id', 'estado']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cambio');
    }
};
