<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué supervisor le pidió el vendedor el cambio de dinero.
 *
 * Antes la solicitud les llegaba a todos los supervisores y ninguno sabía si
 * le tocaba a él. Ahora el vendedor elige a quién, y solo a ese le llega el
 * aviso. Nullable: las solicitudes de antes no tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_cambio', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->after('solicitante_id')
                ->constrained('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_cambio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
        });
    }
};
