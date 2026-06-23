<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->enum('tipo', ['ingreso_manual', 'egreso']);
            $table->decimal('monto', 12, 2);
            $table->string('concepto', 255);
            $table->text('descripcion')->nullable();
            $table->string('comprobante_url')->nullable();
            $table->timestamps();

            $table->index('tienda_id', 'idx_caja_tienda');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_movimientos');
    }
};
