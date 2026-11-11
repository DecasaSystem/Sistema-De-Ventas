<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mano de obra sin costo fijo (usuario, 2026-11-11): "esqueletería… no tienen un costo fijo
 * ni calculable". Un catálogo de tipos (Esqueletería, Tapicería…) que se escoge al agregar
 * mano de obra a una ficha, y el costo se escribe ahí mismo para esa ficha. A diferencia de
 * Tarifas (incentivo × horas), Tarifas nunca lo recalcula.
 *
 * Solo crea la tabla y agrega `ficha_tecnica_items.tipo_mano_obra_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipos_mano_obra')) {
            Schema::create('tipos_mano_obra', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 100);
                // Archivado: ya no se ofrece al agregar, pero las fichas que lo usan siguen igual
                $t->boolean('activo')->default(true);
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
            });

            // Para arrancar: las secciones de las fichas que vinieron del Excel. Se editan en Tarifas.
            $ahora = now();
            foreach (['Esqueletería', 'Carpintería', 'Tapicería', 'Corte y costura', 'Pintura y lacado'] as $i => $nombre) {
                DB::table('tipos_mano_obra')->insert(['nombre' => $nombre, 'orden' => $i, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }

        if (! Schema::hasColumn('ficha_tecnica_items', 'tipo_mano_obra_id')) {
            Schema::table('ficha_tecnica_items', function (Blueprint $t) {
                $t->foreignId('tipo_mano_obra_id')->nullable()->after('tarifa_proceso_id')
                    ->constrained('tipos_mano_obra')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ficha_tecnica_items', 'tipo_mano_obra_id')) {
            Schema::table('ficha_tecnica_items', function (Blueprint $t) {
                $t->dropConstrainedForeignId('tipo_mano_obra_id');
            });
        }
        Schema::dropIfExists('tipos_mano_obra');
    }
};
