<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Juegos de distinto tamaño dentro del mismo producto: unas alas vintage que
 * vienen de a 2 o de a 4 según la opción.
 *
 * El producto tiene su número de piezas por juego (productos.piezas_por_juego)
 * y cada opción de su variante personalizada puede poner el suyo. null = el
 * del producto. Solo vale mientras el producto se venda en juego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_variante_configs', function (Blueprint $table) {
            $table->unsignedSmallInteger('piezas_por_juego')->nullable()->after('precio_adicional');
        });
    }

    public function down(): void
    {
        Schema::table('producto_variante_configs', function (Blueprint $table) {
            $table->dropColumn('piezas_por_juego');
        });
    }
};
