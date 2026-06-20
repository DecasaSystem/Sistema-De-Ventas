<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_telas', function (Blueprint $table) {
            $table->id();
            $table->string('referencia')->unique();
            $table->string('color', 100)->default('');
            $table->string('textura', 100)->default('');
            $table->string('proveedor', 100)->nullable();
            $table->decimal('metros_disponibles', 8, 2)->default(0);
            $table->decimal('metros_reservados', 8, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_telas');
    }
};
