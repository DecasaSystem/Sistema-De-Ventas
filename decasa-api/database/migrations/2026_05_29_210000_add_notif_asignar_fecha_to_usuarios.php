<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'notif_asignar_fecha')) return;
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('notif_asignar_fecha')->default(true)->after('es_tapicero');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('notif_asignar_fecha');
        });
    }
};
