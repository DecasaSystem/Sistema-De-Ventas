<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Usuario;

/**
 * Qué cambios de una orden mueven plata, y quién necesita permiso para hacerlos.
 *
 * Un vendedor no cambia plata solo: el precio de lo vendido, la cantidad, el
 * producto, lo que se regala, lo que se agrega o se quita, los descuentos, el
 * % de anticipo, ni el monto o el medio de un abono. Lo pide, con motivo y
 * soporte, y un supervisor lo aprueba (ver SolicitudCambioController).
 *
 * Se decide comparando con lo guardado, no mirando qué campos llegaron: la
 * pantalla de editar manda siempre el precio y la cantidad de cada producto,
 * los toque o no.
 *
 * Quedan fuera, a propósito:
 *   - el borrador: todavía no es una venta, se está armando;
 *   - ponerle precio a lo que espera cotización ($0 → su precio): es el paso
 *     normal de esas órdenes, no una corrección;
 *   - registrar un abono nuevo: es plata que entra, no un cambio.
 */
class CambiosDePlata
{
    public static function requiereAprobacion(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->rol === 'vendedor' && $orden->estado !== 'borrador';
    }

    /**
     * Días que tiene el vendedor para modificar una orden. Es lo que dice la
     * garantía: pasados, no se cambia nada —ni la tela ni las notas—, porque
     * el mueble ya se empezó a hacer. Antes se cambiaba a los 20 días y el
     * taller trabajaba sobre lo que ya no era.
     */
    public const DIAS_PARA_EDITAR = 5;

    /**
     * ¿Ya no puede este vendedor modificar la orden? Desde que se confirmó la
     * venta (un borrador empieza a contar al completarse), en las ventas
     * normales: una restauración es el mueble del cliente y sigue otro ritmo.
     * Le queda pedir un cambio de dinero con aprobación; el supervisor edita
     * sin límite.
     */
    public static function edicionVencida(Usuario $usuario, Orden $orden): bool
    {
        if ($usuario->rol !== 'vendedor' || $orden->estado === 'borrador') return false;
        if ($orden->serie === Orden::SERIE_RESTAURACION) return false;

        $hasta = self::editableHasta($orden);
        return $hasta !== null && now()->greaterThan($hasta);
    }

    /** Hasta cuándo se puede modificar (para decírselo al vendedor). */
    public static function editableHasta(Orden $orden): ?\Illuminate\Support\Carbon
    {
        $desde = $orden->confirmada_en ?? $orden->created_at;
        return $desde ? \Illuminate\Support\Carbon::parse($desde)->addDays(self::DIAS_PARA_EDITAR) : null;
    }

    public static function mensajeVencida(Orden $orden): string
    {
        return 'Pasaron ' . self::DIAS_PARA_EDITAR . ' días desde que se hizo la orden: ya no se puede modificar, '
             . 'como dice la garantía. Si hace falta un cambio de dinero, pídelo para que lo apruebe un supervisor.';
    }

