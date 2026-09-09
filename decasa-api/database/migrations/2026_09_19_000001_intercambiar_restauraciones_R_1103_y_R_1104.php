<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo puntual de datos: las restauraciones R-1103 y R-1104 quedaron con el
 * número cambiado. Se piden intercambiadas — la que hoy es R-1104 pasa a ser
 * R-1103 y viceversa.
 *
 * El número de una restauración no está copiado en ninguna otra tabla: sale de
 * `Orden::referencia` a partir de `serie` ('R') + `serie_numero` (ver
 * App\Services\NumeracionOrdenes y OrdenController::asignarNumeroSerie()). Todo
 * lo que lo muestra —detalle, PDF, reportes, comisiones, facturación, taller—
 * lo deriva de ahí, así que basta con cambiar `serie_numero` en las dos filas.
 *
 * Se hace en tres pasos con un número temporal para no tener nunca dos filas
 * con el mismo (serie, serie_numero) ni un instante. `serie_numero` es
 * unsignedInteger sin UNIQUE (solo el índice compuesto idx_ordenes_serie), pero
 * el paso intermedio se respeta igual.
 *
 * Solo actúa si existen EXACTAMENTE las dos: la R-1103 y la R-1104 de serie 'R'.
 * Si ya están como se quieren (o falta alguna), no toca nada y lo dice en el
 * log del deploy.
 */
return new class extends Migration
{
    private const SERIE = 'R';
    private const A = 1103;
    private const B = 1104;
    private const TEMP = 999000; // número que no existe, para el paso intermedio

    public function up(): void
    {
        $ordenA = DB::table('ordenes')->where('serie', self::SERIE)->where('serie_numero', self::A)->first();
        $ordenB = DB::table('ordenes')->where('serie', self::SERIE)->where('serie_numero', self::B)->first();

        if (! $ordenA || ! $ordenB) {
            echo '[R-1103/1104] No están las dos restauraciones (R-' . self::A . ': '
                . ($ordenA ? "orden #{$ordenA->id}" : 'no existe') . ' · R-' . self::B . ': '
                . ($ordenB ? "orden #{$ordenB->id}" : 'no existe') . "). Nada que hacer.\n";
            return;
        }

        if (DB::table('ordenes')->where('serie', self::SERIE)->where('serie_numero', self::TEMP)->exists()) {
            echo '[R-1103/1104] El número temporal R-' . self::TEMP . " ya está ocupado. Abortar y revisar a mano.\n";
            return;
        }

        echo "[R-1103/1104] Antes: R-" . self::A . " = orden #{$ordenA->id} · R-" . self::B . " = orden #{$ordenB->id}\n";

        DB::transaction(function () use ($ordenA, $ordenB) {
            DB::table('ordenes')->where('id', $ordenA->id)->update(['serie_numero' => self::TEMP, 'updated_at' => now()]);
            DB::table('ordenes')->where('id', $ordenB->id)->update(['serie_numero' => self::A, 'updated_at' => now()]);
            DB::table('ordenes')->where('id', $ordenA->id)->update(['serie_numero' => self::B, 'updated_at' => now()]);

            $autorId = DB::table('usuarios')->where('rol', 'supervisor')->value('id')
                    ?? DB::table('usuarios')->value('id');

            if ($autorId && DB::getSchemaBuilder()->hasTable('orden_ediciones')) {
                foreach ([[$ordenA->id, self::A, self::B], [$ordenB->id, self::B, self::A]] as [$id, $de, $a]) {
                    DB::table('orden_ediciones')->insert([
                        'orden_id'   => $id,
                        'usuario_id' => $autorId,
                        'cambios'    => json_encode([[
                            'campo'   => 'numeracion',
                            'label'   => 'Número intercambiado (arreglo de datos)',
                            'antes'   => self::SERIE . '-' . $de,
                            'despues' => self::SERIE . '-' . $a,
                        ]], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                }
            }
        });

        echo "[R-1103/1104] LISTO. Ahora: R-" . self::A . " = orden #{$ordenB->id} · R-" . self::B . " = orden #{$ordenA->id}\n";
    }

    public function down(): void
    {
        // Arreglo de datos puntual: no se revierte automáticamente (volver a
        // correr esta migración deshace el cambio).
    }
};
