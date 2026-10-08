<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes de redes: las personas que escriben por WhatsApp o Instagram.
 *
 * Pedido del dueño (2026-10-08): antes de pasar a alguien con un asesor, Elena
 * le pide nombre y celular, y eso tiene que quedar en Clientes, en un apartado
 * de redes. Hasta ahora lo único que quedaba eran las tarjetas de Redes, que se
 * archivan: nadie tenía una lista de interesados con su teléfono, qué buscaban
 * y cómo va cada uno.
 *
 * Es una tabla aparte y no `clientes` a propósito: `clientes` es la de las
 * órdenes (cédula única, datos que confirma un vendedor). Lo que llega del chat
 * es un prospecto; cuando compra, el asesor lo convierte en cliente y quedan
 * enlazados por `cliente_id`.
 *
 * Una fila por persona y canal (`canal` + `identificador`): cada aviso nuevo del
 * agente la actualiza en vez de duplicarla. Se crea apenas el cliente da su nombre o
 * su celular (aunque no pida asesor) y Elena le va actualizando el interés. Los
 * agentes no leen esta tabla: la escriben por la API.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clientes_redes')) return;

        Schema::create('clientes_redes', function (Blueprint $table) {
            $table->id();
            $table->string('canal', 15);                       // whatsapp | instagram
            $table->string('identificador', 80);               // número del chat o ig_<psid>
            $table->string('nombre', 120)->nullable();
            $table->string('telefono', 20)->nullable();        // el que dio para que lo llamen
            $table->string('usuario_red', 80)->nullable();     // @usuario de Instagram
            $table->string('contacto_url', 255)->nullable();   // wa.me / ig.me
            $table->string('ciudad', 80)->nullable();
            $table->string('forma_pago', 40)->nullable();
            $table->string('espacio', 120)->nullable();
            $table->unsignedBigInteger('presupuesto')->nullable();
            $table->json('preferencias')->nullable();
            $table->json('productos_interes')->nullable();
            $table->json('categorias_interes')->nullable();     // camas, sofás… (lo que pidió ver)
            $table->string('interes', 500)->nullable();         // lo que busca, resumido por Elena
            $table->text('ultimo_interes')->nullable();         // resumen de la última tarjeta de Redes
            $table->string('ultimo_tipo', 20)->nullable();     // asesor | pedido | cita | personalizacion
            $table->string('estado', 20)->default('nuevo');    // nuevo | contactado | compro | perdido
            $table->text('notas')->nullable();
            $table->boolean('no_quiso_dar_datos')->default(false);
            $table->unsignedBigInteger('tienda_id')->nullable();
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->unsignedBigInteger('ultima_conversacion_id')->nullable();
            $table->unsignedInteger('total_conversaciones')->default(0);
            $table->timestamp('primer_contacto_at')->nullable();
            $table->timestamp('ultimo_contacto_at')->nullable();
            $table->timestamps();

            $table->unique(['canal', 'identificador']);
            $table->index(['estado', 'ultimo_contacto_at']);
            $table->index('telefono');
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_redes');
    }
};
