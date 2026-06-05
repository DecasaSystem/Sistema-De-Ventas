<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surtidos', function (Blueprint $table) {
            $table->boolean('fuente_fabrica')->default(false)->after('notas');
        });
    }

    public function down(): void
    {
        Schema::table('surtidos', function (Blueprint $table) {
            $table->dropColumn('fuente_fabrica');
        });
    }
};
