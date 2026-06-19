<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surtido_items', function (Blueprint $table) {
            $table->foreignId('variante_id')
                ->nullable()->after('producto_id')
                ->constrained('producto_variantes')->nullOnDelete();
            $table->foreignId('combo_config_id')
                ->nullable()->after('variante_id')
                ->constrained('producto_variante_configs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('surtido_items', function (Blueprint $table) {
            $table->dropForeign(['variante_id']);
            $table->dropForeign(['combo_config_id']);
            $table->dropColumn(['variante_id', 'combo_config_id']);
        });
    }
};
