<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un traslado puede decir QUÉ tela o medida se está mandando.
 *
 * Hasta ahora un traslado solo sabía de productos: "2 sofás de Norte a Sur".
 * El reparto por tela/medida no viajaba con ellos — en el origen se recortaba
 * a lo que quedara (y podía recortar justo la tela que una orden tenía
 * apartada) y en el destino entraban 2 sofás sin tela, como si nadie supiera
 * de qué color eran. Los que llegaban dejaban de poder venderse por su tela.
 *
 * Con la variante en el ítem, lo que sale de una tela allá entra en la misma
 * tela acá. Nulo sigue queriendo decir "sin especificar", que es como se
 * trasladó siempre y como se sigue trasladando lo que no tiene variantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traslado_items', function (Blueprint $table) {
            if (! Schema::hasColumn('traslado_items', 'variante_id')) {
                $table->unsignedBigInteger('variante_id')->nullable()->after('producto_id');
            }
            if (! Schema::hasColumn('traslado_items', 'combo_config_id')) {
                $table->unsignedBigInteger('combo_config_id')->nullable()->after('variante_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('traslado_items', function (Blueprint $table) {
            $table->dropColumn(['variante_id', 'combo_config_id']);
        });
    }
};
