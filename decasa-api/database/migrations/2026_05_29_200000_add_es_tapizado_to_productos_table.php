<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('productos', 'es_tapizado')) return;
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('es_tapizado')->default(false)->after('personalizable');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('es_tapizado');
        });
    }
};
