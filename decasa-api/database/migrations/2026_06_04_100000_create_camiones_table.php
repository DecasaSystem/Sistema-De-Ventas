<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camiones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->nullable();
            $table->string('placa', 20)->nullable();
            $table->foreignId('conductor_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        // Sembrar los 2 camiones iniciales
        DB::table('camiones')->insert([
            ['nombre' => 'Camión 1', 'placa' => null, 'conductor_id' => null, 'activo' => true],
            ['nombre' => 'Camión 2', 'placa' => null, 'conductor_id' => null, 'activo' => true],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('camiones');
    }
};
