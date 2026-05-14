<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE inventario_movimientos MODIFY COLUMN tipo ENUM('entrada','salida','reserva','liberacion','traslado_salida','traslado_entrada') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inventario_movimientos MODIFY COLUMN tipo ENUM('entrada','salida','reserva','liberacion') NOT NULL");
    }
};
