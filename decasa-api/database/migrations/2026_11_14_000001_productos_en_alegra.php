<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué producto de Alegra es cada mueble del sistema.
 *
 * En Decasa cada mueble ya existe en Alegra con su nombre y su inventario por
 * bodega (factura FEN1917: ítem "SOFA TELAVID", descripción "TAUPE", bodega
 * "3 - EDEN"). El sistema empareja por nombre y, si no lo encuentra, el
 * facturador lo escoge una vez: queda aquí para las siguientes facturas. Así
 * no se crean productos repetidos en Alegra. Solo crea una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alegra_productos')) return;

        Schema::create('alegra_productos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('producto_id')->unique()->constrained('productos')->cascadeOnDelete();
            $t->string('alegra_item_id', 40);
            $t->string('alegra_nombre', 200)->nullable();
            // 'automatico' (se encontró por el nombre) o 'manual' (lo escogió una persona).
            $t->string('origen', 12)->default('manual');
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alegra_productos');
    }
};
