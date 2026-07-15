<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6 — factor de sugerencia de venta (ver AGENT.md).
 *
 * El cotizador solo calcula el COSTO de fabricación; la ganancia la pone el supervisor.
 * Como referencia, sugiere un precio de venta = costo × factor. El negocio definió ×2.0.
 * Vive en `configuracion` para poder ajustarlo desde la app sin tocar código.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'factor_venta_sugerido'],
            ['valor' => '2.0', 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('configuracion')->where('clave', 'factor_venta_sugerido')->delete();
    }
};
