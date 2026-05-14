<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traslados', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('tienda_origen_id');
            $table->unsignedBigInteger('tienda_destino_id');
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('supervisor_id')->references('id')->on('usuarios');
            $table->foreign('tienda_origen_id')->references('id')->on('tiendas');
            $table->foreign('tienda_destino_id')->references('id')->on('tiendas');
            $table->index(['supervisor_id', 'created_at']);
        });

        Schema::create('traslado_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('traslado_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedInteger('cantidad');
            $table->timestamps();

            $table->foreign('traslado_id')->references('id')->on('traslados')->cascadeOnDelete();
            $table->foreign('producto_id')->references('id')->on('productos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traslado_items');
        Schema::dropIfExists('traslados');
    }
};
