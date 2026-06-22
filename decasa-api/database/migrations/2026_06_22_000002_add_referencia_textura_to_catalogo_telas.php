<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_telas', function (Blueprint $table) {
            $table->string('referencia', 200)->nullable()->unique()->after('color');
            $table->string('textura', 100)->nullable()->after('referencia');
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_telas', function (Blueprint $table) {
            $table->dropUnique(['referencia']);
            $table->dropColumn(['referencia', 'textura']);
        });
    }
};
