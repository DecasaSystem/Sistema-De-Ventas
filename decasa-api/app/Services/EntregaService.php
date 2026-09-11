<?php

namespace App\Services;

use App\Http\Controllers\OrdenController;
use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\Devolucion;
use App\Models\EntregaLinea;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Support\StockVariantes;
use Illuminate\Support\Facades\DB;

/**
 * Entregar: lo que pasa cuando un producto sale de verdad hacia el cliente.
 *
 * Antes esto vivía dentro de `DespachoController::entregar()` y estaba
 * repetido a medias en tres sitios más —la venta directa al crear la orden,
 * el borrador que se completa, y el supervisor marcando "entregado" desde la
 * orden—, cada uno con su propia forma de bajar el stock y ninguno con acta.
 *
 * Ahora hay UNA puerta. Sea el conductor con su ruta, el vendedor entregando
 * él mismo, o el cliente llevándose el reloj del mostrador, todos abren una
 * entrega (`DespachoItem`), dicen qué va en ella (`EntregaLinea`) y la
 * cierran aquí: el stock baja por línea, la producción de esa pieza se
 * cierra, la orden queda en el estado que le toca.
 *
 * Y es por producto: el reloj hoy, el mueble cuando salga del taller.
 */
class EntregaService
{
    /**
     * Abre una entrega directa —sin ruta ni conductor— a nombre de alguien.
     *
     * Crea un despacho sintético (`tipo='directa'`) con su entrega en
     * `pendiente`. De ahí sigue por el mismo camino que la del conductor.
     */
    public static function abrirDirecta(Orden $orden, Usuario $quien, string $estadoDespacho = 'en_ruta'): DespachoItem
    {
        $despacho = Despacho::create([
            'tipo'             => 'directa',
            'entregado_por_id' => $quien->id,
            'supervisor_id'    => $quien->id,
            'estado'           => $estadoDespacho,
            'fecha_despacho'   => now()->toDateString(),
        ]);

        return DespachoItem::create([
            'despacho_id' => $despacho->id,
            'orden_id'    => $orden->id,
            'posicion'    => 1,
            'estado'      => 'pendiente',
        ]);
    }

    /**
     * Qué se puede meter en una entrega de esta orden hoy.
     *
     * @return array<int,int>  [orden_item_id => unidades que faltan]
     */
    public static function entregablesDe(Orden $orden): array
    {
        return $orden->itemsEntregables()
            ->mapWithKeys(fn (OrdenItem $i) => [$i->id => $i->pendienteEntregar()])
            ->all();
    }

    /**
     * Comprueba lo que alguien quiere entregar contra lo que de verdad se
     * puede. Devuelve la lista limpia, o el motivo por el que no cuadra.
     *
     * @param  array<int, array{orden_item_id:int, cantidad:int}>  $pedidas
     * @return array<int,int>|string  [orden_item_id => cantidad] o el error
     */
    public static function validarLineas(Orden $orden, array $pedidas): array|string
    {
        $entregables = self::entregablesDe($orden);
        $items       = $orden->items->keyBy('id');
        $limpias     = [];

        foreach ($pedidas as $l) {
            $id   = (int) ($l['orden_item_id'] ?? 0);
            $cant = (int) ($l['cantidad'] ?? 0);
            if ($cant < 1) continue;

            $item = $items->get($id);
            if (! $item) {
                return 'Se está entregando algo que no es de esta orden.';
            }
            $nombre = $item->nombre_custom ?: ($item->producto?->nombre ?? 'ese producto');

            if (! isset($entregables[$id])) {
                return "\"{$nombre}\" todavía no se puede entregar: "
                    . ($item->pendienteEntregar() <= 0 ? 'ya se entregó.' : 'el taller no lo ha dado por listo.');
            }
            if ($cant > $entregables[$id]) {
                return "De \"{$nombre}\" faltan por entregar {$entregables[$id]}, no {$cant}.";
            }

            $limpias[$id] = ($limpias[$id] ?? 0) + $cant;
        }

        if (! $limpias) {
            return 'No se marcó nada para entregar.';
        }

        return $limpias;
    }

    /**
     * Escribe qué va en esta entrega.
     *
     * Reemplaza lo que hubiera: el formulario se puede mandar dos veces. Lo
     * devuelto se anota como línea aparte, con su resultado, para que la
     * entrega cuente la historia completa: "iban dos, se quedó una".
     *
     * @param array<int,int> $lineas     [orden_item_id => cantidad que va]
     * @param array<int,int> $devueltas  [orden_item_id => cantidad que vuelve]
     */
    public static function fijarLineas(DespachoItem $entrega, array $lineas, array $devueltas = []): void
    {
        $entrega->lineas()->delete();

        foreach ($lineas as $itemId => $cant) {
            $vuelve = min((int) $cant, (int) ($devueltas[$itemId] ?? 0));
            $queda  = (int) $cant - $vuelve;

            if ($queda > 0) {
                EntregaLinea::create([
                    'despacho_item_id' => $entrega->id, 'orden_item_id' => $itemId,
                    'cantidad' => $queda, 'resultado' => EntregaLinea::ENTREGADO,
                ]);
            }
            if ($vuelve > 0) {
                EntregaLinea::create([
                    'despacho_item_id' => $entrega->id, 'orden_item_id' => $itemId,
                    'cantidad' => $vuelve, 'resultado' => EntregaLinea::DEVUELTO,
                ]);
            }
        }
    }

