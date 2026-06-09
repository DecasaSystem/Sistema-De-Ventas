<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_variantes', function (Blueprint $table) {
            $table->string('marca_tela', 100)->nullable()->change();
            $table->string('nombre_color', 100)->nullable()->change();
            $table->string('medida', 50)->nullable()->after('nombre_color');
            $table->decimal('precio_variante', 12, 2)->nullable()->after('medida');
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('tiene_tallas')->default(false)->after('es_tapizado');
        });
    }

    public function down(): void
    {
        Schema::table('producto_variantes', function (Blueprint $table) {
            $table->dropColumn(['medida', 'precio_variante']);
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('tiene_tallas');
        });
    }
};
