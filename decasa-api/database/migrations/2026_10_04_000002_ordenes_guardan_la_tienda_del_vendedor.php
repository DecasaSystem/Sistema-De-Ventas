<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada orden guarda de qué tienda era el vendedor cuando vendió.
 *
 * Una venta digital cuenta para la tienda del vendedor (ver
 * ComisionController::tiendaParaComision), y se miraba la de HOY. Esa cuenta
 * se rehace con cada pago, cambio de canal o "Recalcular", así que al pasar a
 * alguien de tienda sus ventas viejas se iban con él. Pasó con la #4308:
 * Manuela la vendió el 31 de agosto en Tienda Virtual, el 1 de septiembre
 * pasó al Norte, y la venta terminó sumándole al Norte.
 *
 * Para lo que ya existe se toma lo que está rigiendo: en las digitales, la
 * tienda donde está hoy la comisión del vendedor; en las físicas, la de la
 * orden. Y se corrige lo de Manuela: todo lo que vendió antes del 1 de
 * septiembre es de Tienda Virtual, y sus comisiones sin pagar vuelven allá.
 */
return new class extends Migration
{
    private const TZ = 'America/Bogota';

    public function up(): void
    {
        if (! Schema::hasColumn('ordenes', 'tienda_vendedor_id')) {
            Schema::table('ordenes', function (Blueprint $t) {
                $t->unsignedBigInteger('tienda_vendedor_id')->nullable()->after('tienda_id');
            });
        }

        // Físicas (y todas, de base): la tienda de la orden.
        DB::table('ordenes')->whereNotNull('vendedor_id')->whereNull('tienda_vendedor_id')
            ->update(['tienda_vendedor_id' => DB::raw('tienda_id')]);

        // Digitales: donde está hoy la comisión del vendedor, que es lo que rige.
        $filas = DB::table('ordenes as o')
            ->join('comisiones as c', function ($j) {
                $j->on('c.orden_id', '=', 'o.id')->on('c.vendedor_id', '=', 'o.vendedor_id');
            })
            ->whereNotNull('o.canal')->where('o.canal', '!=', 'fisica')
            ->where(fn ($q) => $q->whereNull('c.origen')->orWhere('c.origen', 'venta'))
            ->select('o.id', 'c.tienda_id')
            ->get();
        foreach ($filas as $f) {
            DB::table('ordenes')->where('id', $f->id)->update(['tienda_vendedor_id' => $f->tienda_id]);
        }

        $this->devolverLoDeManuelaATiendaVirtual();
    }

    private function devolverLoDeManuelaATiendaVirtual(): void
    {
        $virtual = DB::table('tiendas')->where('nombre', 'Tienda Virtual')->value('id');
        if (! $virtual) {
            echo "[Manuela] No hay Tienda Virtual. Nada que hacer.\n";
            return;
        }

        // Manuela, por la #4308 de Tienda Virtual (el número normal se repite
        // entre grupos de tiendas, por eso va con la tienda).
        $manuela = DB::table('ordenes')->where('numero_orden', 4308)->whereNull('serie')
            ->where('tienda_id', $virtual)->value('vendedor_id');
        if (! $manuela) {
            echo "[Manuela] No se encontró la #4308 de Tienda Virtual. Nada que hacer.\n";
            return;
        }

        $corte = Carbon::parse('2026-09-01 00:00:00', self::TZ)->setTimezone('UTC')->toDateTimeString();

        $ids = DB::table('ordenes')->where('vendedor_id', $manuela)
            ->where('created_at', '<', $corte)->pluck('id');

        DB::table('ordenes')->whereIn('id', $ids)->update(['tienda_vendedor_id' => $virtual]);

        // Sus comisiones sin pagar vuelven a donde tocan con la regla nueva.
        $movidas = 0;
        foreach (Orden::whereIn('id', $ids)->whereNotNull('canal')->where('canal', '!=', 'fisica')->get() as $orden) {
            $movidas += ComisionController::sincronizarTienda($orden);
        }

        echo "[Manuela] {$ids->count()} órdenes antes del 1 de septiembre quedan como de Tienda Virtual; "
            . "{$movidas} comisiones movidas.\n";
    }

    public function down(): void
    {
        if (Schema::hasColumn('ordenes', 'tienda_vendedor_id')) {
            Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('tienda_vendedor_id'));
        }
    }
};
