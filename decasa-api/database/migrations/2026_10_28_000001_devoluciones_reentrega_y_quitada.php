<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dos salidas nuevas para lo que vuelve en el camión (orden #1231):
 *  - `reentrega`: estaba bien, se le vuelve a llevar el mismo.
 *  - `quitada`:   el cliente se queda sin eso; deja de cobrarse.
 *
 * `devoluciones.estado` es ENUM en MySQL: sin agregarlas, decidir daba
 * "Data truncated for column 'estado'". Solo se agregan valores; los que ya
 * hay no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','a_produccion','reembolsada','cambio','cambio_mismo','reentrega','quitada') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("UPDATE devoluciones SET estado = 'cambio' WHERE estado IN ('reentrega','quitada')");
        DB::statement("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','a_produccion','reembolsada','cambio','cambio_mismo') NOT NULL DEFAULT 'pendiente'");
    }
};
