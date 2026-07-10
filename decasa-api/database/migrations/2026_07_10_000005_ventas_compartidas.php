<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campos en ordenes para registrar venta compartida
        Schema::table('ordenes', function (Blueprint $table) {
            $table->boolean('es_compartida')->default(false)->after('notas');
            $table->unsignedBigInteger('covendedor_id')->nullable()->after('es_compartida');
            $table->foreign('covendedor_id')->references('id')->on('usuarios')->nullOnDelete();
        });

        // Paso 1: agregar el índice compuesto ANTES de eliminar el simple,
        // para que MySQL nunca quede sin índice de soporte para la FK de orden_id.
        Schema::table('comisiones', function (Blueprint $table) {
            $table->unique(['orden_id', 'vendedor_id']);
        });

        // Paso 2: ahora sí se puede eliminar el índice único simple
        Schema::table('comisiones', function (Blueprint $table) {
            $table->dropUnique(['orden_id']);
        });
    }

    public function down(): void
    {
        Schema::table('comisiones', function (Blueprint $table) {
            $table->unique(['orden_id']);
        });

        Schema::table('comisiones', function (Blueprint $table) {
            $table->dropUnique(['orden_id', 'vendedor_id']);
        });

        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropForeign(['covendedor_id']);
            $table->dropColumn(['es_compartida', 'covendedor_id']);
        });
    }
};