    /**
     * Cierra la entrega: el producto salió.
     *
     * Por cada línea que se quedó en la casa baja el stock de esa unidad,
     * cierra su producción y suma a lo entregado. Lo devuelto no: esa pieza
     * está en la bodega, rota, esperando que decidan.
     *
     * Si la entrega no tiene líneas escritas (una ruta armada antes de las
     * parciales, o un cliente viejo), lleva todo lo que le faltaba a la
     * orden y se pudiera entregar.
     *
     * @return array{se_devolvio_todo:bool, hay_devolucion:bool, estado_orden:string, lineas:int}
     */
    public static function entregar(DespachoItem $entrega, int $quienEntregaId, string $etiquetaCanal): array
    {
        return DB::transaction(function () use ($entrega, $quienEntregaId, $etiquetaCanal) {
            $orden = Orden::with('items.produccion', 'items.producto:id,nombre')
                ->lockForUpdate()->findOrFail($entrega->orden_id);

            // Lo que vuelve en el camión, anotado al subir el acta.
            $devueltoPorItem = Devolucion::where('despacho_item_id', $entrega->id)
                ->get()->groupBy('orden_item_id')
                ->map(fn ($g) => (int) $g->sum('cantidad'))->all();

            if ($entrega->lineas()->doesntExist()) {
                self::fijarLineas($entrega, self::entregablesDe($orden), $devueltoPorItem);
            }

            $lineas = $entrega->lineas()->get();
            $now    = now();

            foreach ($lineas->whereIn('resultado', EntregaLinea::SE_QUEDO) as $linea) {
                $item = $orden->items->firstWhere('id', $linea->orden_item_id);
                if (! $item) continue;

                self::sacarDelInventario($orden, $item, (int) $linea->cantidad, $quienEntregaId, $etiquetaCanal);

                // La pieza fabricada llegó: su producción termina aquí.
                if ($item->produccion && in_array($item->produccion->estado, ['pendiente', 'en_proceso', 'listo', 'retrasado'], true)) {
                    $item->produccion->update(['estado' => 'entregado', 'fecha_real' => $now->toDateString()]);
                }

                $item->increment('cantidad_entregada', (int) $linea->cantidad);
            }

            $hayDevolucion   = ! empty($devueltoPorItem);
            $seDevolvioTodo  = $hayDevolucion && $lineas->whereIn('resultado', EntregaLinea::SE_QUEDO)->isEmpty();

            $entrega->update([
                'estado'       => $seDevolvioTodo ? 'devuelto' : 'entregado',
                'entregado_at' => $now,
            ]);

            $orden->refresh()->load('items.produccion');
            $estado = $orden->estadoTrasEntrega($hayDevolucion);
            $cambios = ['estado' => $estado];
            if ($estado === 'listo_entrega' && ! $orden->listo_entrega_at) {
                $cambios['listo_entrega_at'] = $now;
            }
            $orden->update($cambios);

            $total       = DespachoItem::where('despacho_id', $entrega->despacho_id)->count();
            $completados = DespachoItem::where('despacho_id', $entrega->despacho_id)
                ->whereIn('estado', ['entregado', 'devuelto'])->count();
            if ($completados >= $total) {
                $entrega->despacho()->update(['estado' => 'completado']);
            }

            return [
                'se_devolvio_todo' => $seDevolvioTodo,
                'hay_devolucion'   => $hayDevolucion,
                'estado_orden'     => $estado,
                'lineas'           => $lineas->whereIn('resultado', EntregaLinea::SE_QUEDO)->count(),
            ];
        });
    }

    /**
     * El mostrador: el cliente se lleva estos productos ya, sin acta.
     *
     * Es lo que pasa al crear una venta con "se lo lleva ahora" o cuando el
     * supervisor da algo por entregado desde la orden. Queda como una entrega
     * directa igual que las demás —con quién la hizo y cuándo— y el motivo
     * de que no haya firma escrito en el acta.
     *
     * @param array<int,int> $lineas  [orden_item_id => cantidad]
     */
    public static function entregarEnMostrador(Orden $orden, array $lineas, Usuario $quien, string $motivoSinActa): DespachoItem
    {
        $entrega = self::abrirDirecta($orden, $quien);
        $entrega->update(['firma_omitida_motivo' => $motivoSinActa]);

        self::fijarLineas($entrega, $lineas);
        self::entregar($entrega, $quien->id, 'mostrador');

        return $entrega->fresh();
    }

