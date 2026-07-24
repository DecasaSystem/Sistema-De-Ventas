<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustar el contador de Armenia a 4260 para que la siguiente orden
        // que se cree tome el número 4261.
        // INSERT OR UPDATE idempotente (nunca baja el contador si ya subió).
        DB::statement("
            INSERT INTO orden_secuencias (grupo, ultimo_numero)
            VALUES ('armenia', 4260)
            ON DUPLICATE KEY UPDATE ultimo_numero = GREATEST(ultimo_numero, 4260)
        ");
    }

    public function down(): void
    {
        // No se revierte: bajar el contador provocaría números duplicados.
    }
};
