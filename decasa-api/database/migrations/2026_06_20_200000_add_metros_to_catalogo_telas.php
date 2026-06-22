<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_telas', function (Blueprint $table) {
            $table->decimal('metros_disponibles', 8, 2)->default(0)->after('color');
            $table->decimal('metros_reservados', 8, 2)->default(0)->after('metros_disponibles');
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_telas', function (Blueprint $table) {
            $table->dropColumn(['metros_disponibles', 'metros_reservados']);
        });
    }
};
