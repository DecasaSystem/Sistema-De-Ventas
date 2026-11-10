<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un color por material para marcarlo (usuario, 2026-11-10: "un color que represente cosas,
 * por ejemplo falta actualizar el precio"). Qué significa cada color lo dice la leyenda,
 * editable en Costos → Materiales (`configuracion.materiales_colores`). Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('materiales', 'color')) return;

        Schema::table('materiales', function (Blueprint $t) {
            $t->string('color', 20)->nullable()->after('precio_unitario');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('materiales', 'color')) return;

        Schema::table('materiales', fn (Blueprint $t) => $t->dropColumn('color'));
    }
};
