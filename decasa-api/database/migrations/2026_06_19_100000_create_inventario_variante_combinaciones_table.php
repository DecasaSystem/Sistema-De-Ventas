<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_variante_combinaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variante_id')
                  ->constrained('producto_variantes')
                  ->cascadeOnDelete();
            $table->foreignId('config_id')
                  ->constrained('producto_variante_configs')
                  ->cascadeOnDelete();
            $table->foreignId('tienda_id')
                  ->constrained('tiendas')
                  ->cascadeOnDelete();
            $table->integer('cantidad_disponible')->default(0);
            $table->integer('cantidad_reservada')->default(0);
            $table->timestamps();

            $table->unique(['variante_id', 'config_id', 'tienda_id'], 'ivcom_var_config_tienda_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_variante_combinaciones');
    }
};
