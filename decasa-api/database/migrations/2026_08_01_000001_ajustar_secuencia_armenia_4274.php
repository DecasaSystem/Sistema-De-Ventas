<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Armenia arranca en firme desde hoy y su talonario va por la 4273, así que la
 * próxima orden del sistema tiene que ser la 4274.
 *
 * El contador del sistema venía en 4265 (los números del 4266 al 4273 se usaron
 * fuera del sistema), por eso se sube y no se baja.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GREATEST para que nunca baje: si mientras tanto se emitieron números
        // más altos, bajarlo repetiría consecutivos ya entregados a un cliente.
        DB::statement("
            INSERT INTO orden_secuencias (grupo, ultimo_numero)
            VALUES ('armenia', 4273)
            ON DUPLICATE KEY UPDATE ultimo_numero = GREATEST(ultimo_numero, 4273)
        ");
    }

    public function down(): void
    {
        // No se revierte: bajar el contador provocaría números duplicados.
    }
};
