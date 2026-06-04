<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->string('nombre_ruta', 120)->nullable()->after('notas');
            $table->text('instrucciones')->nullable()->after('nombre_ruta');
        });
    }

    public function down(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->dropColumn(['nombre_ruta', 'instrucciones']);
        });
    }
};
