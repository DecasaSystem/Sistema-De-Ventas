<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La orden viaja con el enlace de firma (2026-10-28, pedido del usuario).
 *
 * Antes el enlace solo llevaba lo que el cliente VE; la orden de verdad vivía
 * en la pantalla de Nueva orden del vendedor (y en su respaldo de 48 horas).
 * Si el cliente se demoraba en firmar, el formulario ya no estaba y no había
 * con qué crearla. Ahora el anexo guarda la orden completa tal como se
 * mandaría a POST /ordenes, y el vendedor escoge al mandarla:
 *  - que se cree sola apenas el cliente firme, o
 *  - esperar la firma y crearla él (desde Nueva orden, cuando quiera).
 *
 * Solo agrega columnas a anexos_garantia:
 *  - orden_pendiente: la orden lista para crear (JSON).
 *  - crear_al_firmar: lo que escogió el vendedor.
 *  - orden_auto_estado / orden_auto_error: cómo le fue al crearla (creada,
 *    fallo) y por qué no se pudo, para que el vendedor lo vea y la cree a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anexos_garantia') && ! Schema::hasColumn('anexos_garantia', 'orden_pendiente')) {
            Schema::table('anexos_garantia', function (Blueprint $t) {
                $t->json('orden_pendiente')->nullable();
                $t->boolean('crear_al_firmar')->default(false);
                $t->string('orden_auto_estado', 20)->nullable();
                $t->text('orden_auto_error')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('anexos_garantia', 'orden_pendiente')) {
            Schema::table('anexos_garantia', function (Blueprint $t) {
                $t->dropColumn(['orden_pendiente', 'crear_al_firmar', 'orden_auto_estado', 'orden_auto_error']);
            });
        }
    }
};
