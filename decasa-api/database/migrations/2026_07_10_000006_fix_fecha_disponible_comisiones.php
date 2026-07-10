<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        // Recalcular fecha_disponible de todos los registros existentes:
        // último día del mes siguiente al mes de venta
        // Ej: mes_venta = '2026-07' → fecha_disponible = '2026-08-31'
        $comisiones = DB::table('comisiones')->get(['id', 'mes_venta']);

        foreach ($comisiones as $c) {
            $fechaDisp = Carbon::createFromFormat('Y-m', $c->mes_venta)
                ->addMonth()
                ->endOfMonth()
                ->toDateString();

            DB::table('comisiones')
                ->where('id', $c->id)
                ->update(['fecha_disponible' => $fechaDisp]);
        }
    }

    public function down(): void
    {
        // No hay rollback útil: restaurar fecha_venta + 1 mes requeriría
        // tener la fecha original, que ya no está disponible fácilmente.
    }
};
