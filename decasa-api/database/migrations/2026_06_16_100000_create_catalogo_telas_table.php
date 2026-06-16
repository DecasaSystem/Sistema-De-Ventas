<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_telas', function (Blueprint $table) {
            $table->id();
            $table->string('marca', 100);
            $table->string('tipo',  100);
            $table->string('color', 100);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['marca', 'tipo', 'color']);
            $table->index('marca');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_telas');
    }
};
