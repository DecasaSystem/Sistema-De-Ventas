<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('cedula', 20)->unique()->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->enum('canal_pref', ['fisica', 'whatsapp', 'red_social', 'otro'])->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
