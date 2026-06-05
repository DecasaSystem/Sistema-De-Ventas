<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('traslado_items', 'cantidad_aceptada')) {
            Schema::table('traslado_items', function (Blueprint $table) {
                $table->unsignedInteger('cantidad_aceptada')->nullable()->after('cantidad');
            });
        }
        if (!Schema::hasColumn('surtido_items', 'cantidad_aceptada')) {
            Schema::table('surtido_items', function (Blueprint $table) {
                $table->unsignedInteger('cantidad_aceptada')->nullable()->after('cantidad');
            });
        }
    }

    public function down(): void
    {
        Schema::table('traslado_items', function (Blueprint $table) {
            $table->dropColumn('cantidad_aceptada');
        });

        Schema::table('surtido_items', function (Blueprint $table) {
            $table->dropColumn('cantidad_aceptada');
        });
    }
};
