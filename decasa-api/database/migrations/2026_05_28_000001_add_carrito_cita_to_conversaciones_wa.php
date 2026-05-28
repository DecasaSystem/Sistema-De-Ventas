<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->json('carrito')->nullable()->after('historial');
            $table->json('datos_cita')->nullable()->after('carrito');
            $table->foreignId('tienda_id')->nullable()->after('datos_cita')
                  ->constrained('tiendas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversaciones_wa', function (Blueprint $table) {
            $table->dropForeign(['tienda_id']);
            $table->dropColumn(['carrito', 'datos_cita', 'tienda_id']);
        });
    }
};
