<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_variante_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')
                  ->constrained('producto_variante_configs')
                  ->cascadeOnDelete();
            $table->foreignId('tienda_id')
                  ->constrained('tiendas')
                  ->cascadeOnDelete();
            $table->integer('cantidad_disponible')->default(0);
            $table->integer('cantidad_reservada')->default(0);
            $table->timestamps();

            $table->unique(['config_id', 'tienda_id'], 'ivc_config_tienda_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_variante_configs');
    }
};
