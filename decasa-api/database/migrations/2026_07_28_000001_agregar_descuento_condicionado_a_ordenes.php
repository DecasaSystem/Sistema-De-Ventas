<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento condicionado al medio de pago.
 *
 * Se otorga por pagar en efectivo o transferencia. Si el cliente paga cualquier
 * parte con tarjeta, se pierde completo y el total vuelve a subir.
 *
 * Va aparte de descuento_total (el descuento comercial, que se respeta siempre)
 * porque solo este se puede revertir: con un único campo no habría forma de
 * saber cuánto devolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes', 'descuento_condicionado')) {
                $table->decimal('descuento_condicionado', 12, 2)->default(0)->after('descuento_total');
            }
            if (! Schema::hasColumn('ordenes', 'descuento_condicionado_pct')) {
                $table->decimal('descuento_condicionado_pct', 5, 2)->nullable()->after('descuento_condicionado');
            }
            // Se marca en vez de borrarse: deja el rastro de que hubo descuento
            // y de cuándo se perdió.
            if (! Schema::hasColumn('ordenes', 'descuento_condicionado_revertido_at')) {
                $table->timestamp('descuento_condicionado_revertido_at')->nullable()->after('descuento_condicionado_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn([
                'descuento_condicionado',
                'descuento_condicionado_pct',
                'descuento_condicionado_revertido_at',
            ]);
        });
    }
};
