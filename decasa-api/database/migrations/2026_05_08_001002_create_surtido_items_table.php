<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surtido_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('surtido_tienda_id');
            $table->unsignedBigInteger('producto_id');
            $table->integer('cantidad');
            $table->json('especificaciones')->nullable();
            $table->foreign('surtido_tienda_id')->references('id')->on('surtido_tiendas')->onDelete('cascade');
            $table->foreign('producto_id')->references('id')->on('productos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surtido_items');
    }
};
