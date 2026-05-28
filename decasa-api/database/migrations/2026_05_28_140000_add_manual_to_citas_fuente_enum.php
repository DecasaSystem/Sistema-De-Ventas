<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE citas MODIFY COLUMN fuente ENUM('whatsapp','instagram','manual') NOT NULL DEFAULT 'whatsapp'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE citas MODIFY COLUMN fuente ENUM('whatsapp','instagram') NOT NULL DEFAULT 'whatsapp'");
    }
};
