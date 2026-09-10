<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos visuales: el PDF de siempre, pero adentro del sistema.
 *
 * Hasta ahora un catálogo era un enlace a Drive: el cliente tenía que
 * descargar el archivo para verlo. Esto guarda las páginas como imágenes
 * ordenadas y las muestra como una revista, sin descargar nada, y con un link
 * público por categoría para mandar por WhatsApp.
 *
 * No reemplaza al catálogo de productos (`/api/catalogo/{seccion}`), que arma
 * la grilla desde el inventario. Este es para el material de diseño que ya
 * viene maquetado hoja por hoja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            // La dirección web del catálogo. Se deriva del nombre y es única.
            $table->string('slug', 140)->unique();
            $table->string('descripcion', 300)->nullable();
            // Una de las páginas hace de portada en la grilla. Si va en null se
            // usa la primera.
            $table->string('portada_url', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('catalogo_paginas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_id')->constrained('catalogos')->cascadeOnDelete();
            $table->string('imagen_url', 500);
            $table->string('nota', 200)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['catalogo_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_paginas');
        Schema::dropIfExists('catalogos');
    }
};
