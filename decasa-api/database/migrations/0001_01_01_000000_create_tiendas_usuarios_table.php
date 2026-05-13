<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiendas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('ciudad', 80)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('email', 120)->unique();
            $table->string('password');
            $table->enum('rol', ['vendedor', 'supervisor'])->default('vendedor');
            $table->foreignId('tienda_default_id')->nullable()->constrained('tiendas');
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        // Necesaria para SESSION_DRIVER=database
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('tiendas');
    }
};
