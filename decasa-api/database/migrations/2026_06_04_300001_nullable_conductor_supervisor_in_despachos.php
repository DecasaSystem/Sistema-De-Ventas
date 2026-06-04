<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->unsignedBigInteger('conductor_id')->nullable()->default(null)->change();
            $table->unsignedBigInteger('supervisor_id')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->unsignedBigInteger('conductor_id')->nullable(false)->change();
            $table->unsignedBigInteger('supervisor_id')->nullable(false)->change();
        });
    }
};
