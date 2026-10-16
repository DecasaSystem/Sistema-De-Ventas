<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los textos de los documentos que se editan desde Gestión → Documentos: el
 * anexo de garantías que firma el cliente y la orden de entrega que se lleva
 * el conductor.
 *
 * Cada vez que alguien guarda se crea una fila nueva (una versión) y nunca se
 * pisa la anterior: el anexo es un texto legal y cada anexo firmado guarda la
 * versión que leyó el cliente, así que el PDF de un anexo firmado en octubre
 * tiene que seguir saliendo con el texto de octubre aunque hoy diga otra cosa.
 * La vigente es la última. Si una clave no tiene filas, vale el texto de
 * fábrica que trae el código (AnexoGarantiaTexto, PlantillasDocumento).
 *
 * Solo crea una tabla: no toca nada de lo que ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plantillas_documento')) return;

        Schema::create('plantillas_documento', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 40);
            // La misma que queda en anexos_garantia.version (20 caracteres).
            $t->string('version', 20);
            $t->json('contenido');
            // Por qué se cambió: "lo pidió el abogado", "nueva garantía de espumas".
            $t->string('nota', 255)->nullable();
            $t->unsignedBigInteger('creado_por')->nullable();
            $t->timestamps();

            $t->unique(['clave', 'version']);
            $t->index(['clave', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_documento');
    }
};
