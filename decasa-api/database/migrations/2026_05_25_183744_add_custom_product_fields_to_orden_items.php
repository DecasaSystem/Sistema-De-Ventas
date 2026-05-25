<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            // Make producto_id nullable to support fully-custom products
            $table->unsignedBigInteger('producto_id')->nullable()->change();

            // Fields for products that don't exist in the catalog
            $table->string('nombre_custom', 200)->nullable()->after('producto_id');
            $table->string('categoria_custom', 100)->nullable()->after('nombre_custom');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn(['nombre_custom', 'categoria_custom']);
            $table->unsignedBigInteger('producto_id')->nullable(false)->change();
        });
    }
};
