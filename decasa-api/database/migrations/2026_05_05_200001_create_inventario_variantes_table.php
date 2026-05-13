<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_variantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variante_id')->constrained('producto_variantes')->onDelete('cascade');
            $table->foreignId('tienda_id')->constrained('tiendas')->onDelete('cascade');
            $table->integer('cantidad_disponible')->default(0);
            $table->integer('cantidad_reservada')->default(0);
            $table->integer('stock_minimo')->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['variante_id', 'tienda_id']);
            $table->index('tienda_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_variantes');
    }
};
