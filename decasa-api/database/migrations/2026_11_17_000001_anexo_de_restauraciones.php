<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué anexo firmó el cliente: el de garantías de ventas (null, como todos los
 * de antes) o el de restauraciones ('anexo_restauracion'). Hace falta porque
 * las versiones de los dos textos son fechas y pueden coincidir el mismo día:
 * sin esto el PDF de un anexo de restauración podía salir con el de ventas.
 * Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anexos_garantia') || Schema::hasColumn('anexos_garantia', 'plantilla')) return;

        Schema::table('anexos_garantia', function (Blueprint $table) {
            $table->string('plantilla', 40)->nullable()->after('version');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('anexos_garantia', 'plantilla')) {
            Schema::table('anexos_garantia', fn (Blueprint $t) => $t->dropColumn('plantilla'));
        }
    }
};
