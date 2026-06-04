<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->enum('estado', ['borrador', 'asignado', 'en_ruta', 'completado'])
                  ->default('asignado')
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->enum('estado', ['asignado', 'en_ruta', 'completado'])
                  ->default('asignado')
                  ->change();
        });
    }
};
