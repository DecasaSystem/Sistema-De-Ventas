<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE produccion_pasos
            MODIFY tipo_proceso ENUM(
                'ebanisteria',
                'tapizado',
                'laca',
                'esqueleteria',
                'pintura',
                'costura'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE produccion_pasos
            MODIFY tipo_proceso ENUM(
                'ebanisteria',
                'tapizado',
                'laca'
            ) NOT NULL
        ");
    }
};
