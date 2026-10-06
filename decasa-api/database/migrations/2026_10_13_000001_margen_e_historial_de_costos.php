<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costos de producción, dos cosas que faltaban:
 *
 * 1. Margen: la ficha técnica (lo que cuesta fabricar) no sabía a qué producto
 *    del catálogo (lo que se cobra) corresponde. `producto_id` las une para
 *    comparar costo contra precio de venta. Es opcional y no es único: un
 *    producto puede tener más de una ficha, y las fichas viejas del Excel se
 *    van vinculando a mano.
 *
 * 2. Historial de precios de materiales: cada cambio de precio queda anotado
 *    (quién, cuándo, de cuánto a cuánto y cuánto movió el costo de las fichas).
 *    Se guarda el nombre del material porque el material se puede borrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->foreignId('producto_id')->nullable()->after('categoria')
                  ->constrained('productos')->nullOnDelete();
        });

        Schema::create('material_precio_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->nullable()->constrained('materiales')->nullOnDelete();
            $table->string('material_nombre');
            $table->decimal('precio_anterior', 12, 2);
            $table->decimal('precio_nuevo', 12, 2);
            $table->unsignedInteger('productos_afectados')->default(0);
            // Cuánto subió (o bajó) en total el costo de las fichas que lo usan
            $table->decimal('impacto_total', 14, 2)->default(0);
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['material_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_precio_historial');

        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->dropColumn('producto_id');
        });
    }
};
