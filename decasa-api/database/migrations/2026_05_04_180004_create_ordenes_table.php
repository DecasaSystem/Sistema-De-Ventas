<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('vendedor_id')->constrained('usuarios');
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->enum('canal', ['fisica', 'whatsapp', 'red_social', 'otro'])->nullable();
            $table->enum('estado', ['pendiente_anticipo', 'en_produccion', 'listo_entrega', 'entregado', 'cancelado'])
                  ->default('pendiente_anticipo');
            $table->decimal('valor_total', 12, 2);
            $table->decimal('anticipo_pct', 5, 2)->default(50.00);
            $table->text('notas')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('vendedor_id', 'idx_ordenes_vendedor');
            $table->index('tienda_id', 'idx_ordenes_tienda');
            $table->index('estado', 'idx_ordenes_estado');
            $table->index('created_at', 'idx_ordenes_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes');
    }
};
