<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La serie de órdenes con descuento especial es FV2, no FB2: se creó con la
 * letra equivocada.
 *
 * Al momento del cambio no había ninguna orden emitida con la serie vieja, así
 * que esto solo renombra el contador. El UPDATE de órdenes queda igual por si
 * se llegara a aplicar sobre una base donde sí se alcanzaron a emitir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ordenes')->where('serie', 'FB2')->update(['serie' => 'FV2']);

        // El contador: si ya existe 'fv2' (base nueva) no se duplica; si existe
        // 'fb2', se conserva su valor para no repetir números.
        $viejo = DB::table('orden_secuencias')->where('grupo', 'fb2')->value('ultimo_numero');

        if ($viejo !== null) {
            $nuevo = DB::table('orden_secuencias')->where('grupo', 'fv2')->value('ultimo_numero');

            if ($nuevo === null) {
                DB::table('orden_secuencias')->insert(['grupo' => 'fv2', 'ultimo_numero' => $viejo]);
            } else {
                DB::table('orden_secuencias')->where('grupo', 'fv2')
                    ->update(['ultimo_numero' => max($viejo, $nuevo)]);
            }

            DB::table('orden_secuencias')->where('grupo', 'fb2')->delete();
        }

        // Textos ya guardados en el historial de ediciones y notificaciones
        DB::statement("UPDATE orden_ediciones SET cambios = REPLACE(cambios, 'FB2', 'FV2') WHERE cambios LIKE '%FB2%'");
        DB::statement("UPDATE notificaciones SET mensaje = REPLACE(mensaje, 'FB2', 'FV2') WHERE mensaje LIKE '%FB2%'");
        DB::statement("UPDATE notificaciones SET titulo  = REPLACE(titulo, 'FB2', 'FV2')  WHERE titulo  LIKE '%FB2%'");
    }

    public function down(): void
    {
        DB::table('ordenes')->where('serie', 'FV2')->update(['serie' => 'FB2']);

        $actual = DB::table('orden_secuencias')->where('grupo', 'fv2')->value('ultimo_numero');
        if ($actual !== null) {
            DB::table('orden_secuencias')->insert(['grupo' => 'fb2', 'ultimo_numero' => $actual]);
            DB::table('orden_secuencias')->where('grupo', 'fv2')->delete();
        }
    }
};
