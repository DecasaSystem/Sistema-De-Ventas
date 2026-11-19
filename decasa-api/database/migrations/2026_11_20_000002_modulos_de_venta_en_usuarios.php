<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vender como módulos (docs/metodologia-de-trabajo-por-rol.md, fase 2).
 *
 * Siete interruptores por persona. Se prenden a todos los que hoy los tienen
 * en pantalla (todo el que no es conductor ni despachador), así que el día
 * del cambio nadie pierde nada. Solo agrega columnas.
 */
return new class extends Migration
{
    private const COLUMNAS = [
        'acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones',
        'acceso_caja', 'acceso_consultas_costo', 'acceso_estadisticas',
    ];

    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            foreach (self::COLUMNAS as $c) {
                if (! Schema::hasColumn('usuarios', $c)) {
                    $t->boolean($c)->default(false);
                }
            }
        });

        DB::table('usuarios')
            ->whereNotIn('rol', ['conductor', 'despachador'])
            ->update(array_fill_keys(self::COLUMNAS, true));
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            foreach (self::COLUMNAS as $c) {
                if (Schema::hasColumn('usuarios', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
