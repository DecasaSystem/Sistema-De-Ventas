<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Que un ítem sea obsequio deja de deducirse del precio.
 *
 * Hasta ahora "regalo" y "todavía sin cotizar" eran la misma cosa vistas desde
 * la base: un personalizado en $0. Por eso una venta con un obsequio quedaba
 * atrapada esperando un precio que nadie iba a poner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('es_regalo')->default(false)->after('es_restauracion');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn('es_regalo');
        });
    }
};
