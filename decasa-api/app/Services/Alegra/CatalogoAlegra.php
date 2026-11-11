<?php

namespace App\Services\Alegra;

use Illuminate\Support\Facades\Cache;

/**
 * Lo que hay hoy en Alegra (cajas/bancos, impuestos, productos), para revisar
 * que lo escogido en Ajustes siga sirviendo.
 *
 * El caso que lo motivó (2026-11-11): en Ajustes salían tiendas desactivadas;
 * lo mismo pasaba con lo que viene de Alegra. Un banco o un producto que se
 * desactiva en Alegra después de escogerlo hacía fallar la factura al emitir,
 * sin decir por qué. Y si escogían "IVA 5%" o "ARRENDAMIENTO" como impuesto,
 * el sistema calculaba con 19 % y Alegra con otro: el total no cuadraba.
 *
 * Se recuerda 10 minutos: la revisión de una factura se abre muchas veces.
 */
class CatalogoAlegra
{
    private const MINUTOS = 10;

    public function __construct(private ClienteAlegra $alegra) {}

    /** @return array<string, array>|null por id; null si no se pudo preguntar */
    public function cuentas(): ?array
    {
        return $this->lista('bank-accounts');
    }

    /** @return array<string, array>|null por id */
    public function impuestos(): ?array
    {
        return $this->lista('taxes');
    }

    /** El producto/servicio de Alegra, o null si no se pudo preguntar. ['_no_existe' => true] si Alegra dice 404. */
    public function item(string $id): ?array
    {
        $clave = 'alegra_item_' . md5($id);
        if (is_array($g = Cache::get($clave))) return $g;

        try {
            $r = $this->alegra->get('items/' . rawurlencode($id));
        } catch (SinRespuestaDeAlegra) {
            return null;
        }
        if ($r->status === 404) $dato = ['_no_existe' => true];
        elseif ($r->ok && is_array($r->json)) $dato = $r->json;
        else return null;

        Cache::put($clave, $dato, now()->addMinutes(self::MINUTOS));

        return $dato;
    }

    public static function activo(array $x): bool
    {
        return ! isset($x['status']) || $x['status'] === 'active';
    }

    /**
     * ¿Alegra lleva inventario de este producto? Si sí, cada factura le descuenta
     * existencias (y si no deja inventario negativo, la factura falla). El
     * inventario lo lleva el sistema: el producto genérico debe ser sin inventario.
     */
    public static function llevaInventario(array $item): bool
    {
        if (($item['type'] ?? null) === 'service') return false;
        $inv = $item['inventory'] ?? null;

        return is_array($inv) && (array_key_exists('availableQuantity', $inv) || array_key_exists('unitCost', $inv) || array_key_exists('initialQuantity', $inv));
    }

    /** Olvida lo recordado (al guardar Ajustes, para que se vea al instante). */
    public static function olvidar(): void
    {
        Cache::forget('alegra_lista_bank-accounts');
        Cache::forget('alegra_lista_taxes');
    }

    private function lista(string $ruta): ?array
    {
        $clave = "alegra_lista_{$ruta}";
        if (is_array($g = Cache::get($clave))) return $g;
        if (! ClienteAlegra::configurado()) return null;

        try {
            $r = $this->alegra->get($ruta);
        } catch (SinRespuestaDeAlegra) {
            return null;
        }
        if (! $r->ok || ! is_array($r->json)) return null;

        $porId = [];
        foreach ($r->json as $x) {
            if (is_array($x) && isset($x['id'])) $porId[(string) $x['id']] = $x;
        }
        Cache::put($clave, $porId, now()->addMinutes(self::MINUTOS));

        return $porId;
    }
}
