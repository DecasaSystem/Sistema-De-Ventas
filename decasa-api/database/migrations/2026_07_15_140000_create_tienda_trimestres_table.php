<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tienda_trimestres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->char('trimestre', 7); // 'YYYY-Qn'
            $table->decimal('deficit_inicial', 15, 2)->default(0);
            $table->decimal('pool_bruto', 15, 2)->default(0);
            $table->decimal('pool_pagado', 15, 2)->default(0);
            $table->decimal('deficit_final', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['tienda_id', 'trimestre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tienda_trimestres');
    }
};
