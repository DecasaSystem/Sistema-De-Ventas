<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatsController extends Controller
{
    // ─── Helper: parsear período ──────────────────────────────────────────────

    private function parseFechas(Request $r): array
    {
        $periodo = $r->query('periodo');
        $hoy     = Carbon::now()->toDateString();

        switch ($periodo) {
            case 'hoy':
                $desde = $hoy; $hasta = $hoy; break;
            case 'semana':
                $desde = Carbon::now()->startOfWeek()->toDateString(); $hasta = $hoy; break;
            case 'mes_anterior':
                $desde = Carbon::now()->subMonth()->startOfMonth()->toDateString();
                $hasta = Carbon::now()->subMonth()->endOfMonth()->toDateString();
                break;
            case 'anio':
                $desde = Carbon::now()->startOfYear()->toDateString(); $hasta = $hoy; break;
            default:
                $desde = $r->query('desde', Carbon::now()->startOfMonth()->toDateString());
                $hasta = $r->query('hasta', $hoy);
        }

        // Período anterior (misma duración) para comparativa
        $duracion       = Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1;
        $desdeAnterior  = Carbon::parse($desde)->subDays($duracion)->toDateString();
        $hastaAnterior  = Carbon::parse($desde)->subDay()->toDateString();

        return compact('desde', 'hasta', 'desdeAnterior', 'hastaAnterior');
    }

    // ─── Helper: KPIs agregados ───────────────────────────────────────────────

    private function kpis(string $desde, string $hasta, ?string $tiendaId, ?int $vendedorId): array
    {
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        // Ingresos reales cobrados en el período
        $ingresosQ = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango);
        if ($tiendaId)   $ingresosQ->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $ingresosQ->where('o.vendedor_id', $vendedorId);
        $ingresos = (float) $ingresosQ->sum('p.monto');

        // Conteos de órdenes creadas en el período
        $ordenesQ = DB::table('ordenes')->whereBetween('created_at', $rango);
        if ($tiendaId)   $ordenesQ->where('tienda_id',   $tiendaId);
        if ($vendedorId) $ordenesQ->where('vendedor_id', $vendedorId);
        $ord = $ordenesQ->selectRaw('
            COUNT(*)                                                           AS total,
            SUM(estado = "entregado")                                          AS entregadas,
            SUM(estado = "cancelado")                                          AS canceladas,
            SUM(estado NOT IN ("entregado","cancelado"))                       AS pendientes
        ')->first();

        $entregadas = (int) ($ord->entregadas ?? 0);

        // Cartera pendiente (órdenes activas con saldo > 0, sin filtro de fecha)
        $carteraQ = DB::table('v_saldo_ordenes as v')
            ->join('ordenes as o', 'o.id', '=', 'v.orden_id')
            ->where('v.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', ['entregado', 'cancelado']);
        if ($tiendaId)   $carteraQ->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $carteraQ->where('o.vendedor_id', $vendedorId);
        $cartera = (float) $carteraQ->sum('v.saldo_pendiente');

        return [
            'ingresos_totales'   => $ingresos,
            'ordenes_totales'    => (int) ($ord->total      ?? 0),
            'ordenes_entregadas' => $entregadas,
            'ordenes_pendientes' => (int) ($ord->pendientes ?? 0),
            'ordenes_canceladas' => (int) ($ord->canceladas ?? 0),
            'ticket_promedio'    => $entregadas > 0 ? round($ingresos / $entregadas) : 0,
            'cartera_pendiente'  => $cartera,
        ];
    }

    // ─── GET /api/stats/panel ─────────────────────────────────────────────────

    public function panel(Request $request)
    {
        $user       = $request->user();
        $f          = $this->parseFechas($request);
        $tiendaId   = $request->query('tienda_id');
        $vendedorId = $user->rol === 'vendedor' ? $user->id : null;

        $actual   = $this->kpis($f['desde'],         $f['hasta'],         $tiendaId, $vendedorId);
        $anterior = $this->kpis($f['desdeAnterior'], $f['hastaAnterior'], $tiendaId, $vendedorId);

        $varPct = $anterior['ingresos_totales'] > 0
            ? round(($actual['ingresos_totales'] - $anterior['ingresos_totales'])
                    / $anterior['ingresos_totales'] * 100, 1)
            : null;

        return response()->json([
            'periodo'    => ['desde' => $f['desde'], 'hasta' => $f['hasta']],
            ...$actual,
            'comparativa' => [
                'ingresos_anterior' => $anterior['ingresos_totales'],
                'variacion_pct'     => $varPct,
            ],
        ]);
    }

    // ─── GET /api/stats/tendencia ─────────────────────────────────────────────

    public function tendencia(Request $request)
    {
        $user       = $request->user();
        $f          = $this->parseFechas($request);
        $tiendaId   = $request->query('tienda_id');
        $agrupado   = $request->query('agrupado', 'dia');
        $vendedorId = $request->query('vendedor_id', $user->rol === 'vendedor' ? $user->id : null);

        $desde = $f['desde'];
        $hasta = $f['hasta'];
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        // Formato MySQL y Carbon según agrupación
        $fmtMysql  = $agrupado === 'mes' ? '%Y-%m' : '%Y-%m-%d';
        $fmtCarbon = $agrupado === 'mes' ? 'Y-m'   : 'Y-m-d';

        // Dinero cobrado por período
        $cobradoQ = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango)
            ->selectRaw("DATE_FORMAT(p.created_at, '{$fmtMysql}') AS periodo, SUM(p.monto) AS total")
            ->groupBy('periodo')->orderBy('periodo');
        if ($tiendaId)   $cobradoQ->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $cobradoQ->where('o.vendedor_id', $vendedorId);

        // Valor de órdenes creadas por período
        $ordenesQ = DB::table('ordenes')
            ->whereBetween('created_at', $rango)
            ->selectRaw("DATE_FORMAT(created_at, '{$fmtMysql}') AS periodo, SUM(valor_total) AS total")
            ->groupBy('periodo')->orderBy('periodo');
        if ($tiendaId)   $ordenesQ->where('tienda_id',   $tiendaId);
        if ($vendedorId) $ordenesQ->where('vendedor_id', $vendedorId);

        $cobrado     = $cobradoQ->get()->keyBy('periodo');
        $ordenesValor = $ordenesQ->get()->keyBy('periodo');

        // Generar rango completo de etiquetas (sin huecos)
        $labels = $serCobrado = $serOrdenes = [];
        $cursor = Carbon::parse($desde);
        $fin    = Carbon::parse($hasta);

        while ($cursor->lte($fin)) {
            $key        = $cursor->format($fmtCarbon);
            $labels[]   = $key;
            $serCobrado[]  = (float) ($cobrado->get($key)?->total ?? 0);
            $serOrdenes[]  = (float) ($ordenesValor->get($key)?->total ?? 0);
            $agrupado === 'mes' ? $cursor->addMonth() : $cursor->addDay();
        }

        return response()->json([
            'labels'        => $labels,
            'cobrado'       => $serCobrado,
            'ordenes_valor' => $serOrdenes,
        ]);
    }

    // ─── GET /api/stats/categorias ───────────────────────────────────────────

    public function categorias(Request $request)
    {
        $user       = $request->user();
        $f          = $this->parseFechas($request);
        $tiendaId   = $request->query('tienda_id');
        $vendedorId = $user->rol === 'vendedor' ? $user->id : null;

        $q = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', [$f['desde'] . ' 00:00:00', $f['hasta'] . ' 23:59:59'])
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw("
                COALESCE(p.categoria, 'Sin categoría')     AS categoria,
                SUM(oi.cantidad)                           AS cantidad,
                SUM(oi.cantidad * oi.precio_unitario)      AS valor_total,
                COUNT(DISTINCT p.id)                       AS num_productos
            ")
            ->groupBy('categoria')
            ->orderByDesc('valor_total');

        if ($tiendaId)   $q->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $q->where('o.vendedor_id', $vendedorId);

        return response()->json($q->get());
    }

    // ─── GET /api/stats/productos ─────────────────────────────────────────────

    public function productos(Request $request)
    {
        $user       = $request->user();
        $f          = $this->parseFechas($request);
        $tiendaId   = $request->query('tienda_id');
        $tipo       = $request->query('tipo', 'valor');
        $busqueda   = trim($request->query('q', ''));
        $limit      = $busqueda ? 50 : min((int) $request->query('limit', 10), 50);
        $categoria  = $request->query('categoria');
        $vendedorId = $user->rol === 'vendedor' ? $user->id : null;

        $q = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', [$f['desde'] . ' 00:00:00', $f['hasta'] . ' 23:59:59'])
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('
                p.id       AS producto_id,
                p.nombre,
                p.categoria,
                p.foto_url,
                SUM(oi.cantidad)                          AS cantidad,
                SUM(oi.cantidad * oi.precio_unitario)     AS valor_total
            ')
            ->groupBy('p.id', 'p.nombre', 'p.categoria', 'p.foto_url')
            ->orderByDesc($tipo === 'cantidad' ? 'cantidad' : 'valor_total')
            ->limit($limit);

        if ($tiendaId)   $q->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $q->where('o.vendedor_id', $vendedorId);
        if ($categoria)  $q->where('p.categoria',   $categoria);
        if ($busqueda)   $q->whereRaw('LOWER(p.nombre) LIKE ?', ['%' . mb_strtolower($busqueda) . '%']);

        return response()->json($q->get());
    }

    // ─── GET /api/stats/cartera ───────────────────────────────────────────────

    public function cartera(Request $request)
    {
        $user       = $request->user();
        $tiendaId   = $request->query('tienda_id');
        $vendedorId = $request->query('vendedor_id');

        if ($user->rol === 'vendedor') $vendedorId = $user->id;

        $q = DB::table('v_saldo_ordenes as v')
            ->join('ordenes as o',  'o.id',  '=', 'v.orden_id')
            ->join('clientes as c', 'c.id',  '=', 'o.cliente_id')
            ->join('usuarios as u', 'u.id',  '=', 'o.vendedor_id')
            ->join('tiendas as t',  't.id',  '=', 'o.tienda_id')
            ->where('v.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', ['entregado', 'cancelado'])
            ->selectRaw('
                o.id                                            AS orden_id,
                o.estado,
                o.created_at,
                c.nombre                                        AS cliente,
                c.telefono,
                u.id                                            AS vendedor_id,
                u.nombre                                        AS vendedor,
                t.nombre                                        AS tienda,
                o.valor_total,
                v.total_pagado,
                v.saldo_pendiente,
                DATEDIFF(CURDATE(), DATE(o.created_at))         AS dias_sin_pagar
            ')
            ->orderByDesc('v.saldo_pendiente');

        if ($tiendaId)   $q->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $q->where('o.vendedor_id', $vendedorId);

        return response()->json($q->get());
    }

    // ─── GET /api/stats/tiendas  (solo supervisor) ────────────────────────────

    public function tiendas(Request $request)
    {
        $f     = $this->parseFechas($request);
        $desde = $f['desde']; $hasta = $f['hasta'];
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $tiendas = DB::table('tiendas')->where('activa', true)->get();

        $resultado = $tiendas->map(function ($t) use ($rango, $desde, $hasta) {
            $ingresos = (float) DB::table('pagos as p')
                ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
                ->where('o.tienda_id', $t->id)->whereBetween('p.created_at', $rango)
                ->sum('p.monto');

            $ord = DB::table('ordenes')->where('tienda_id', $t->id)
                ->whereBetween('created_at', $rango)
                ->selectRaw('COUNT(*) AS total, SUM(estado = "entregado") AS entregadas')
                ->first();

            $entregadas = (int) ($ord->entregadas ?? 0);

            $top = DB::table('pagos as p')
                ->join('ordenes as o',  'o.id',  '=', 'p.orden_id')
                ->join('usuarios as u', 'u.id',  '=', 'o.vendedor_id')
                ->where('o.tienda_id', $t->id)->whereBetween('p.created_at', $rango)
                ->selectRaw('u.id, u.nombre, SUM(p.monto) AS ingresos')
                ->groupBy('u.id', 'u.nombre')->orderByDesc('ingresos')
                ->first();

            return [
                'tienda_id'          => $t->id,
                'nombre'             => $t->nombre,
                'ciudad'             => $t->ciudad,
                'ingresos'           => $ingresos,
                'ordenes_totales'    => (int) ($ord->total ?? 0),
                'ordenes_entregadas' => $entregadas,
                'ticket_promedio'    => $entregadas > 0 ? round($ingresos / $entregadas) : 0,
                'vendedor_destacado' => $top ? [
                    'id'       => $top->id,
                    'nombre'   => $top->nombre,
                    'ingresos' => (float) $top->ingresos,
                ] : null,
            ];
        });

        return response()->json($resultado);
    }

    // ─── GET /api/stats/vendedores  (solo supervisor) ─────────────────────────

    public function vendedores(Request $request)
    {
        $f        = $this->parseFechas($request);
        $tiendaId = $request->query('tienda_id');
        $rango    = [$f['desde'] . ' 00:00:00', $f['hasta'] . ' 23:59:59'];

        $vendedores = DB::table('usuarios as u')
            ->leftJoin('tiendas as t', 't.id', '=', 'u.tienda_default_id')
            ->whereIn('u.rol', ['vendedor', 'supervisor'])->where('u.activo', true)
            ->when($tiendaId, fn($q) => $q->where('u.tienda_default_id', $tiendaId))
            ->selectRaw('u.id, u.nombre, u.rol, t.nombre AS tienda, t.id AS tienda_id')
            ->get();

        $resultado = $vendedores->map(function ($v) use ($rango) {
            $ingresos = (float) DB::table('pagos as p')
                ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
                ->where('o.vendedor_id', $v->id)->whereBetween('p.created_at', $rango)
                ->sum('p.monto');

            $ord = DB::table('ordenes')->where('vendedor_id', $v->id)
                ->whereBetween('created_at', $rango)
                ->selectRaw('COUNT(*) AS total, SUM(estado="entregado") AS entregadas, SUM(estado="cancelado") AS canceladas')
                ->first();

            $entregadas = (int) ($ord->entregadas ?? 0);

            $cartera = (float) DB::table('v_saldo_ordenes as vs')
                ->join('ordenes as o', 'o.id', '=', 'vs.orden_id')
                ->where('o.vendedor_id', $v->id)
                ->where('vs.saldo_pendiente', '>', 0)
                ->whereNotIn('o.estado', ['entregado', 'cancelado'])
                ->sum('vs.saldo_pendiente');

            return [
                'id'                 => $v->id,
                'nombre'             => $v->nombre,
                'rol'                => $v->rol,
                'tienda'             => $v->tienda,
                'tienda_id'          => $v->tienda_id,
                'ingresos'           => $ingresos,
                'ordenes_totales'    => (int) ($ord->total     ?? 0),
                'ordenes_entregadas' => $entregadas,
                'ordenes_canceladas' => (int) ($ord->canceladas ?? 0),
                'ticket_promedio'    => $entregadas > 0 ? round($ingresos / $entregadas) : 0,
                'cartera_pendiente'  => $cartera,
            ];
        })->sortByDesc('ingresos')->values();

        return response()->json($resultado);
    }

    // ─── GET /api/stats/vendedores/me ────────────────────────────────────────

    public function statsMe(Request $request)
    {
        $f = $this->parseFechas($request);
        return response()->json($this->perfilVendedor($request->user()->id, $f['desde'], $f['hasta']));
    }

    // ─── GET /api/stats/vendedor/{id} ────────────────────────────────────────

    public function statsVendedor(Request $request, int $id)
    {
        $user = $request->user();

        if ($user->rol === 'vendedor' && $user->id !== $id) abort(403);

        $f      = $this->parseFechas($request);
        $perfil = $this->perfilVendedor($id, $f['desde'], $f['hasta']);

        // Si supervisor, añadir comparativa vs promedio del equipo
        if ($user->rol === 'supervisor') {
            $rango       = [$f['desde'] . ' 00:00:00', $f['hasta'] . ' 23:59:59'];
            $totalEquipo = (float) DB::table('pagos as p')
                ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
                ->whereBetween('p.created_at', $rango)->sum('p.monto');
            $nVendedores = DB::table('usuarios')->whereIn('rol', ['vendedor', 'supervisor'])->where('activo', true)->count();

            $perfil['comparativa_equipo'] = [
                'promedio_ingresos' => $nVendedores > 0 ? round($totalEquipo / $nVendedores) : 0,
                'total_equipo'      => $totalEquipo,
                'num_vendedores'    => $nVendedores,
                'pct_del_total'     => $totalEquipo > 0
                    ? round($perfil['dinero_vendido'] / $totalEquipo * 100, 1)
                    : 0,
            ];
        }

        return response()->json($perfil);
    }

    // ─── GET /api/stats/conductores  (solo supervisor) ───────────────────────

    public function conductores(Request $request)
    {
        $f     = $this->parseFechas($request);
        $rango = [$f['desde'] . ' 00:00:00', $f['hasta'] . ' 23:59:59'];

        $conductores = DB::table('usuarios')
            ->where('rol', 'conductor')->where('activo', true)
            ->select('id', 'nombre')
            ->get();

        $resultado = $conductores->map(function ($c) use ($rango) {
            $entregas = (int) DB::table('despacho_items as di')
                ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
                ->where('d.conductor_id', $c->id)
                ->where('di.estado', 'entregado')
                ->whereBetween('di.entregado_at', $rango)
                ->count();

            $cobrado = (float) DB::table('pagos as p')
                ->join('ordenes as o',        'o.id',  '=', 'p.orden_id')
                ->join('despacho_items as di', 'di.orden_id', '=', 'o.id')
                ->join('despachos as d',       'd.id',  '=', 'di.despacho_id')
                ->where('d.conductor_id', $c->id)
                ->whereBetween('p.created_at', $rango)
                ->sum('p.monto');

            $pendientes = (int) DB::table('despacho_items as di')
                ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
                ->where('d.conductor_id', $c->id)
                ->whereIn('d.estado', ['asignado', 'en_ruta'])
                ->where('di.estado', 'pendiente')
                ->count();

            return [
                'id'         => $c->id,
                'nombre'     => $c->nombre,
                'entregas'   => $entregas,
                'cobrado'    => $cobrado,
                'pendientes' => $pendientes,
            ];
        })->sortByDesc('entregas')->values();

        return response()->json($resultado);
    }

    // ─── GET /api/stats/conductor ────────────────────────────────────────────

    public function statsConductor(Request $request)
    {
        $f         = $this->parseFechas($request);
        $conductor = $request->user();
        $rango     = [$f['desde'] . ' 00:00:00', $f['hasta'] . ' 23:59:59'];

        $entregas = DB::table('despacho_items as di')
            ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
            ->where('d.conductor_id', $conductor->id)
            ->where('di.estado', 'entregado')
            ->whereBetween('di.entregado_at', $rango)
            ->count();

        $cobrado = (float) DB::table('pagos as p')
            ->join('ordenes as o',        'o.id',  '=', 'p.orden_id')
            ->join('despacho_items as di', 'di.orden_id', '=', 'o.id')
            ->join('despachos as d',       'd.id',  '=', 'di.despacho_id')
            ->where('d.conductor_id', $conductor->id)
            ->whereBetween('p.created_at', $rango)
            ->sum('p.monto');

        $pendientes = DB::table('despacho_items as di')
            ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
            ->where('d.conductor_id', $conductor->id)
            ->whereIn('d.estado', ['asignado', 'en_ruta'])
            ->where('di.estado', 'pendiente')
            ->count();

        $tendenciaRaw = DB::table('despacho_items as di')
            ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
            ->where('d.conductor_id', $conductor->id)
            ->where('di.estado', 'entregado')
            ->whereBetween('di.entregado_at', $rango)
            ->selectRaw("DATE(di.entregado_at) AS dia, COUNT(*) AS total")
            ->groupBy('dia')->orderBy('dia')
            ->get()->keyBy('dia');

        $labels = $serie = [];
        $cursor = Carbon::parse($f['desde']);
        $fin    = Carbon::parse($f['hasta']);
        while ($cursor->lte($fin)) {
            $key      = $cursor->toDateString();
            $labels[] = $key;
            $serie[]  = (int) ($tendenciaRaw->get($key)?->total ?? 0);
            $cursor->addDay();
        }

        $recientes = DB::table('despacho_items as di')
            ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
            ->join('ordenes as o',   'o.id', '=', 'di.orden_id')
            ->join('clientes as c',  'c.id', '=', 'o.cliente_id')
            ->where('d.conductor_id', $conductor->id)
            ->where('di.estado', 'entregado')
            ->selectRaw('di.id, o.id AS orden_id, c.nombre AS cliente, c.direccion, o.valor_total, di.entregado_at')
            ->orderByDesc('di.entregado_at')
            ->limit(15)
            ->get();

        return response()->json([
            'conductor'  => ['nombre' => $conductor->nombre],
            'periodo'    => ['desde' => $f['desde'], 'hasta' => $f['hasta']],
            'entregas'   => $entregas,
            'cobrado'    => $cobrado,
            'pendientes' => $pendientes,
            'tendencia'  => ['labels' => $labels, 'serie' => $serie],
            'recientes'  => $recientes,
        ]);
    }

    // ─── Perfil genérico (por columna + valor) ──────────────────────────────

    private function perfilPor(string $columna, int $valor, string $desde, string $hasta): array
    {
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $ingresos = (float) DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->where("o.$columna", $valor)->whereBetween('p.created_at', $rango)
            ->sum('p.monto');

        $ord = DB::table('ordenes')->where($columna, $valor)
            ->whereBetween('created_at', $rango)
            ->selectRaw('
                COUNT(*)                                        AS total,
                SUM(estado = "entregado")                       AS entregadas,
                SUM(estado NOT IN ("entregado","cancelado"))    AS pendientes,
                SUM(estado = "cancelado")                       AS canceladas
            ')->first();

        $entregadas = (int) ($ord->entregadas ?? 0);

        $cartera = (float) DB::table('v_saldo_ordenes as v')
            ->join('ordenes as o', 'o.id', '=', 'v.orden_id')
            ->where("o.$columna", $valor)
            ->where('v.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', ['entregado', 'cancelado'])
            ->sum('v.saldo_pendiente');

        $topProductos = DB::table('orden_items as oi')
            ->join('ordenes as o',  'o.id',  '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->where("o.$columna", $valor)
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('p.id, p.nombre, p.categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->orderByDesc('valor_total')->limit(5)->get();

        $ordenesRecientes = DB::table('ordenes as o')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('v_saldo_ordenes as v', 'v.orden_id', '=', 'o.id')
            ->where("o.$columna", $valor)
            ->selectRaw('o.id, c.nombre AS cliente, o.estado, o.valor_total, COALESCE(v.saldo_pendiente, o.valor_total) AS saldo_pendiente, o.created_at')
            ->orderByDesc('o.created_at')->limit(5)->get();

        $canales = DB::table('ordenes')->where($columna, $valor)
            ->whereBetween('created_at', $rango)
            ->selectRaw('canal, COUNT(*) AS total')->groupBy('canal')->get();

        return [
            'dinero_vendido'     => $ingresos,
            'ordenes_creadas'    => (int) ($ord->total      ?? 0),
            'ordenes_entregadas' => $entregadas,
            'ordenes_pendientes' => (int) ($ord->pendientes ?? 0),
            'ordenes_canceladas' => (int) ($ord->canceladas ?? 0),
            'ticket_promedio'    => $entregadas > 0 ? round($ingresos / $entregadas) : 0,
            'cartera_pendiente'  => $cartera,
            'top_productos'      => $topProductos,
            'ordenes_recientes'  => $ordenesRecientes,
            'canales'            => $canales,
        ];
    }

    // ─── Perfil individual por vendedor ─────────────────────────────────────

    private function perfilVendedor(int $vendedorId, string $desde, string $hasta): array
    {
        $vendedor = DB::table('usuarios as u')
            ->leftJoin('tiendas as t', 't.id', '=', 'u.tienda_default_id')
            ->where('u.id', $vendedorId)
            ->selectRaw('u.id, u.nombre, u.email, u.rol, t.nombre AS tienda, t.id AS tienda_id')
            ->first();

        $data = $this->perfilPor('vendedor_id', $vendedorId, $desde, $hasta);
        $data['vendedor'] = $vendedor;
        $data['periodo'] = ['desde' => $desde, 'hasta' => $hasta];
        return $data;
    }
}
