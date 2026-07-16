<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    // Tiendas de Pereira: la comisión se paga trimestral en vez de mensual.
    private const TIENDAS_TRIMESTRALES = ['Decasa Unicentro Pereira', 'Decasa Circunvalar'];

    public function up(): void
    {
        // Recalcular fecha_disponible de las comisiones aún no pagadas:
        // - Mensual: día 20 del mes siguiente a la venta.
        // - Trimestral (Pereira): día 20 del mes siguiente al cierre del
        //   trimestre calendario (mar/jun/sep/dic) en que cae la venta.
        $tiendas = DB::table('tiendas')->pluck('nombre', 'id');

        $comisiones = DB::table('comisiones')
            ->where('estado', '!=', 'pagada')
            ->get(['id', 'tienda_id', 'fecha_venta']);

        foreach ($comisiones as $c) {
            $fechaVenta   = Carbon::parse($c->fecha_venta);
            $tiendaNombre = $tiendas[$c->tienda_id] ?? null;

            if (in_array($tiendaNombre, self::TIENDAS_TRIMESTRALES, true)) {
                $mesCierre  = intdiv($fechaVenta->month - 1, 3) * 3 + 3;
                $fechaDisp  = Carbon::create($fechaVenta->year, $mesCierre, 1)
                    ->addMonth()->day(20)->toDateString();
            } else {
                $fechaDisp = Carbon::create($fechaVenta->year, $fechaVenta->month, 1)
                    ->addMonth()->day(20)->toDateString();
            }

            DB::table('comisiones')->where('id', $c->id)->update(['fecha_disponible' => $fechaDisp]);
        }
    }

    public function down(): void
    {
        // No hay rollback útil: restaurar la regla previa (fin de mes siguiente)
        // perdería la distinción trimestral ya aplicada.
    }
};
