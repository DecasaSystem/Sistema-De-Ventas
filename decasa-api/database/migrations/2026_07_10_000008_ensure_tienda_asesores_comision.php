<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tienda_asesores_comision')) {
            return;
        }

        Schema::create('tienda_asesores_comision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tienda_id')->constrained('tiendas')->cascadeOnDelete();
            $table->char('mes', 7);
            $table->foreignId('vendedor_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tienda_id', 'mes', 'vendedor_id']);
            $table->index(['tienda_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tienda_asesores_comision');
    }
};
