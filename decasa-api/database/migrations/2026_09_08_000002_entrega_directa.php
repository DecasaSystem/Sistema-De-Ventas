<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega directa: un vendedor o supervisor autorizado entrega su propia
 * orden sin pasar por una ruta ni un conductor.
 *
 * Medida temporal — los conductores no están usando el programa y eso deja
 * las órdenes atascadas en "listo para entrega". La entrega la sigue
 * pudiendo hacer un conductor con su ruta; esto es un segundo camino, no un
 * reemplazo.
 *
 * Se reusa el motor del conductor entero: la entrega directa crea un
 * `despacho` sintético (`tipo='directa'`, sin camión ni conductor, con
 * `entregado_por_id`) y un `despacho_item`, y a partir de ahí corre por los
 * mismos endpoints (`registrarPago`, `entregar`) y la misma pantalla. Así la
 * entrega directa y la del conductor descuentan inventario, cierran
 * producción y arman el acta exactamente igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despachos', function (Blueprint $table) {
            $table->enum('tipo', ['ruta', 'directa'])->default('ruta')->after('estado');
            $table->foreignId('entregado_por_id')->nullable()->after('conductor_id')
                ->constrained('usuarios')->nullOnDelete();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_entregas')->default(false)->after('acceso_despacho');
        });

        // Los supervisores ya podían dar una orden por entregada (a mano, desde
        // la orden). Este permiso les formaliza eso con acta y comprobante.
        DB::table('usuarios')->where('rol', 'supervisor')->update(['acceso_entregas' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('acceso_entregas');
        });

        Schema::table('despachos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entregado_por_id');
            $table->dropColumn('tipo');
        });
    }
};
