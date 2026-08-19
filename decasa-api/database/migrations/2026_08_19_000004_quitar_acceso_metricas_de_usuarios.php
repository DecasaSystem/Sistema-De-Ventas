<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas no es un módulo aparte de Redes: va junto. Se quita la bandera
 * separada; a partir de ahora quien tiene acceso_redes ve también Métricas,
 * sin necesitar un segundo permiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('acceso_metricas');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_metricas')->default(false)->after('acceso_despacho');
        });
    }
};