    /**
     * Deshace una entrega: lo que salió vuelve al inventario y a la cuenta.
     *
     * Solo para entregas directas (mostrador, vendedor, supervisor). La del
     * conductor tiene acta firmada y se corrige desde Despacho.
     */
    public static function revertir(DespachoItem $entrega, Usuario $quien, string $motivo): void
    {
        DB::transaction(function () use ($entrega, $quien, $motivo) {
            $orden = Orden::with('items.produccion')->lockForUpdate()->findOrFail($entrega->orden_id);

            foreach ($entrega->lineas()->whereIn('resultado', EntregaLinea::SE_QUEDO)->get() as $linea) {
                $item = $orden->items->firstWhere('id', $linea->orden_item_id);
                if (! $item) continue;

                self::devolverAlInventario($orden, $item, (int) $linea->cantidad, $quien->id, $motivo);

                if ($item->produccion && $item->produccion->estado === 'entregado') {
                    $item->produccion->update(['estado' => 'listo']);
                }

                $item->update(['cantidad_entregada' => max(0, (int) $item->cantidad_entregada - (int) $linea->cantidad)]);
            }

            // La entrega deja de existir: la historia queda en la edición de
            // la orden, que es donde se mira por qué se deshizo.
            $despacho = $entrega->despacho;
            $entrega->lineas()->delete();
            $entrega->delete();
            $despacho?->delete();
        });
    }

    // ── Inventario ───────────────────────────────────────────────────────────

    /** El producto salió: baja disponible y suelta la reserva. */
    private static function sacarDelInventario(Orden $orden, OrdenItem $item, int $cantidad, int $quienId, string $etiqueta): void
    {
        // Lo fabricado no tiene stock que bajar: nunca lo tuvo. Solo lo de
        // catálogo, que se apartó al vender.
        $tocaStock = ! $item->es_personalizado;
        if (! $tocaStock || ! $item->producto_id) return;

        $origenId = $item->tienda_origen_id ?? $orden->tienda_id;

        if ($item->variante_id) {
            InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $origenId)
                ->update([
                    'cantidad_disponible' => DB::raw("cantidad_disponible - {$cantidad}"),
                    'cantidad_reservada'  => DB::raw("cantidad_reservada - {$cantidad}"),
                ]);
            if ($item->combo_config_id) {
                InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                    ->where('config_id', $item->combo_config_id)->where('tienda_id', $origenId)
                    ->update([
                        'cantidad_disponible' => DB::raw("cantidad_disponible - {$cantidad}"),
                        'cantidad_reservada'  => DB::raw("cantidad_reservada - {$cantidad}"),
                    ]);
            }
        }
        Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $origenId)
            ->update([
                'cantidad_disponible' => DB::raw("cantidad_disponible - {$cantidad}"),
                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$cantidad}"),
            ]);

        // Bajó el stock base: el reparto por tela/medida tiene que seguir
        // cabiendo dentro de lo que quedó.
        StockVariantes::cuadrar((int) $item->producto_id, (int) $origenId, "Entrega orden #{$orden->id}");

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id,
            'tienda_id'   => $origenId,
            'tipo'        => 'salida',
            'cantidad'    => $cantidad,
            'motivo'      => "Entrega orden #{$orden->id} — {$etiqueta}",
            'usuario_id'  => $quienId,
        ]);

        // Aquí es donde el producto sale de verdad del inventario: si era el
        // último, que se sepa.
        OrdenController::notificarSiSeAcabo((int) $item->producto_id, (int) $origenId, $cantidad);
    }

    /** La entrega se deshizo: vuelve al inventario y queda reservado para esta orden. */
    private static function devolverAlInventario(Orden $orden, OrdenItem $item, int $cantidad, int $quienId, string $motivo): void
    {
        $tocaStock = ! $item->es_personalizado;
        if (! $tocaStock || ! $item->producto_id) return;

        $origenId = $item->tienda_origen_id ?? $orden->tienda_id;

        if ($item->variante_id) {
            InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $origenId)
                ->update([
                    'cantidad_disponible' => DB::raw("cantidad_disponible + {$cantidad}"),
                    'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cantidad}"),
                ]);
            if ($item->combo_config_id) {
                InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                    ->where('config_id', $item->combo_config_id)->where('tienda_id', $origenId)
                    ->update([
                        'cantidad_disponible' => DB::raw("cantidad_disponible + {$cantidad}"),
                        'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cantidad}"),
                    ]);
            }
        }
        Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $origenId)
            ->update([
                'cantidad_disponible' => DB::raw("cantidad_disponible + {$cantidad}"),
                'cantidad_reservada'  => DB::raw("cantidad_reservada + {$cantidad}"),
            ]);

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id,
            'tienda_id'   => $origenId,
            'tipo'        => 'entrada',
            'cantidad'    => $cantidad,
            'motivo'      => "Entrega revertida orden #{$orden->id}: {$motivo}",
            'usuario_id'  => $quienId,
        ]);
    }
}
