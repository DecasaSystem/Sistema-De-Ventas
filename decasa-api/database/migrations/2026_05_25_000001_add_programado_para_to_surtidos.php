<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surtidos', function (Blueprint $table) {
            $table->timestamp('programado_para')->nullable()->after('notas');
        });

        // Ampliar el enum de estado para incluir 'programado'
        DB::statement("ALTER TABLE surtidos MODIFY COLUMN estado ENUM('programado','enviado','completado','rechazado_parcial') NOT NULL DEFAULT 'enviado'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE surtidos MODIFY COLUMN estado ENUM('enviado','completado','rechazado_parcial') NOT NULL DEFAULT 'enviado'");

        Schema::table('surtidos', function (Blueprint $table) {
            $table->dropColumn('programado_para');
        });
    }
};
