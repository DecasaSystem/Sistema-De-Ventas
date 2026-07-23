<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de la tela para reconocerla mejor en el módulo de telas.
     */
    public function up(): void
    {
        Schema::table('catalogo_telas', function (Blueprint $table) {
            $table->string('foto_url', 500)->nullable()->after('textura');
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_telas', function (Blueprint $table) {
            $table->dropColumn('foto_url');
        });
    }
};
