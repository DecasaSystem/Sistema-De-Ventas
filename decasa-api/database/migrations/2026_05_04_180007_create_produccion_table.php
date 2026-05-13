<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_item_id')->unique()->constrained('orden_items');
            $table->date('fecha_inicio');
            $table->date('fecha_compromiso');
            $table->date('fecha_real')->nullable();
            $table->enum('estado', ['en_proceso', 'listo', 'retrasado', 'entregado'])->default('en_proceso');
            $table->text('motivo_retraso')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion');
    }
};
