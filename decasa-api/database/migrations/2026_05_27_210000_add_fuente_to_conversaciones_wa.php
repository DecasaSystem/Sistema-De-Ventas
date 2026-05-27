<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->enum('fuente', ['whatsapp', 'instagram'])->default('whatsapp')->after('estado');
            $table->string('contacto_url', 500)->nullable()->after('whatsapp_url');
        });
    }

    public function down(): void
    {
        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->dropColumn(['fuente', 'contacto_url']);
        });
    }
};
