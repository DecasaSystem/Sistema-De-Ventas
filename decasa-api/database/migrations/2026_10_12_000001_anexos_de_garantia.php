<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El anexo de garantías, firmado dentro del sistema.
 *
 * Antes se imprimía, el cliente lo firmaba en papel y se le tomaba una foto.
 * Ahora se lee y se firma en la pantalla —en la tienda, en el teléfono del
 * vendedor, o a distancia con un enlace que le llega al cliente— y queda
 * guardado qué leyó, qué respondió y su firma. La foto del papel sigue
 * siendo posible (`ordenes.anexo_foto_url`).
 *
 * El anexo nace antes que la orden (la venta todavía no existe cuando el
 * cliente firma), por eso `orden_id` empieza vacío y se llena al crearla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anexos_garantia')) return;

        Schema::create('anexos_garantia', function (Blueprint $t) {
            $t->id();
            // Lo que va en el enlace que se le manda al cliente.
            $t->string('token', 64)->unique();
            $t->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $t->foreignId('vendedor_id')->nullable()->constrained('usuarios')->nullOnDelete();
            // Qué texto del anexo se firmó: si se cambia el documento, los
            // firmados antes siguen diciendo lo que el cliente leyó.
            $t->string('version', 20);
            $t->string('modo', 20);                 // presencial | remoto
            $t->string('estado', 20)->default('pendiente'); // pendiente | firmado | anulado
            // Lo que el cliente vio de la orden antes de firmar (a distancia).
            $t->json('resumen')->nullable();
            $t->string('resumen_hash', 64)->nullable();
            // Secciones marcadas como leídas y el check list (sí / no).
            $t->json('respuestas')->nullable();
            $t->string('nombre_firmante', 150)->nullable();
            $t->string('documento_firmante', 30)->nullable();
            $t->string('firma_url', 500)->nullable();
            $t->timestamp('firmado_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 300)->nullable();
            $t->timestamp('vence_at')->nullable();
            $t->timestamps();

            $t->index(['orden_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos_garantia');
    }
};
