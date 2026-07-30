<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\Usuario;

/**
 * Facturación tiene que enterarse de cualquier cambio en la plata de una orden.
 *
 * No basta con dejarlo en el historial: si el anticipo se corrige o el cliente
 * termina pagando con tarjeta después de haber dicho transferencia, quien lleva
 * las cuentas necesita saberlo en el momento, no cuando cuadra la caja.
 */
class AvisoFacturacion
{
    /**
     * Campos de orden_ediciones que representan plata. El resto (dirección,
     * canal, notas…) no le cambia las cuentas a nadie.
     */
    private const CAMPOS_DE_DINERO = [
        'valor_total',
        'descuento_total',
        'descuento_condicionado',
    ];

    /** Prefijos de campos por ítem/pago que sí mueven el total. */
    private const PATRONES_DE_DINERO = [
        '/^pago_\d+_(monto|metodo)$/',
        '/^item_\d+_(precio|cantidad|producto)$/',
        '/^item_(nuevo|eliminado)/',
    ];

    public static function esCambioDeDinero(array $cambio): bool
    {
        $campo = $cambio['campo'] ?? '';

        if (in_array($campo, self::CAMPOS_DE_DINERO, true)) {
            return true;
        }
        foreach (self::PATRONES_DE_DINERO as $patron) {
            if (preg_match($patron, $campo)) return true;
        }

        return false;
    }

    /**
     * Quién debe enterarse: facturación Y supervisión, siempre.
     *
     * No es redundancia. Hoy la bandera de facturación la tiene una sola
     * persona; si ese día no entra al programa, el aviso de que cambió la plata
     * no lo lee nadie y el descuadre aparece cuando se cierra la caja. Una
     * notificación de dinero que no llega a nadie es peor que una de más.
     */
    public static function destinatarios(?int $excluirUsuarioId = null): \Illuminate\Support\Collection
    {
        return Usuario::where('activo', true)
            ->where(fn($q) => $q->where('facturacion', true)->orWhere('rol', 'supervisor'))
            ->when($excluirUsuarioId, fn($q) => $q->where('id', '!=', $excluirUsuarioId))
            ->get();
    }

    /**
     * Avisa de un cambio de plata ya aplicado.
     *
     * @param array $cambios Mismo formato que orden_ediciones: campo/label/antes/despues.
     */
    public static function cambioDeDinero(Orden $orden, Usuario $autor, array $cambios, string $contexto = ''): void
    {
        $deDinero = array_values(array_filter($cambios, [self::class, 'esCambioDeDinero']));
        if (empty($deDinero)) {
            return;
        }

        $orden->loadMissing('cliente');
        $cliente = $orden->cliente?->nombre ?? 'cliente';

        $detalle = collect($deDinero)
            ->map(fn($c) => self::describir($c))
            ->filter()
            ->take(3)
            ->implode(' · ');

        $sobran = count($deDinero) - 3;
        if ($sobran > 0) {
            $detalle .= " · y {$sobran} cambio(s) más";
        }

        $mensaje = "Orden {$orden->referencia} de {$cliente}: {$detalle}. Lo cambió {$autor->nombre}."
            . ($contexto ? " ({$contexto})" : '')
            . ' Saldo pendiente: ' . self::pesos($orden->saldoPendiente()) . '.';

        foreach (self::destinatarios($autor->id) as $d) {
            NotificacionService::crear(
                tipo:      'cambio_dinero',
                titulo:    "Cambió la plata — Orden {$orden->referencia}",
                mensaje:   $mensaje,
                datos:     ['orden_id' => $orden->id, 'cambios' => $deDinero],
                usuarioId: $d->id,
                urgente:   true,
            );
        }
    }

    /**
     * Aviso de una orden que se cancela teniendo plata recibida: hay que decidir
     * qué se hace con lo que ya pagó el cliente.
     */
    public static function ordenCanceladaConPagos(Orden $orden, Usuario $autor): void
    {
        $pagado = $orden->totalPagado();
        if ($pagado <= 0) {
            return;
        }

        $orden->loadMissing('cliente');
        $cliente = $orden->cliente?->nombre ?? 'cliente';

        foreach (self::destinatarios($autor->id) as $d) {
            NotificacionService::crear(
                tipo:      'cambio_dinero',
                titulo:    "Orden cancelada con plata recibida — {$orden->referencia}",
                mensaje:   "Orden {$orden->referencia} de {$cliente} se canceló ({$autor->nombre}) "
                         . 'y ya tenía ' . self::pesos($pagado) . ' pagados. Hay que definir qué pasa con ese dinero.',
                datos:     ['orden_id' => $orden->id, 'total_pagado' => $pagado],
                usuarioId: $d->id,
                urgente:   true,
            );
        }
    }

    /** "Anticipo — monto: $500.000 → $1.500.000" */
    private static function describir(array $cambio): string
    {
        $label  = $cambio['label']   ?? $cambio['campo'] ?? 'cambio';
        $antes  = $cambio['antes']   ?? null;
        $despues = $cambio['despues'] ?? null;

        $fmt = function ($v) {
            if ($v === null || $v === '') return '—';
            if (is_bool($v)) return $v ? 'sí' : 'no';
            return is_numeric($v) ? self::pesos((float) $v) : (string) $v;
        };

        return "{$label}: {$fmt($antes)} → {$fmt($despues)}";
    }

    private static function pesos(float $v): string
    {
        return '$' . number_format($v, 0, ',', '.');
    }
}
