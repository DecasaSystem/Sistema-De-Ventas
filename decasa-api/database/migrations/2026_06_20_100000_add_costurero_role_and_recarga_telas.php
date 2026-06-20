<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor','ebanista','despachador','costurero') NOT NULL DEFAULT 'vendedor'");

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('recarga_telas')->default(false)->after('acceso_redes');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('recarga_telas');
        });

        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor','ebanista','despachador') NOT NULL DEFAULT 'vendedor'");
    }
};
