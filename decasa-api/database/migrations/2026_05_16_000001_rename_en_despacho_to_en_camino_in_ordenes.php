<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Paso 1: ampliar el ENUM para aceptar ambos valores temporalmente
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_despacho','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");

        // Paso 2: migrar datos existentes
        DB::statement("UPDATE ordenes SET estado = 'en_camino' WHERE estado = 'en_despacho'");

        // Paso 3: quitar el valor viejo del ENUM
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_despacho','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");
        DB::statement("UPDATE ordenes SET estado = 'en_despacho' WHERE estado = 'en_camino'");
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_despacho','entregado','cancelado') NOT NULL DEFAULT 'pendiente_anticipo'");
    }
};
