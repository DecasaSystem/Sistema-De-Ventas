<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_cotizacion','pendiente_anticipo','en_produccion','listo_entrega','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");
    }
};
