<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('produccion', function (Blueprint $table) {
            $table->date('fecha_compromiso')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('produccion', function (Blueprint $table) {
            $table->date('fecha_compromiso')->nullable(false)->change();
        });
    }
};
