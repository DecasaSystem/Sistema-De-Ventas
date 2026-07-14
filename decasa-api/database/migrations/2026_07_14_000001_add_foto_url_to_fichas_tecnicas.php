<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->string('foto_url', 500)->nullable()->after('ruta_excel');
        });
    }

    public function down(): void
    {
        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->dropColumn('foto_url');
        });
    }
};
