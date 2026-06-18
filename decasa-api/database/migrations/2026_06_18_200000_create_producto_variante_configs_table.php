<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('producto_variante_configs');
        Schema::create('producto_variante_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')
                  ->constrained('productos')
                  ->cascadeOnDelete();
            $table->foreignId('tipo_variante_id')
                  ->constrained('tipos_variante')
                  ->cascadeOnDelete();
            $table->foreignId('opcion_id')
                  ->constrained('tipo_variante_opciones')
                  ->cascadeOnDelete();
            $table->decimal('precio_adicional', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['producto_id', 'tipo_variante_id', 'opcion_id'], 'pvc_producto_tipo_opcion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_variante_configs');
    }
};
