<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->integer('cantidad_disponible')->default(0);
            $table->integer('cantidad_reservada')->default(0);
            $table->integer('stock_minimo')->default(1);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['producto_id', 'tienda_id']);
            $table->index('tienda_id', 'idx_inv_tienda');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario');
    }
};
