<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cancelar una pieza tiene que sacarla del taller de verdad.
 *
 * Al cancelar una producción solo se le cambiaba el estado a ella; sus pasos
 * seguían como estaban. Y "Mis pasos" busca pasos en proceso sin preguntar por
 * la pieza a la que pertenecen, así que el paso seguía en la lista del ebanista
 * y se podía avanzar. La pieza quedaba atrapada: no salía del taller y tampoco
 * se podía quitar de la orden —al editar se bloquea "porque su producción ya
 * está en curso"—, que es justo lo que uno intenta hacer cuando se dio cuenta
 * de que ese producto no había que fabricarlo.
 *
 * Los pasos ganan el estado `cancelado`. No se borran: lo que se planeó hacer
 * es parte de lo que pasó con esa pieza. Los que alguien ya completó se quedan
 * como completados —ese trabajo se hizo de verdad— y solo se cancela lo que
 * quedaba por delante.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE produccion_pasos MODIFY estado ENUM('pendiente','en_proceso','completado','cancelado') NOT NULL DEFAULT 'pendiente'");

        // Lo que ya está mal en la base: piezas canceladas cuyos pasos siguen
        // vivos. Sin esto, las que ya están atrapadas se quedan así.
        $arreglados = DB::update("
            UPDATE produccion_pasos pp
            JOIN produccion p ON p.id = pp.produccion_id
            SET pp.estado = 'cancelado'
            WHERE p.estado = 'cancelado'
              AND pp.estado IN ('pendiente', 'en_proceso')
        ");

        if ($arreglados) {
            \Log::info("[DECASA] Migración: se cancelaron {$arreglados} pasos que seguían vivos en piezas canceladas.");
        }
    }

    public function down(): void
    {
        // Vuelven a 'pendiente': es lo más cercano en el enum viejo.
        DB::statement("UPDATE produccion_pasos SET estado = 'pendiente' WHERE estado = 'cancelado'");
        DB::statement("ALTER TABLE produccion_pasos MODIFY estado ENUM('pendiente','en_proceso','completado') NOT NULL DEFAULT 'pendiente'");
    }
};
