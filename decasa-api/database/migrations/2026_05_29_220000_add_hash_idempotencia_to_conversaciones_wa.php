<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conversaciones_wa', 'hash_idempotencia')) return;
        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->string('hash_idempotencia', 64)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->dropColumn('hash_idempotencia');
        });
    }
};
