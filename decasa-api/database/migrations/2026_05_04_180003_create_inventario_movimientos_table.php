<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->enum('tipo', ['entrada', 'salida', 'reserva', 'liberacion']);
            $table->integer('cantidad');
            $table->string('motivo', 200)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_movimientos');
    }
};
