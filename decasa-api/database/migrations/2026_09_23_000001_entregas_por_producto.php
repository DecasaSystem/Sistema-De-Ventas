<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Se entregan productos, no órdenes.
 *
 * Un cliente compra un reloj que está en la tienda y un mueble que hay que
 * fabricar. Hasta ahora no había forma de decir "el reloj ya se lo llevó, el
 * mueble va después": la entrega era de la orden entera —un `despacho_item`
 * es una orden en un despacho— y cualquier camino que marcaba "entregado"
 * descontaba el stock y cerraba la producción de todo a la vez.
 *
 * `entrega_lineas` dice qué productos y cuántas unidades fueron en CADA
 * entrega. Un `despacho_item` pasa a ser "una entrega" (acta, fotos, pago,
 * quién entregó) y una orden puede tener varias. La orden queda `entregado`
 * solo cuando todo lo vivo está entregado; mientras tanto se queda en su
 * estado natural y muestra "entrega parcial 1/2".
 *
 * `orden_items.cantidad_entregada` es una caché de esas líneas, para no sumar
 * al listar. `llevar_ahora` es la marca de "se lo lleva de una" por producto,
 * que antes era de toda la orden (`ordenes.entrega_inmediata`) y rechazaba la
 * venta si había algo para fabricar.
 *
 * Lo ya entregado se rellena: cada entrega hecha se traduce a sus líneas
 * (menos lo que volvió en el camión), y las órdenes entregadas sin despacho
 * —venta directa, supervisor a mano— quedan con todo como entregado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrega_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despacho_item_id')->constrained('despacho_items')->cascadeOnDelete();
            $table->foreignId('orden_item_id')->constrained('orden_items')->cascadeOnDelete();
            $table->unsignedInteger('cantidad');
            // entregado: se quedó en la casa. con_novedad: se quedó pero llegó
            // con algo. devuelto: volvió en el camión (ver `devoluciones`).
            $table->enum('resultado', ['entregado', 'con_novedad', 'devuelto'])->default('entregado');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['orden_item_id', 'resultado']);
        });

        Schema::table('orden_items', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_entregada')->default(0)->after('cantidad');
            $table->boolean('llevar_ahora')->default(false)->after('usa_stock_tienda');
        });

        // ── Lo ya entregado ──────────────────────────────────────────────────
        // 1. Cada entrega hecha por despacho: todos los productos de la orden,
        //    menos lo que ese despacho devolvió.
        $entregas = DB::table('despacho_items')->where('estado', 'entregado')->get(['id', 'orden_id']);
        foreach ($entregas as $e) {
            $devueltas = DB::table('devoluciones')->where('despacho_item_id', $e->id)
                ->selectRaw('orden_item_id, SUM(cantidad) as n')->groupBy('orden_item_id')
                ->pluck('n', 'orden_item_id');

            foreach (DB::table('orden_items')->where('orden_id', $e->orden_id)->get(['id', 'cantidad']) as $oi) {
                $entregadas = (int) $oi->cantidad - (int) ($devueltas[$oi->id] ?? 0);
                if ($entregadas > 0) {
                    DB::table('entrega_lineas')->insert([
                        'despacho_item_id' => $e->id, 'orden_item_id' => $oi->id,
                        'cantidad' => $entregadas, 'resultado' => 'entregado', 'created_at' => now(),
                    ]);
                }
                if (($devueltas[$oi->id] ?? 0) > 0) {
                    DB::table('entrega_lineas')->insert([
                        'despacho_item_id' => $e->id, 'orden_item_id' => $oi->id,
                        'cantidad' => (int) $devueltas[$oi->id], 'resultado' => 'devuelto', 'created_at' => now(),
                    ]);
                }
            }
        }

        // 2. La caché sale de las líneas...
        DB::statement('
            UPDATE orden_items oi
            SET cantidad_entregada = (
                SELECT COALESCE(SUM(el.cantidad), 0) FROM entrega_lineas el
                WHERE el.orden_item_id = oi.id AND el.resultado IN ("entregado", "con_novedad")
            )
        ');

        // 3. ...y las órdenes entregadas sin ninguna entrega registrada (venta
        //    directa, o el supervisor la marcó a mano) quedan con todo entregado.
        //    Lo devuelto para cambio (`devuelto_en`) no cuenta: ya no es de la orden.
        DB::statement('
            UPDATE orden_items oi
            JOIN ordenes o ON o.id = oi.orden_id
            SET oi.cantidad_entregada = oi.cantidad
            WHERE o.estado = "entregado" AND oi.devuelto_en IS NULL
              AND oi.cantidad_entregada < oi.cantidad
        ');
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            $table->dropColumn(['cantidad_entregada', 'llevar_ahora']);
        });
        Schema::dropIfExists('entrega_lineas');
    }
};
