<?php

namespace App\Services\Finanzas;

use App\Models\Gasto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los gastos por mes, en las dos lentes:
 *
 * - DEVENGADO (estado de resultados): cada gasto se reparte entre los meses
 *   que cubre (`cubre_desde`/`cubre_hasta`): el recibo de luz de septiembre
 *   pagado en octubre es de septiembre; la licencia anual pone 1/12 en cada mes.
 * - CAJA (flujo): el mes en que se pagó (`fecha_pago`).
 *
 * Más las COMPRAS del taller (`compras` ya compradas con precio): entran como
 * categoría "Compras de taller" en el mes de la compra. No se registran otra
 * vez como gasto, y no se suman con el costo de producción por fichas (eso es
 * otra lente: §3.5 del plan).
 *
 * La caja de las tiendas NO se lee (decisión del dueño, 2026-10-09): es el
 * efectivo de las ventas, que ya entra por `pagos`.
 */
class FuenteGastos
{
    public const CATEGORIA_COMPRAS = [
        'id' => 'compras', 'nombre' => 'Compras de taller', 'naturaleza' => 'variable',
        'varia_con_ventas' => true, 'area' => 'produccion', 'icono' => 'ShoppingCartIcon',
    ];

    /**
     * @return array<string, array> [mes => devengado, caja, fijos, variables,
     *         varia_con_ventas, por_area, por_categoria, compras]
     */
    public static function porMes(string $desde, string $hasta, ?int $tiendaId = null): array
    {
        return Periodo::porMesRecordado("gastos:$tiendaId", $desde, $hasta,
            fn ($d, $h) => self::cargar($d, $h, $tiendaId));
    }

    private static function cargar(string $desde, string $hasta, ?int $tiendaId): array
    {
        [$ini] = Periodo::limites($desde);
        [, $fin] = Periodo::limites($hasta);
        $meses = array_flip(Periodo::meses($desde, $hasta));
        $out = [];

        $gastos = Gasto::with('categoria')
            ->where('estado', Gasto::PAGADO)
            ->where(fn ($q) => $q
                ->where(fn ($q3) => $q3->whereDate('fecha_pago', '>=', $ini->toDateString())->whereDate('fecha_pago', '<=', $fin->toDateString()))
                ->orWhere(fn ($q2) => $q2->whereDate('cubre_desde', '<=', $fin->toDateString())
                                         ->whereDate('cubre_hasta', '>=', $ini->toDateString())))
            ->when($tiendaId, fn ($q) => $q->where('tienda_id', $tiendaId))
            ->get();

        foreach ($gastos as $g) {
            $cat = $g->categoria;
            $info = [
                'id' => $cat?->id, 'nombre' => $cat?->nombre ?? 'Sin categoría',
                'naturaleza' => $cat?->naturaleza ?? 'variable', 'varia_con_ventas' => (bool) $cat?->varia_con_ventas,
                'area' => $cat?->area ?? 'administracion', 'icono' => $cat?->icono,
            ];

            // El abono a una factura de proveedor es solo caja: el gasto ya se
            // contó con la factura, el mes en que llegó (abajo).
            if (! $g->cuenta_por_pagar_id) {
                foreach (Periodo::repartirGasto($g->cubre_desde, $g->cubre_hasta) as $mes => $fraccion) {
                    if (isset($meses[$mes])) self::sumar($out, $mes, $info, (float) $g->monto * $fraccion, false);
                }
            }

            $mesPago = Periodo::mesDe($g->fecha_pago);
            if (isset($meses[$mesPago])) {
                $out[$mesPago] ??= self::vacio();
                $out[$mesPago]['caja'] += (float) $g->monto;
            }
        }

        // Las facturas de proveedores a crédito: gasto del mes de la factura,
        // se paguen cuando se paguen.
        if (\App\Support\Esquema::tabla('cuentas_por_pagar')) {
            $facturas = \App\Models\CuentaPorPagar::with('categoria')->where('estado', '!=', 'anulada')
                ->whereDate('fecha_factura', '>=', $ini->toDateString())->whereDate('fecha_factura', '<=', $fin->toDateString())
                ->when($tiendaId, fn ($q) => $q->where('tienda_id', $tiendaId))
                ->get();
            foreach ($facturas as $f) {
                $mes = Periodo::mesDe($f->fecha_factura);
                if (! isset($meses[$mes])) continue;
                $cat = $f->categoria;
                self::sumar($out, $mes, [
                    'id' => $cat?->id, 'nombre' => $cat?->nombre ?? 'Sin categoría',
                    'naturaleza' => $cat?->naturaleza ?? 'variable', 'varia_con_ventas' => (bool) $cat?->varia_con_ventas,
                    'area' => $cat?->area ?? 'administracion', 'icono' => $cat?->icono,
                ], (float) $f->monto, false);
            }
        }

        // Las compras del taller: en el mes en que se compraron.
        if (! $tiendaId && \App\Support\Esquema::tabla('compras')) {
            $compras = DB::table('compras')->where('estado', 'comprado')->whereNotNull('precio')
                ->whereDate('fecha_compra', '>=', $ini->toDateString())->whereDate('fecha_compra', '<=', $fin->toDateString())
                ->get(['fecha_compra', 'precio']);
            foreach ($compras as $c) {
                $mes = Periodo::mesDe($c->fecha_compra);
                if (! isset($meses[$mes])) continue;
                self::sumar($out, $mes, self::CATEGORIA_COMPRAS, (float) $c->precio, true);
                $out[$mes]['caja'] += (float) $c->precio;
            }
        }

        foreach ($out as $mes => $m) {
            $out[$mes]['por_categoria'] = collect($m['por_categoria'])
                ->map(fn ($c) => ['monto' => round($c['monto'])] + $c)
                ->sortByDesc('monto')->values()->all();
        }

        return $out;
    }

