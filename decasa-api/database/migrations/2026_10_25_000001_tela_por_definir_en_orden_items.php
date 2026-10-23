<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Producto vendido sabiendo la medida pero no la tela ("la cama de 1.40, la
 * tela la escoge después"). Se marca para que la orden lo diga, se pueda
 * definir después y no se despache sin ella. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orden_items', 'tela_por_definir')) return;

        Schema::table('orden_items', function (Blueprint $table) {
            $table->boolean('tela_por_definir')->default(false)->after('combo_config_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orden_items', 'tela_por_definir')) return;

        Schema::table('orden_items', fn (Blueprint $table) => $table->dropColumn('tela_por_definir'));
    }
};
