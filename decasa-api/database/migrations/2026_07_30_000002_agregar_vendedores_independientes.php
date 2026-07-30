<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendedores independientes: venden por su cuenta, sacan producto de las tiendas
 * pero su plata no entra a la caja de ninguna. Llevan caja propia y sus ventas
 * sí suman al total de la empresa.
 *
 * El ebanista ya era exactamente este caso con una regla aparte por rol; se
 * unifica aquí para no mantener dos caminos que hacen lo mismo.
 */
return new class extends Migration
{
    /** Sede contenedora: las órdenes necesitan una tienda y esta no es un punto de venta. */
    public const SEDE_INDEPENDIENTES = 'Independientes';

    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('independiente')->default(false)->after('es_tapicero');
        });

        Schema::table('tiendas', function (Blueprint $table) {
            $table->boolean('es_independientes')->default(false)->after('es_fabrica');
        });

        // ordenes.tienda_id es obligatorio y varios reportes cruzan con tiendas.
        // En vez de volverlo opcional —lo que dejaría estas ventas fuera de esos
        // reportes— las órdenes de los independientes cuelgan de esta sede, que
        // no cuenta como tienda en rankings ni cajas.
        $sedeId = DB::table('tiendas')
            ->where('nombre', self::SEDE_INDEPENDIENTES)
            ->value('id');

        if (! $sedeId) {
            DB::table('tiendas')->insert([
                'nombre'            => self::SEDE_INDEPENDIENTES,
                'ciudad'            => null,
                'activa'            => true,
                'es_fabrica'        => false,
                'es_independientes' => true,
                'created_at'        => now(),
            ]);
        } else {
            DB::table('tiendas')->where('id', $sedeId)->update(['es_independientes' => true]);
        }

        // El ebanista pasa a ser independiente. Se le deja su tienda actual
        // (Bodega Fábrica) para no mover sus órdenes históricas: los reportes
        // excluyen sus ventas de la tienda mirando la bandera del vendedor.
        DB::table('usuarios')->where('rol', 'ebanista')->update(['independiente' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('independiente');
        });
        Schema::table('tiendas', function (Blueprint $table) {
            $table->dropColumn('es_independientes');
        });
        // La sede se conserva: puede tener órdenes colgando.
    }
};
