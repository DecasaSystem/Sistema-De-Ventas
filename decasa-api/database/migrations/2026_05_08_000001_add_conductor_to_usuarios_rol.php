<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor') NOT NULL DEFAULT 'vendedor'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor') NOT NULL DEFAULT 'vendedor'");
    }
};
