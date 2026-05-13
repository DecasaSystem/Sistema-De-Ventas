<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('categoria', 80)->nullable();
            $table->decimal('precio_base', 12, 2);
            $table->boolean('personalizable')->default(false);
            $table->text('descripcion')->nullable();
            $table->string('foto_url', 255)->nullable();
            $table->string('medidas', 200)->nullable();
            $table->string('material', 200)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
