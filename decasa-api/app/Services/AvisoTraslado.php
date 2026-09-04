<?php

namespace App\Services;

use App\Events\InventarioActualizado;
use App\Models\Traslado;
use App\Models\Usuario;

/**
 * Cuando un traslado mueve stock de verdad, hay que contarlo.
 *
 * El traslado que hace el supervisor se ejecuta de una: la mercancía sale de una
 * tienda y entra en la otra en el mismo momento, sin nada que aceptar. Eso está
 * bien, pero hasta ahora pasaba en silencio: a la tienda destino no le llegaba
 * ningún aviso y su pantalla de inventario tampoco se refrescaba, así que la
 * mercancía aparecía sola y nadie sabía de dónde. Quien la mandó creía que algo
 * había fallado porque "no les sale nada para aceptar" — y no tenía que salirles,
 * simplemente nadie les avisó.
 */
class AvisoTraslado
{
    /**
     * El stock ya se movió: refresca las pantallas de las dos tiendas y le avisa
     * a la gente de la tienda destino que le llegó mercancía.
     *
     * @param int|null $autorId Quien hizo el traslado; no se avisa a sí mismo.
     */
    public static function llegada(Traslado $traslado, ?int $autorId = null): void
    {
        $traslado->loadMissing([
            'items.producto:id,nombre',
            'tiendaOrigen:id,nombre',
            'tiendaDestino:id,nombre',
        ]);

        self::refrescarInventario($traslado);

        $detalle = self::detalleProductos($traslado);
        if ($detalle === '') {
            return;   // aceptado en cero: no llegó nada, no hay nada que contar
        }

        $nombreOrigen = $traslado->tiendaOrigen?->nombre ?? 'otra tienda';
        $productoIds  = $traslado->items
            ->filter(fn($i) => self::cantidadReal($i) > 0)
            ->pluck('producto_id')
            ->unique()
            ->values();

        foreach (self::genteDeLaTienda($traslado->tienda_destino_id, $autorId) as $usuarioId) {
            NotificacionService::crear(
                tipo:      'traslado_recibido',
                titulo:    'Llegó mercancía a tu tienda',
                mensaje:   "Traslado #{$traslado->id}: llegó {$detalle} desde {$nombreOrigen}. Ya está en tu inventario.",
                datos:     [
                    'traslado_id' => $traslado->id,
                    'tienda_id'   => $traslado->tienda_destino_id,
                    'productos'   => $productoIds,
                ],
                usuarioId: $usuarioId,
            );
        }
    }

    /**
     * Solo el refresco en vivo, sin aviso: se usa cuando quien recibe ya sabe
     * porque acaba de aceptar el traslado con el dedo, pero la tienda de origen
     * sí necesita ver que le bajó el stock.
     */
    public static function refrescarInventario(Traslado $traslado): void
    {
        $traslado->loadMissing('items');

        foreach ($traslado->items as $item) {
            if (self::cantidadReal($item) <= 0) continue;

            try {
                event(new InventarioActualizado(
                    (int) $traslado->tienda_origen_id, (int) $item->producto_id, 'salida'
                ));
                event(new InventarioActualizado(
                    (int) $traslado->tienda_destino_id, (int) $item->producto_id, 'entrada'
                ));
            } catch (\Throwable) {
                // Reverb caído: el stock ya se movió, solo se pierde el refresco.
            }
        }
    }

    /**
     * Quién atiende esa tienda. No es solo el rol vendedor: con `acceso_surtir`
     * un costurero o un ebanista también mueve inventario de su tienda, y es
     * quien va a encontrarse la mercancía.
     */
    private static function genteDeLaTienda(int $tiendaId, ?int $excluirUsuarioId = null)
    {
        return Usuario::where('tienda_default_id', $tiendaId)
            ->where('activo', true)
            ->where(fn($q) => $q->where('rol', 'vendedor')->orWhere('acceso_surtir', true))
            ->when($excluirUsuarioId, fn($q) => $q->where('id', '!=', $excluirUsuarioId))
            ->pluck('id');
    }

    /** "4 Sillas Dubái, 2 Mesas de centro y 1 producto más" */
    private static function detalleProductos(Traslado $traslado): string
    {
        $partes = $traslado->items
            ->filter(fn($i) => self::cantidadReal($i) > 0)
            ->map(fn($i) => self::cantidadReal($i) . ' ' . ($i->producto?->nombre ?? "producto #{$i->producto_id}"))
            ->values();

        if ($partes->isEmpty()) return '';

        $sobran = $partes->count() - 3;
        $texto  = $partes->take(3)->implode(', ');

        return $sobran > 0
            ? "{$texto} y {$sobran} producto(s) más"
            : $texto;
    }

    /**
     * Lo que de verdad llegó. En un traslado aceptado a medias vale la cantidad
     * aceptada, no la que se pidió; en los inmediatos esa columna viene vacía.
     */
    private static function cantidadReal($item): int
    {
        return (int) ($item->cantidad_aceptada ?? $item->cantidad);
    }
}
