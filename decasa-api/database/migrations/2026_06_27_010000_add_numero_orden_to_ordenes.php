<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->unsignedInteger('numero_orden')->nullable()->unique()->after('id');
        });

        // Backfill: numerar órdenes existentes que no son borrador, en orden de creación
        DB::statement('SET @n = 0');
        DB::statement("
            UPDATE ordenes
            SET numero_orden = (@n := @n + 1)
            WHERE estado != 'borrador'
            ORDER BY id ASC
        ");
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('numero_orden');
        });
    }
};