    /**
     * Lo que cambia de plata en la orden con este payload (el de PATCH
     * /ordenes/{id}), como filas para mostrar: [{label, antes, despues}].
     * Vacío si nada de plata cambia.
     */
    public static function deOrden(Orden $orden, array $payload): array
    {
        $orden->loadMissing('items.producto:id,nombre');
        $filas = [];

        $num = fn ($v) => round((float) ($v ?? 0), 2);
        $distinto = fn ($a, $b) => abs($num($a) - $num($b)) > 0.009;

        if (array_key_exists('descuento_total', $payload) && $distinto($payload['descuento_total'], $orden->descuento_total)) {
            $filas[] = self::fila('Descuento', $num($orden->descuento_total), $num($payload['descuento_total']), 'plata');
        }
        if (array_key_exists('descuento_condicionado_monto', $payload)
            && $distinto($payload['descuento_condicionado_monto'], $orden->descuento_condicionado)) {
            $filas[] = self::fila('Descuento por pago en efectivo', $num($orden->descuento_condicionado), $num($payload['descuento_condicionado_monto']), 'plata');
        }
        if (array_key_exists('anticipo_pct', $payload) && $payload['anticipo_pct'] !== null
            && $distinto($payload['anticipo_pct'], $orden->anticipo_pct)) {
            $filas[] = self::fila('% de anticipo', $num($orden->anticipo_pct) . '%', $num($payload['anticipo_pct']) . '%');
        }

        $porId = $orden->items->keyBy('id');
        foreach ($payload['items'] ?? [] as $cambio) {
            /** @var OrdenItem|null $item */
            $item = $porId->get((int) ($cambio['id'] ?? 0));
            if (! $item) continue;
            $nombre = self::nombreItem($item);

            if (array_key_exists('precio_unitario', $cambio) && $cambio['precio_unitario'] !== null
                && $distinto($cambio['precio_unitario'], $item->precio_unitario)
                // Ponerle precio a lo que espera cotización es el paso normal.
                && ! $item->esperaCotizacion()) {
                $filas[] = self::fila("{$nombre} — precio", $num($item->precio_unitario), $num($cambio['precio_unitario']), 'plata');
            }
            if (array_key_exists('cantidad', $cambio) && $cambio['cantidad'] !== null
                && (int) $cambio['cantidad'] !== (int) $item->cantidad) {
                $filas[] = self::fila("{$nombre} — cantidad", (int) $item->cantidad, (int) $cambio['cantidad']);
            }
            if (array_key_exists('producto_id', $cambio) && $cambio['producto_id'] !== null
                && (int) $cambio['producto_id'] !== (int) $item->producto_id) {
                $nuevo = Producto::whereKey($cambio['producto_id'])->value('nombre') ?? "Producto #{$cambio['producto_id']}";
                $filas[] = self::fila("{$nombre} — producto", $nombre, $nuevo);
            }
            if (array_key_exists('es_regalo', $cambio) && (bool) $cambio['es_regalo'] !== (bool) $item->es_regalo) {
                $filas[] = self::fila("{$nombre} — obsequio", $item->es_regalo ? 'Sí' : 'No', $cambio['es_regalo'] ? 'Sí' : 'No');
            }
        }

        foreach ($payload['items_eliminar'] ?? [] as $id) {
            $item = $porId->get((int) $id);
            if (! $item) continue;
            $filas[] = self::fila('Se quita', self::nombreItem($item) . ' × ' . (int) $item->cantidad . ' @ ' . self::pesos($item->precio_unitario), '—');
        }

        foreach ($payload['items_nuevos'] ?? [] as $nuevo) {
            $nombre = ! empty($nuevo['producto_id'])
                ? (Producto::whereKey($nuevo['producto_id'])->value('nombre') ?? "Producto #{$nuevo['producto_id']}")
                : ($nuevo['nombre_custom'] ?? 'Producto');
            $filas[] = self::fila('Se agrega', '—', $nombre . ' × ' . (int) ($nuevo['cantidad'] ?? 1) . ' @ ' . self::pesos($nuevo['precio_unitario'] ?? 0));
        }

        return $filas;
    }

    /** Lo que cambia de plata en un abono: el monto y el medio. La referencia no. */
    public static function dePago(Pago $pago, array $data): array
    {
        $filas = [];
        $tipo  = $pago->tipo === 'anticipo' ? 'Anticipo' : 'Abono';
        $fecha = $pago->created_at?->format('d/m/Y');

        if (array_key_exists('monto', $data) && abs((float) $data['monto'] - (float) $pago->monto) > 0.009) {
            $filas[] = self::fila("{$tipo} del {$fecha} — monto", round((float) $pago->monto, 2), round((float) $data['monto'], 2), 'plata');
        }
        if (! empty($data['metodo']) && $data['metodo'] !== $pago->metodo) {
            $filas[] = self::fila("{$tipo} del {$fecha} — medio de pago", self::metodo($pago->metodo), self::metodo($data['metodo']));
        }

        return $filas;
    }

    /**
     * Solo lo de plata de un payload de editar orden: lo que va a la solicitud.
     * Lo demás (fotos, notas, fechas, specs) el vendedor lo guarda directo.
     */
    public static function soloPlata(array $payload): array
    {
        $out = array_intersect_key($payload, array_flip([
            'descuento_total', 'descuento_condicionado_monto', 'anticipo_pct', 'items_eliminar', 'items_nuevos',
        ]));
        $items = [];
        foreach ($payload['items'] ?? [] as $i) {
            $plata = array_intersect_key($i, array_flip(['precio_unitario', 'cantidad', 'producto_id', 'es_regalo']));
            if ($plata) $items[] = ['id' => $i['id']] + $plata;
        }
        if ($items) $out['items'] = $items;
        return $out;
    }

    private static function fila(string $label, $antes, $despues, ?string $tipo = null): array
    {
        return array_filter(['label' => $label, 'antes' => $antes, 'despues' => $despues, 'tipo' => $tipo], fn ($v) => $v !== null);
    }

    private static function nombreItem(OrdenItem $item): string
    {
        return $item->producto?->nombre ?? $item->nombre_custom ?? "Producto #{$item->id}";
    }

    private static function pesos($v): string
    {
        return '$' . number_format((float) $v, 0, ',', '.');
    }

    private static function metodo(?string $m): string
    {
        return match ($m) {
            'efectivo'      => 'Efectivo',
            'transferencia' => 'Transferencia',
            'tarjeta'       => 'Tarjeta',
            'addi'          => 'Addi',
            default         => ucfirst((string) $m),
        };
    }
}
