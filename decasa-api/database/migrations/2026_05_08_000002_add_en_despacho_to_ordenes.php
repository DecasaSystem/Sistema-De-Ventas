<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_despacho','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");

        if (! Schema::hasColumn('ordenes', 'listo_entrega_at')) {
            Schema::table('ordenes', function (Blueprint $table) {
                $table->timestamp('listo_entrega_at')->nullable()->after('estado');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('listo_entrega_at');
        });

        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");
    }
};