    /**
     * Las facturas de proveedores que se deben (con saldo), por fecha de
     * vencimiento. Para el calendario y el flujo de caja.
     *
     * @return array<int, array{id: int, proveedor: ?string, concepto: string, numero: ?string, vence: string, saldo: float}>
     */
    public static function facturasPorPagar(): array
    {
        if (! \App\Support\Esquema::tabla('cuentas_por_pagar')) return [];

        return \App\Models\CuentaPorPagar::with(['proveedor:id,nombre', 'abonos'])->where('estado', 'pendiente')
            ->orderBy('fecha_vencimiento')->get()
            ->map(fn ($f) => [
                'id' => $f->id, 'proveedor' => $f->nombreProveedor(), 'concepto' => $f->concepto,
                'numero' => $f->numero_factura, 'vence' => $f->fecha_vencimiento->toDateString(), 'saldo' => $f->saldo(),
            ])
            ->filter(fn ($f) => $f['saldo'] > 0)->values()->all();
    }

    /**
     * El primer mes con algún gasto registrado. Antes de él, los meses se ven
     * muy rentables solo porque no hay gastos cargados: la pantalla lo avisa.
     */
    public static function primerMesConGastos(): ?string
    {
        $primero = Periodo::recordar('primer-gasto', fn () => Gasto::where('estado', Gasto::PAGADO)->min('cubre_desde'));

        return $primero ? Periodo::mesDe($primero) : null;
    }

    private static function sumar(array &$out, string $mes, array $cat, float $monto, bool $esCompra): void
    {
        $out[$mes] ??= self::vacio();
        $m = &$out[$mes];

        $m['devengado'] += $monto;
        $m[$cat['naturaleza'] === 'fijo' ? 'fijos' : 'variables'] += $monto;
        if ($cat['varia_con_ventas']) $m['varia_con_ventas'] += $monto;
        if ($esCompra) $m['compras'] += $monto;
        $m['por_area'][$cat['area']] = ($m['por_area'][$cat['area']] ?? 0) + $monto;

        $clave = (string) $cat['id'];
        $m['por_categoria'][$clave] ??= [
            'id' => $cat['id'], 'nombre' => $cat['nombre'], 'naturaleza' => $cat['naturaleza'],
            'area' => $cat['area'], 'icono' => $cat['icono'] ?? null, 'monto' => 0.0,
        ];
        $m['por_categoria'][$clave]['monto'] += $monto;
        unset($m);
    }

    private static function vacio(): array
    {
        return [
            'devengado' => 0.0, 'caja' => 0.0, 'fijos' => 0.0, 'variables' => 0.0,
            'varia_con_ventas' => 0.0, 'compras' => 0.0, 'por_area' => [], 'por_categoria' => [],
        ];
    }
}
