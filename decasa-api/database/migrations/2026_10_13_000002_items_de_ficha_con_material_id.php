<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los ítems de material de las fichas se enlazaban con el catálogo solo por el
 * NOMBRE escrito (`descripcion` = `materiales.nombre`). Un renombre, un espacio
 * de más o una grafía distinta los dejaba sueltos. Ahora llevan `material_id`.
 *
 * Se llena con lo que hoy coincide por nombre (sin mayúsculas ni espacios de los
 * lados). Lo que no coincide queda en null: son materiales fuera del catálogo y
 * el módulo de Costos los marca "para revisar". La mano de obra no lleva material.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ficha_tecnica_items', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('es_mano_obra')
                  ->constrained('materiales')->nullOnDelete();
        });

        // Si hay dos materiales con el mismo nombre gana el más viejo (el que ya
        // usaban las fichas); los duplicados se ordenan aparte con `activo`.
        $porNombre = [];
        foreach (DB::table('materiales')->orderBy('id')->get(['id', 'nombre']) as $m) {
            $clave = mb_strtolower(trim($m->nombre));
            if ($clave !== '' && ! isset($porNombre[$clave])) $porNombre[$clave] = $m->id;
        }

        foreach ($porNombre as $clave => $id) {
            DB::table('ficha_tecnica_items')
                ->where('es_mano_obra', false)
                ->whereNull('material_id')
                ->whereRaw('LOWER(TRIM(descripcion)) = ?', [$clave])
                ->update(['material_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('ficha_tecnica_items', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
            $table->dropColumn('material_id');
        });
    }
};
