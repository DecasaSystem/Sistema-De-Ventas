<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que se vendió por WhatsApp, Instagram, Facebook o la página cuenta para
 * la tienda de la persona, no para la tienda donde se registró la orden.
 *
 * Hasta ahora la comisión nacía con la tienda de la orden, canal aparte. En
 * un reemplazo eso le cargaba a Norte lo que Génesis (Unicentro) cerraba por
 * WhatsApp mientras cubría allá. Ver ComisionController::tiendaParaComision.
 *
 * Aquí se pone al día TODO el historial, pagado incluido: lo pagado tiene el
 * monto congelado y no cambia; solo cambia a qué tienda se le cuenta la
 * venta. Se usa la tienda que la persona tiene HOY en el perfil, que es la
 * misma regla que aplica de aquí en adelante.
 *
 * No se deshace: no hay forma de saber cuáles filas se movieron aquí y
 * cuáles ya venían así.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solo las órdenes que pueden cambiar: digitales, de alguien con
        // tienda, y cuya comisión no está en esa tienda. Pasar por todas las
        // demás sería recorrer miles de filas para no tocar ninguna.
        $ids = DB::table('comisiones as c')
            ->join('ordenes as o', 'o.id', '=', 'c.orden_id')
            ->join('usuarios as u', 'u.id', '=', 'c.vendedor_id')
            ->whereColumn('c.vendedor_id', 'o.vendedor_id')
            ->where(fn ($q) => $q->whereNull('c.origen')->orWhere('c.origen', 'venta'))
            ->whereNotNull('o.canal')->where('o.canal', '!=', 'fisica')
            ->whereNotNull('u.tienda_default_id')
            ->whereColumn('c.tienda_id', '!=', 'u.tienda_default_id')
            ->distinct()->pluck('o.id');

        $movidas = 0;
        foreach (Orden::whereIn('id', $ids)->cursor() as $orden) {
            $movidas += ComisionController::sincronizarTienda($orden, inclusoPagadas: true);
        }

        Log::info("Comisiones movidas a la tienda del vendedor por venta digital: {$movidas} (de {$ids->count()} órdenes).");
    }

    public function down(): void
    {
        // Intencionalmente vacío: ver la nota de arriba.
    }
};
