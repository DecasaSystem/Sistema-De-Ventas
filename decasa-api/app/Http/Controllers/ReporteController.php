<?php

namespace App\Http\Controllers;

use App\Exports\ReporteExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReporteController extends Controller
{
    // ─── Endpoints JSON ───────────────────────────────────────────────────────

    /** GET /api/reportes/ventas?desde=&hasta=&tienda_id= */
    public function ventas(Request $request)
    {
        return response()->json($this->buildVentas($request));
    }

    /** GET /api/reportes/vendedores?desde=&hasta= */
    public function vendedores(Request $request)
    {
        return response()->json($this->buildVendedores($request));
    }

    /** GET /api/reportes/productos-top?tienda_id=&limit=10 */
    public function productosTop(Request $request)
    {
        return response()->json($this->buildProductosTop($request));
    }

    /** GET /api/reportes/pendientes */
    public function pendientes(Request $request)
    {
        return response()->json($this->buildPendientes($request));
    }

    /** GET /api/reportes/retrasos */
    public function retrasos(Request $request)
    {
        return response()->json($this->buildRetrasos($request));
    }

    // ─── Export Excel ─────────────────────────────────────────────────────────

    /** GET /api/reportes/exportar?tipo=ventas&desde=&hasta= */
    public function exportar(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'tipo' => 'required|in:ventas,vendedores,productos-top,pendientes,retrasos',
        ]);

        // Vendedores solo exportan sus propios datos
        $vendedorId = $user->rol === 'vendedor' ? $user->id : null;

        [$rows, $headings, $filename, $title, $totals] = match ($data['tipo']) {
            'ventas'        => $this->rowsVentas($request, $vendedorId),
            'vendedores'    => $this->rowsVendedores($request),
            'productos-top' => $this->rowsProductosTop($request, $vendedorId),
            'pendientes'    => $this->rowsPendientes($request, $vendedorId),
            'retrasos'      => $this->rowsRetrasos($request),
        };

        return Excel::download(
            new ReporteExport(collect($rows), $headings, $title ?? '', $totals ?? []),
            $filename
        );
    }

    // ─── Data builders (JSON) ─────────────────────────────────────────────────

    private function buildVentas(Request $r): array
    {
        [$desde, $hasta] = $this->rango($r);
        $tiendaId = $r->query('tienda_id');

        $base = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId));

        $resumen = (clone $base)
            ->selectRaw('
                COUNT(DISTINCT o.id)  AS total_ordenes,
                SUM(p.monto)          AS total_cobrado,
                SUM(o.valor_total)    AS valor_bruto,
                AVG(o.valor_total)    AS ticket_promedio
            ')->first();

        $porDia = (clone $base)
            ->selectRaw('DATE(p.created_at) AS fecha, SUM(p.monto) AS monto')
            ->groupByRaw('DATE(p.created_at)')
            ->orderBy('fecha')
            ->get();

        $porTienda = (clone $base)
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->selectRaw('t.nombre AS tienda, SUM(p.monto) AS monto')
            ->groupBy('t.id', 't.nombre')
            ->orderByDesc('monto')
            ->get();

        return compact('resumen', 'porDia', 'porTienda') + ['desde' => $desde, 'hasta' => $hasta];
    }

    private function buildVendedores(Request $r): array
    {
        [$desde, $hasta] = $this->rango($r);

        return DB::table('usuarios as u')
            ->leftJoin('ordenes as o', fn($j) => $j
                ->on('o.vendedor_id', '=', 'u.id')
                ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            )
            ->leftJoin('pagos as p', 'p.orden_id', '=', 'o.id')
            ->where('u.rol', 'vendedor')
            ->where('u.activo', true)
            ->selectRaw('
                u.id            AS vendedor_id,
                u.nombre        AS vendedor,
                COUNT(DISTINCT o.id)  AS total_ordenes,
                COALESCE(SUM(p.monto), 0)  AS total_cobrado,
                COALESCE(AVG(o.valor_total), 0) AS ticket_promedio
            ')
            ->groupBy('u.id', 'u.nombre')
            ->orderByDesc('total_cobrado')
            ->get()
            ->toArray();
    }

    private function buildProductosTop(Request $r): array
    {
        $tiendaId = $r->query('tienda_id');
        $limit    = min((int) ($r->query('limit', 10)), 50);

        return DB::table('orden_items as oi')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->where('o.estado', '!=', 'cancelado')
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw('
                p.id               AS producto_id,
                p.nombre,
                p.categoria,
                SUM(oi.cantidad)                         AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario)    AS total_valor
            ')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->orderByDesc('total_unidades')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function buildPendientes(Request $r): array
    {
        $tiendaId = $r->query('tienda_id');

        return DB::table('ordenes as o')
            ->join('clientes as c',  'c.id',  '=', 'o.cliente_id')
            ->join('usuarios as u',  'u.id',  '=', 'o.vendedor_id')
            ->join('tiendas as t',   't.id',  '=', 'o.tienda_id')
            ->leftJoin('pagos as p', 'p.orden_id', '=', 'o.id')
            ->whereNotIn('o.estado', ['entregado', 'cancelado'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw('
                o.id            AS orden_id,
                o.estado,
                o.valor_total,
                o.created_at,
                c.nombre        AS cliente,
                c.telefono,
                u.nombre        AS vendedor,
                t.nombre        AS tienda,
                COALESCE(SUM(p.monto), 0)                       AS total_pagado,
                o.valor_total - COALESCE(SUM(p.monto), 0)       AS saldo_pendiente
            ')
            ->groupBy('o.id', 'o.estado', 'o.valor_total', 'o.created_at',
                      'c.nombre', 'c.telefono', 'u.nombre', 't.nombre')
            ->orderByDesc('o.created_at')
            ->get()
            ->toArray();
    }

    private function buildRetrasos(Request $request): array
    {
        $user = $request->user();
        $vendedorId = $user->rol === 'vendedor' ? $user->id : null;

        return DB::table('produccion as pr')
            ->join('orden_items as oi', 'oi.id', '=', 'pr.orden_item_id')
            ->join('ordenes as o',      'o.id',  '=', 'oi.orden_id')
            ->join('clientes as c',     'c.id',  '=', 'o.cliente_id')
            ->join('productos as pd',   'pd.id', '=', 'oi.producto_id')
            ->join('usuarios as u',     'u.id',  '=', 'o.vendedor_id')
            ->join('tiendas as t',      't.id',  '=', 'o.tienda_id')
            ->where(function ($q) {
                $q->where('pr.estado', 'retrasado')
                  ->orWhere(fn($q2) =>
                      $q2->where('pr.estado', 'en_proceso')
                         ->whereRaw('pr.fecha_compromiso < CURDATE()')
                  );
            })
            ->when($vendedorId, fn($q) => $q->where('o.vendedor_id', $vendedorId))
            ->selectRaw('
                pr.id                AS produccion_id,
                o.id                 AS orden_id,
                c.nombre             AS cliente,
                c.telefono,
                pd.nombre            AS producto,
                pr.fecha_compromiso,
                DATEDIFF(CURDATE(), pr.fecha_compromiso) AS dias_retraso,
                pr.estado,
                pr.motivo_retraso,
                u.nombre             AS vendedor,
                t.nombre             AS tienda
            ')
            ->orderBy('pr.fecha_compromiso')
            ->get()
            ->toArray();
    }

    // ─── Row builders (Excel flat arrays) ────────────────────────────────────

    private function rowsVentas(Request $r, ?int $vendedorId = null): array
    {
        [$desde, $hasta] = $this->rango($r);
        $tiendaId = $r->query('tienda_id');

        $rows = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->join('tiendas as t',  't.id', '=', 'o.tienda_id')
            ->join('usuarios as u', 'u.id', '=', 'p.vendedor_id')
            ->whereBetween('p.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->when($vendedorId, fn($q) => $q->where('o.vendedor_id', $vendedorId))
            ->select(
                'p.created_at as fecha',
                'o.id as orden_id',
                'c.nombre as cliente',
                't.nombre as tienda',
                'u.nombre as vendedor',
                'o.estado',
                'o.valor_total',
                'p.tipo',
                'p.metodo',
                'p.monto',
                'p.referencia'
            )
            ->orderBy('p.created_at')
            ->get()
            ->map(fn($r) => [
                $r->fecha, $r->orden_id, $r->cliente, $r->tienda,
                $r->vendedor, $this->estadoLabel($r->estado),
                number_format($r->valor_total, 0, '.', ','),
                $r->tipo, $r->metodo,
                number_format($r->monto, 0, '.', ','),
                $r->referencia ?? '',
            ]);

        $totalMonto = $rows->sum(fn($r) => (float) str_replace(',', '', $r[9]));

        $totals = [
            '', '', '', '', 'TOTALES', '',
            '', '', '',
            number_format($totalMonto, 0, '.', ','),
            '',
        ];

        $headings = [
            'Fecha', 'Orden ID', 'Cliente', 'Tienda', 'Vendedor',
            'Estado', 'Valor Orden', 'Tipo Pago', 'Método', 'Monto (COP)', 'Referencia'
        ];

        return [
            $rows,
            $headings,
            "ventas_{$desde}_{$hasta}.xlsx",
            "Ventas {$desde} al {$hasta}",
            $totals,
        ];
    }

    private function estadoLabel(string $estado): string
    {
        return match ($estado) {
            'pendiente_anticipo' => 'Pte. Anticipo',
            'en_produccion'      => 'En Producción',
            'listo_entrega'      => 'Listo Entrega',
            'entregado'          => 'Entregado',
            'cancelado'          => 'Cancelado',
            default              => $estado,
        };
    }

    private function rowsVendedores(Request $r): array
    {
        $rows = collect($this->buildVendedores($r))->map(fn($v) => [
            $v->vendedor, $v->total_ordenes,
            number_format($v->total_cobrado, 2, '.', ''),
            number_format($v->ticket_promedio, 2, '.', ''),
        ]);

        return [
            $rows,
            ['Vendedor', 'Total Órdenes', 'Total Cobrado (COP)', 'Ticket Promedio (COP)'],
            'vendedores.xlsx',
            'Ranking Vendedores',
            [],
        ];
    }

    private function rowsProductosTop(Request $r, ?int $vendedorId = null): array
    {
        $tiendaId = $r->query('tienda_id');
        $limit    = min((int) ($r->query('limit', 10)), 50);

        $rows = DB::table('orden_items as oi')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->where('o.estado', '!=', 'cancelado')
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->when($vendedorId, fn($q) => $q->where('o.vendedor_id', $vendedorId))
            ->selectRaw('
                p.id               AS producto_id,
                p.nombre,
                p.categoria,
                SUM(oi.cantidad)                         AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario)    AS total_valor
            ')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->orderByDesc('total_unidades')
            ->limit($limit)
            ->get()
            ->map(fn($p) => [
                $p->nombre, $p->categoria, $p->total_unidades,
                number_format($p->total_valor, 2, '.', ''),
            ]);

        return [
            $rows,
            ['Producto', 'Categoría', 'Unidades Vendidas', 'Valor Total (COP)'],
            'productos_top.xlsx',
            'Top Productos',
            [],
        ];
    }

    private function rowsPendientes(Request $r, ?int $vendedorId = null): array
    {
        $tiendaId = $r->query('tienda_id');

        $rows = DB::table('ordenes as o')
            ->join('clientes as c',  'c.id',  '=', 'o.cliente_id')
            ->join('usuarios as u',  'u.id',  '=', 'o.vendedor_id')
            ->join('tiendas as t',   't.id',  '=', 'o.tienda_id')
            ->leftJoin('pagos as p', 'p.orden_id', '=', 'o.id')
            ->whereNotIn('o.estado', ['entregado', 'cancelado'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->when($vendedorId, fn($q) => $q->where('o.vendedor_id', $vendedorId))
            ->selectRaw('
                o.id            AS orden_id,
                o.estado,
                o.valor_total,
                o.created_at,
                c.nombre        AS cliente,
                c.telefono,
                u.nombre        AS vendedor,
                t.nombre        AS tienda,
                COALESCE(SUM(p.monto), 0)                       AS total_pagado,
                o.valor_total - COALESCE(SUM(p.monto), 0)       AS saldo_pendiente
            ')
            ->groupBy('o.id', 'o.estado', 'o.valor_total', 'o.created_at',
                      'c.nombre', 'c.telefono', 'u.nombre', 't.nombre')
            ->orderByDesc('o.created_at')
            ->get()
            ->map(fn($o) => [
                $o->orden_id, $o->cliente, $o->telefono, $o->vendedor, $o->tienda,
                $this->estadoLabel($o->estado), $o->valor_total, $o->total_pagado, $o->saldo_pendiente, $o->created_at,
            ]);

        return [
            $rows,
            ['Orden ID', 'Cliente', 'Teléfono', 'Vendedor', 'Tienda', 'Estado',
             'Valor Total', 'Total Pagado', 'Saldo Pendiente', 'Fecha'],
            'ordenes_pendientes.xlsx',
            'Cartera Pendiente',
            [],
        ];
    }

    private function rowsRetrasos(Request $request): array
    {
        $rows = collect($this->buildRetrasos($request))->map(fn($r) => [
            $r->produccion_id, $r->orden_id, $r->cliente, $r->telefono,
            $r->producto, $r->fecha_compromiso, $r->dias_retraso,
            $r->estado, $r->motivo_retraso, $r->vendedor, $r->tienda,
        ]);

        return [
            $rows,
            ['ID Prod.', 'Orden ID', 'Cliente', 'Teléfono', 'Producto',
             'Fecha Compromiso', 'Días Retraso', 'Estado', 'Motivo', 'Vendedor', 'Tienda'],
            'retrasos_produccion.xlsx',
            'Retrasos Producción',
            [],
        ];
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function rango(Request $r): array
    {
        return [
            $r->query('desde', now()->subDays(30)->toDateString()),
            $r->query('hasta', now()->toDateString()),
        ];
    }
}
