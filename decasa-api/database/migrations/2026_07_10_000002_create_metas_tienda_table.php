<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metas_tienda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->char('mes', 7); // 'YYYY-MM'
            $table->decimal('meta', 15, 2);
            $table->timestamps();
            $table->unique(['tienda_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_tienda');
    }
};
