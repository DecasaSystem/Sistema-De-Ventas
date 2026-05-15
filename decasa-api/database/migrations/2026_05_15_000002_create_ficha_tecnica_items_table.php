<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ficha_tecnica_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ficha_tecnica_id')->constrained('fichas_tecnicas')->cascadeOnDelete();
            $table->string('seccion')->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 10, 4)->default(0);
            $table->string('unidad')->nullable();
            $table->decimal('precio_unitario', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('es_mano_obra')->default(false);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_tecnica_items');
    }
};
