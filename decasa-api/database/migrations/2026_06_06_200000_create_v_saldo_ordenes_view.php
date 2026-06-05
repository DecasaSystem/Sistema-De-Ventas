<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE OR REPLACE VIEW v_saldo_ordenes AS
            SELECT
                o.id                                            AS orden_id,
                o.valor_total,
                COALESCE(SUM(p.monto), 0)                      AS total_pagado,
                o.valor_total - COALESCE(SUM(p.monto), 0)     AS saldo_pendiente
            FROM ordenes o
            LEFT JOIN pagos p ON p.orden_id = o.id
            GROUP BY o.id, o.valor_total
        ');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_saldo_ordenes');
    }
};
