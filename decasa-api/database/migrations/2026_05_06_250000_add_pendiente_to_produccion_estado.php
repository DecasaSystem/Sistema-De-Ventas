<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE produccion MODIFY COLUMN estado ENUM('pendiente','en_proceso','listo','retrasado','entregado') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE produccion MODIFY COLUMN estado ENUM('en_proceso','listo','retrasado','entregado') NOT NULL DEFAULT 'en_proceso'");
    }
};
