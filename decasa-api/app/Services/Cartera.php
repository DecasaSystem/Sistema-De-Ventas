<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La cartera: las órdenes que todavía deben plata.
 *
 * Una sola regla para la lista en pantalla y para el Excel. Antes cada una
 * tenía la suya y no cuadraban: la lista sí mostraba las entregadas que
 * deben (el mueble salió, la plata no) y el Excel las dejaba fuera; y el Excel
 * no miraba el saldo, así que metía órdenes ya pagadas por completo.
 *
 * Entra toda orden con saldo por cobrar, entregada o no, salvo las canceladas
 * y lo que todavía no es una venta (borrador, cotización).
 *
 * Quién ve cuál:
 *   - Vendedor de tienda: la de su tienda —la de sus compañeros también—, más
 *     sus propias ventas si alguna quedó en otra tienda.
 *   - Vendedor independiente: solo lo suyo. Los independientes comparten una
 *     "sede" pero cada uno lleva su caja y sus clientes.
 *   - Los demás (supervisor…): todas, o la tienda que elijan.
 */
class Cartera
{
    /**
     * @return Collection<int, array> una fila por orden, de la que más debe a la que menos
     */
    public static function de(Usuario $usuario, ?int $tiendaId = null): Collection
    {
        $pagado = '(SELECT COALESCE(SUM(p.monto), 0) FROM pagos p WHERE p.orden_id = o.id)';

        $q = DB::table('ordenes as o')
            ->leftJoin('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'o.vendedor_id')
            ->leftJoin('tiendas as t',  't.id', '=', 'o.tienda_id')
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            ->whereRaw("o.valor_total - {$pagado} > 0")
            ->selectRaw("
                o.id, o.estado, o.created_at, o.valor_total,
                o.numero_orden, o.serie, o.serie_numero, o.numero_anulado, o.cotizacion_numero,
                c.nombre   AS cliente,
                c.telefono AS telefono,
                u.nombre   AS vendedor,
                t.nombre   AS tienda,
                {$pagado}  AS total_pagado
            ");

        if ($usuario->rol === 'vendedor') {
            $q->where(function ($w) use ($usuario) {
                $w->where('o.vendedor_id', $usuario->id)
                  ->orWhere('o.covendedor_id', $usuario->id);
                if (! $usuario->independiente && $usuario->tienda_default_id) {
                    $w->orWhere('o.tienda_id', $usuario->tienda_default_id);
                }
            });
        } elseif ($tiendaId) {
            $q->where('o.tienda_id', $tiendaId);
        }

        $hoy = Carbon::today();

        return $q->get()
            ->map(function ($o) use ($hoy) {
                $pagadoOrden = (float) $o->total_pagado;
                // El número que tiene en papel (#2567, R-1098, FV2-45): el mismo
                // que arma el modelo, para que la cartera diga lo que dice la orden.
                $referencia = (new Orden)->forceFill([
                    'estado'            => $o->estado,
                    'numero_orden'      => $o->numero_orden,
                    'serie'             => $o->serie,
                    'serie_numero'      => $o->serie_numero,
                    'numero_anulado'    => $o->numero_anulado,
                    'cotizacion_numero' => $o->cotizacion_numero,
                ])->referencia;

                return [
                    'orden_id'        => (int) $o->id,
                    'referencia'      => $referencia,
                    'estado'          => $o->estado,
                    'created_at'      => $o->created_at,
                    'cliente'         => $o->cliente,
                    'telefono'        => $o->telefono,
                    'vendedor'        => $o->vendedor,
                    'tienda'          => $o->tienda,
                    'valor_total'     => (float) $o->valor_total,
                    'total_pagado'    => $pagadoOrden,
                    'saldo_pendiente' => round((float) $o->valor_total - $pagadoOrden, 2),
                    'dias_sin_pagar'  => $o->created_at
                        ? (int) Carbon::parse($o->created_at)->startOfDay()->diffInDays($hoy)
                        : null,
                ];
            })
            ->sortByDesc('saldo_pendiente')
            ->values();
    }
}
