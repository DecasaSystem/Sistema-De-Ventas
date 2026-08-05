<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto en un mensaje del chat de la orden.
 *
 * Una duda sobre un mueble casi siempre se resuelve mostrando: "así llegó la
 * tela", "mira la veta". Explicarlo por escrito cuesta más y se presta a
 * malentendidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            $table->string('imagen_url', 500)->nullable()->after('mensaje');
        });
    }

    public function down(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            $table->dropColumn('imagen_url');
        });
    }
};
