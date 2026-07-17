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

    /** GET /api/reportes/interesados?tienda_id=&desde=&hasta= */
    public function interesados(Request $request)
    {
        $tiendaId = $request->query('tienda_id');
        $desde    = $request->query('desde');
        $hasta    = $request->query('hasta');

        // ── Leads activos (solo tipo=interesado) ──────────────────────────────
        $baseLeads = DB::table('clientes')->where('tipo', 'interesado');
        if ($tiendaId) $baseLeads->where('tienda_id', $tiendaId);

        $total  = (clone $baseLeads)->count();
        $nuevos = (clone $baseLeads)
            ->when($desde, fn($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn($q) => $q->whereDate('created_at', '<=', $hasta))
            ->count();

        // Nuevos por día en período (para gráfica)
        $porDia = (clone $baseLeads)
            ->when($desde, fn($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn($q) => $q->whereDate('created_at', '<=', $hasta))
            ->selectRaw('DATE(created_at) as fecha, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('fecha')
            ->get();

        // ── Análisis de demanda: todos los que tienen categorias_interes ──────
        // Incluye interesados actuales + los que ya se convirtieron a oficial
        // para no perder el historial de qué preguntaban.
        $baseDemanda = DB::table('clientes')
            ->whereNotNull('categorias_interes')
            ->whereRaw("JSON_LENGTH(categorias_interes) > 0");
        if ($tiendaId) $baseDemanda->where('tienda_id', $tiendaId);

        // Categorías de interés: expandir JSON y contar
        $registros = (clone $baseDemanda)->pluck('categorias_interes');
        $categoriaConteo = [];
        foreach ($registros as $json) {
            foreach (json_decode($json ?? '[]', true) ?? [] as $cat) {
                $cat = trim($cat);
                if ($cat) $categoriaConteo[$cat] = ($categoriaConteo[$cat] ?? 0) + 1;
            }
        }
        arsort($categoriaConteo);
        $topCategorias = collect($categoriaConteo)
            ->map(fn($v, $k) => ['categoria' => $k, 'total' => $v])
            ->values();

        // Por tienda: clientes con categorias_interes registradas
        $porTienda = DB::table('clientes as c')
            ->join('tiendas as t', 't.id', '=', 'c.tienda_id')
            ->whereNotNull('c.categorias_interes')
            ->whereRaw("JSON_LENGTH(c.categorias_interes) > 0")
            ->when($tiendaId, fn($q) => $q->where('c.tienda_id', $tiendaId))
            ->selectRaw('t.id as tienda_id, t.nombre as tienda, COUNT(*) as total')
            ->groupBy('t.id', 't.nombre')
            ->orderByDesc('total')
            ->get()
            ->map(function ($tienda) {
                $cats = DB::table('clientes')
                    ->whereNotNull('categorias_interes')
                    ->whereRaw("JSON_LENGTH(categorias_interes) > 0")
                    ->where('tienda_id', $tienda->tienda_id)
                    ->pluck('categorias_interes');
                $conteo = [];
                foreach ($cats as $json) {
                    foreach (json_decode($json ?? '[]', true) ?? [] as $cat) {
                        $cat = trim($cat);
                        if ($cat) $conteo[$cat] = ($conteo[$cat] ?? 0) + 1;
                    }
                }
                arsort($conteo);
                $tienda->top_categorias = collect($conteo)
                    ->map(fn($v, $k) => ['categoria' => $k, 'total' => $v])
                    ->values()
                    ->take(5);
                return $tienda;
            });

        // Por canal de captación (solo leads activos)
        $porCanal = (clone $baseLeads)
            ->selectRaw("COALESCE(canal_pref, 'sin_definir') as canal, COUNT(*) as total")
            ->groupBy('canal')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'total'           => $total,
            'nuevos_periodo'  => $nuevos,
            'periodo'         => ['desde' => $desde, 'hasta' => $hasta],
            'top_categorias'  => $topCategorias,
            'por_tienda'      => $porTienda,
            'por_canal'       => $porCanal,
            'por_dia'         => $porDia,
        ]);
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

        [$rows, $headings, $filename, $title, $totals, $meta] = match ($data['tipo']) {
            'ventas'        => $this->rowsVentas($request, $vendedorId),
            'vendedores'    => $this->rowsVendedores($request),
            'productos-top' => $this->rowsProductosTop($request, $vendedorId),
            'pendientes'    => $this->rowsPendientes($request, $vendedorId),
            'retrasos'      => $this->rowsRetrasos($request),
        };

        return Excel::download(
            new ReporteExport(collect($rows), $headings, $title ?? '', $totals ?? [], $meta ?? ''),
            $filename
        );
    }

    /** GET /api/reportes/canales?desde=&hasta=&tienda_id= */
    public function canalVentas(Request $request)
    {
        [$desde, $hasta] = $this->rango($request);
        $tiendaId = $request->query('tienda_id');

        $filas = DB::table('ordenes as o')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw('
                COALESCE(o.canal, "otro")  AS canal,
                COUNT(*)                   AS total_ordenes,
                SUM(o.valor_total)         AS valor_bruto
            ')
            ->groupBy('o.canal')
            ->orderByDesc('total_ordenes')
            ->get();

        $totalOrdenes = $filas->sum('total_ordenes');
        $totalValor   = $filas->sum('valor_bruto');

        $data = $filas->map(fn($f) => [
            'canal'          => $f->canal,
            'total_ordenes'  => (int) $f->total_ordenes,
            'valor_bruto'    => (float) $f->valor_bruto,
            'pct_ordenes'    => $totalOrdenes > 0 ? round($f->total_ordenes / $totalOrdenes * 100, 1) : 0,
            'pct_valor'      => $totalValor   > 0 ? round($f->valor_bruto   / $totalValor   * 100, 1) : 0,
        ]);

        return response()->json([
            'desde'         => $desde,
            'hasta'         => $hasta,
            'total_ordenes' => $totalOrdenes,
            'total_valor'   => $totalValor,
            'por_canal'     => $data,
        ]);
    }

    /** GET /api/reportes/resumen-mensual */
    public function resumenMensual()
    {
        $hoy   = now();
        $desde = $hoy->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $hasta = $hoy->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $general = DB::table('orden_items as oi')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o',   'o.id', '=', 'oi.orden_id')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->selectRaw('
                p.id               AS producto_id,
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre,
                COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
                SUM(oi.cantidad)                       AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario)  AS total_valor
            ')
            ->groupBy('p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
            ->orderByDesc('total_unidades')
            ->limit(20)
            ->get();

        $rawPorTienda = DB::table('orden_items as oi')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o',   'o.id', '=', 'oi.orden_id')
            ->join('tiendas as t',   't.id', '=', 'o.tienda_id')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->selectRaw('
                o.tienda_id,
                t.nombre           AS tienda_nombre,
                p.id               AS producto_id,
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre,
                COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
                SUM(oi.cantidad)                       AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario)  AS total_valor
            ')
            ->groupBy('o.tienda_id', 't.nombre', 'p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
            ->orderByDesc('total_unidades')
            ->get();

        $porTienda = $rawPorTienda
            ->groupBy('tienda_id')
            ->map(function ($items) {
                $first = $items->first();
                return [
                    'tienda_id'     => $first->tienda_id,
                    'tienda_nombre' => $first->tienda_nombre,
                    'top'           => $items->values()->take(10),
                ];
            })
            ->values();

        $meses   = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                    'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $mesAnterior = $hoy->copy()->subMonthNoOverflow();
        $mesLabel    = $meses[(int) $mesAnterior->format('n') - 1] . ' ' . $mesAnterior->format('Y');

        return response()->json([
            'mes'        => $mesLabel,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'general'    => $general,
            'por_tienda' => $porTienda,
        ]);
    }

    /** GET /api/reportes/resumen-mensual/exportar */
    public function exportarResumenMensual()
    {
        $hoy   = now();
        $desde = $hoy->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $hasta = $hoy->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $general = DB::table('orden_items as oi')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o',   'o.id', '=', 'oi.orden_id')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->selectRaw('"TOP GENERAL" AS seccion,
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre,
                COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
                SUM(oi.cantidad) AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario) AS total_valor')
            ->groupBy('p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
            ->orderByDesc('total_unidades')
            ->limit(20)
            ->get();

        $porTiendaRaw = DB::table('orden_items as oi')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o',   'o.id', '=', 'oi.orden_id')
            ->join('tiendas as t',   't.id', '=', 'o.tienda_id')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->selectRaw('t.nombre AS seccion, t.id AS tienda_id,
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre,
                COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
                SUM(oi.cantidad) AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario) AS total_valor')
            ->groupBy('o.tienda_id', 't.id', 't.nombre', 'p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
            ->orderBy('t.nombre')
            ->orderByDesc('total_unidades')
            ->get();

        $porTiendaTop10 = $porTiendaRaw->groupBy('tienda_id')
            ->flatMap(fn($items) => $items->take(10));

        $rows = $general->concat($porTiendaTop10)->map(fn($r) => [
            $r->seccion,
            $r->nombre,
            $r->categoria ?? '',
            $r->total_unidades,
            number_format($r->total_valor, 2, '.', ''),
        ]);

        $meses    = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                     'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $mesAnterior = $hoy->copy()->subMonthNoOverflow();
        $mesLabel    = $meses[(int) $mesAnterior->format('n') - 1] . ' ' . $mesAnterior->format('Y');

        return Excel::download(
            new ReporteExport(
                $rows,
                ['Sección / Tienda', 'Producto', 'Categoría', 'Unidades Vendidas', 'Valor Total (COP)'],
                "Resumen Mensual {$mesLabel}",
                [],
                $this->metaStr($desde, $hasta),
            ),
            "resumen_mensual_{$desde}.xlsx"
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
            ->whereIn('u.rol', ['vendedor', 'supervisor', 'ebanista'])
            ->where('u.activo', true)
            ->selectRaw('
                u.id            AS vendedor_id,
                u.nombre        AS vendedor,
                u.rol           AS rol,
                COUNT(DISTINCT o.id)  AS total_ordenes,
                COALESCE(SUM(p.monto), 0)  AS total_cobrado,
                COALESCE(AVG(o.valor_total), 0) AS ticket_promedio
            ')
            ->groupBy('u.id', 'u.nombre', 'u.rol')
            ->orderByDesc('total_cobrado')
            ->get()
            ->toArray();
    }

    private function buildProductosTop(Request $r): array
    {
        [$desde, $hasta] = $this->rango($r);
        $tiendaId = $r->query('tienda_id');
        $limit    = min((int) ($r->query('limit', 10)), 50);

        return DB::table('orden_items as oi')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw('
                p.id               AS producto_id,
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre,
                COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
                SUM(oi.cantidad)                         AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario)    AS total_valor
            ')
            ->groupBy('p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
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

        return DB::table('ordenes as o')
            ->join('clientes as c',         'c.id',            '=', 'o.cliente_id')
            ->join('usuarios as u',         'u.id',            '=', 'o.vendedor_id')
            ->join('tiendas as t',          't.id',            '=', 'o.tienda_id')
            ->leftJoin('orden_items as oi', 'oi.orden_id',     '=', 'o.id')
            ->leftJoin('produccion as pr',  'pr.orden_item_id','=', 'oi.id')
            ->whereNotIn('o.estado', ['entregado', 'cancelado', 'borrador'])
            ->when($vendedorId, fn($q) => $q->where('o.vendedor_id', $vendedorId))
            ->groupBy('o.id', 'o.numero_orden', 'c.nombre', 'c.telefono', 'u.nombre', 't.nombre', 'o.estado', 'o.created_at')
            ->havingRaw('
                (MIN(pr.fecha_compromiso) IS NOT NULL AND MIN(pr.fecha_compromiso) < CURDATE())
                OR MAX(pr.estado = "retrasado") = 1
                OR (MIN(oi.fecha_entrega_prom) IS NOT NULL AND MIN(oi.fecha_entrega_prom) < CURDATE())
                OR (COALESCE(MIN(pr.fecha_compromiso), MIN(oi.fecha_entrega_prom)) IS NULL
                    AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY))
            ')
            ->selectRaw('
                o.id                  AS orden_id,
                o.numero_orden,
                c.nombre              AS cliente,
                c.telefono,
                u.nombre              AS vendedor,
                t.nombre              AS tienda,
                o.estado,
                o.created_at,
                COALESCE(MIN(pr.fecha_compromiso), MIN(oi.fecha_entrega_prom)) AS fecha_compromiso,
                CASE
                    WHEN COALESCE(MIN(pr.fecha_compromiso), MIN(oi.fecha_entrega_prom)) IS NOT NULL
                    THEN DATEDIFF(CURDATE(), COALESCE(MIN(pr.fecha_compromiso), MIN(oi.fecha_entrega_prom)))
                    ELSE DATEDIFF(CURDATE(), DATE_ADD(DATE(o.created_at), INTERVAL 30 DAY))
                END                   AS dias_retraso,
                COUNT(DISTINCT oi.id) AS items_count
            ')
            ->orderByDesc('dias_retraso')
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
            $this->metaStr($desde, $hasta, $r->query('tienda_id')),
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
        [$desde, $hasta] = $this->rango($r);

        $rows = collect($this->buildVendedores($r))->map(fn($v) => [
            $v->vendedor, $v->total_ordenes,
            number_format($v->total_cobrado, 2, '.', ''),
            number_format($v->ticket_promedio, 2, '.', ''),
        ]);

        return [
            $rows,
            ['Vendedor', 'Total Órdenes', 'Total Cobrado (COP)', 'Ticket Promedio (COP)'],
            "vendedores_{$desde}_{$hasta}.xlsx",
            "Vendedores {$desde} al {$hasta}",
            [],
            $this->metaStr($desde, $hasta),
        ];
    }

    private function rowsProductosTop(Request $r, ?int $vendedorId = null): array
    {
        [$desde, $hasta] = $this->rango($r);
        $tiendaId = $r->query('tienda_id');

        $rows = DB::table('orden_items as oi')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o',   'o.id', '=', 'oi.orden_id')
            ->join('tiendas as t',   't.id', '=', 'o.tienda_id')
            ->where('o.estado', '!=', 'cancelado')
            ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->when($vendedorId, fn($q) => $q->where('o.vendedor_id', $vendedorId))
            ->selectRaw('
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre,
                t.nombre            AS tienda,
                COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
                SUM(oi.cantidad)                        AS total_unidades,
                SUM(oi.cantidad * oi.precio_unitario)   AS total_valor
            ')
            ->groupBy('p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'), 't.id', 't.nombre')
            ->orderByDesc('total_unidades')
            ->limit(200)
            ->get()
            ->map(fn($p) => [
                $p->nombre, $p->tienda, $p->categoria, $p->total_unidades,
                number_format($p->total_valor, 2, '.', ''),
            ]);

        return [
            $rows,
            ['Producto', 'Tienda', 'Categoría', 'Unidades Vendidas', 'Valor Total (COP)'],
            "productos_top_{$desde}_{$hasta}.xlsx",
            "Top Productos {$desde} al {$hasta}",
            [],
            $this->metaStr($desde, $hasta, $tiendaId),
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
            'ordenes_pendientes_' . now()->toDateString() . '.xlsx',
            'Cartera Pendiente',
            [],
            $this->metaStr(null, null, $tiendaId),
        ];
    }

    private function rowsRetrasos(Request $request): array
    {
        $rows = collect($this->buildRetrasos($request))->map(fn($r) => [
            $r->orden_id, $r->cliente, $r->telefono, $r->vendedor, $r->tienda,
            $r->estado, $r->items_count, $r->fecha_compromiso ?? 'Sin fecha',
            $r->dias_retraso, $r->created_at,
        ]);

        return [
            $rows,
            ['Orden ID', 'Cliente', 'Teléfono', 'Vendedor', 'Tienda',
             'Estado', 'Items', 'Fecha Compromiso', 'Días Retraso', 'Fecha Creación'],
            'ordenes_atrasadas_' . now()->toDateString() . '.xlsx',
            'Órdenes Atrasadas',
            [],
            $this->metaStr(null, null),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function rango(Request $r): array
    {
        return [
            $r->query('desde', now()->subDays(30)->toDateString()),
            $r->query('hasta', now()->toDateString()),
        ];
    }

    private function metaStr(?string $desde, ?string $hasta, mixed $tiendaId = null): string
    {
        $parts = [];

        if ($desde && $hasta) {
            $parts[] = 'Período: ' . date('d/m/Y', strtotime($desde)) . ' – ' . date('d/m/Y', strtotime($hasta));
        } else {
            $parts[] = 'Estado actual';
        }

        if ($tiendaId) {
            $nombre  = DB::table('tiendas')->where('id', $tiendaId)->value('nombre') ?? "Tienda #{$tiendaId}";
            $parts[] = "Tienda: {$nombre}";
        } else {
            $parts[] = 'Tienda: Todas';
        }

        $parts[] = 'Exportado: ' . now()->format('d/m/Y H:i');

        return implode('    •    ', $parts);
    }
}
