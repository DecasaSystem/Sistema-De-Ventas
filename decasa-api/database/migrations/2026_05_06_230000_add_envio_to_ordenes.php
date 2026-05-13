<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->string('direccion_envio', 300)->nullable()->after('firma_url');
            $table->string('ciudad_envio', 100)->nullable()->after('direccion_envio');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn(['direccion_envio', 'ciudad_envio']);
        });
    }
};
