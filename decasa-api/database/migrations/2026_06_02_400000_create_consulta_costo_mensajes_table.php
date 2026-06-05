<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consulta_costo_mensajes')) {
            Schema::create('consulta_costo_mensajes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('consulta_id')->constrained('consultas_costo')->cascadeOnDelete();
                $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
                $table->text('mensaje');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_costo_mensajes');
    }
};
