<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Services\RangoFechas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatsController extends Controller
{
    // ─── Helper: parsear período ──────────────────────────────────────────────

    /**
     * La app guarda los timestamps en UTC, pero el negocio vive en Colombia
     * (UTC−5). Sin esto, todo lo vendido después de las 7 de la noche caía en
     * el día siguiente, el reporte de "Hoy" se vaciaba a esa hora, y una venta
     * del 31 a las 8 p.m. se contaba en el mes entrante.
     *
     * Se recibe el día tal como lo entiende la gente y se devuelve el rango en
     * UTC, que es como está la base.
     */
    public const TZ_NEGOCIO = 'America/Bogota';

    private function rangoUtc(string $desde, string $hasta): array
    {
        return [
            Carbon::parse($desde, self::TZ_NEGOCIO)->startOfDay()->setTimezone('UTC')->toDateTimeString(),
            Carbon::parse($hasta, self::TZ_NEGOCIO)->endOfDay()->setTimezone('UTC')->toDateTimeString(),
        ];
    }

    private function parseFechas(Request $r): array
    {
        // Qué significa cada botón del filtro vive en RangoFechas, y no acá,
        // porque Reportes leía lo mismo por su cuenta y se separaron: sus
        // apartados ignoraban el filtro y devolvían siempre lo de los últimos
        // 30 días.
        [$desde, $hasta] = RangoFechas::de($r);

        // Período anterior (misma duración) para comparativa
        $duracion       = Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1;
        $desdeAnterior  = Carbon::parse($desde)->subDays($duracion)->toDateString();
        $hastaAnterior  = Carbon::parse($desde)->subDay()->toDateString();

        return compact('desde', 'hasta', 'desdeAnterior', 'hastaAnterior');
    }

    // ─── Helper: KPIs agregados ───────────────────────────────────────────────

    private function kpis(string $desde, string $hasta, ?string $tiendaId, ?int $vendedorId): array
    {
        $rango = $this->rangoUtc($desde, $hasta);

        // Cobranza del período: TODO el dinero que entró en el rango, sin
        // importar de qué mes sea la orden. Un abono de septiembre sobre una
        // restauración de julio entra aquí. Es flujo de caja, no "cuánto de lo
        // que vendimos este mes ya está cobrado".
        $cobranzaQ = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango);
        // El ingreso se le acredita a la tienda que recibió el dinero, que puede
        // no ser la de la orden. Los pagos viejos sin tienda caen a la de su orden.
        if ($tiendaId)   $cobranzaQ->whereRaw('COALESCE(p.tienda_id, o.tienda_id) = ?', [$tiendaId]);
        if ($vendedorId) $cobranzaQ->where('o.vendedor_id', $vendedorId);
        $cobranzaPeriodo = (float) $cobranzaQ->sum('p.monto');

        // Cobrado DE LO VENDIDO EN EL PERÍODO: abonos de órdenes creadas dentro
        // del rango. Este es el que va pegado a "Total vendido", para que el
        // recuadro cuadre: vendido = cobrado + por cobrar. Antes aquí iba la
        // cobranza de arriba y salía "más cobrado que vendido" cuando el mes
        // recibía plata de órdenes viejas.
        $cobradoVendidoQ = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES);
        if ($tiendaId)   $cobradoVendidoQ->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $cobradoVendidoQ->where('o.vendedor_id', $vendedorId);
        $ingresos = (float) $cobradoVendidoQ->sum('p.monto');

        // Conteos de órdenes creadas en el período (sin cotizaciones ni borradores:
        // todavía no son ventas)
        $ordenesQ = DB::table('ordenes')
            ->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES);
        if ($tiendaId)   $ordenesQ->where('tienda_id',   $tiendaId);
        if ($vendedorId) $ordenesQ->where('vendedor_id', $vendedorId);
        $ord = $ordenesQ->selectRaw('
            COUNT(*)                                                           AS total,
            SUM(estado = "entregado")                                          AS entregadas,
            SUM(estado = "cancelado")                                          AS canceladas,
            SUM(estado NOT IN ("entregado","cancelado"))                       AS pendientes
        ')->first();

        $entregadas = (int) ($ord->entregadas ?? 0);
        $ordenesTotales = (int) ($ord->total ?? 0);

        // Lo vendido en el período: el valor de las órdenes hechas dentro de él.
        //
        // Antes se calculaba como "ingresos + cartera", pero la cartera no
        // llevaba filtro de fecha: era toda la deuda histórica. Al mes de 4 días
        // eso daba $56.543.534 cuando lo vendido eran $18.340.000 — tres veces
        // más, porque venía arrastrando lo que se debía de meses anteriores.
        $vendidoQ = DB::table('ordenes as o')
            ->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES);
        if ($tiendaId)   $vendidoQ->where('tienda_id',   $tiendaId);
        if ($vendedorId) $vendidoQ->where('vendedor_id', $vendedorId);
        $totalVendido = (float) (clone $vendidoQ)->sum('valor_total');

        // De qué es esa plata: venta, restauración o la serie con descuento.
        // Se pregunta sobre lo MISMO que ya se sumó, así que los tres cajones
        // dan justo el total de arriba.
        $porTipo = (clone $vendidoQ)
            ->selectRaw(Orden::selectMontosPorTipo('o.valor_total'))
            ->selectRaw('
                SUM(CASE WHEN (' . Orden::sqlTipo() . ") = 'venta'        THEN 1 ELSE 0 END) AS ordenes_venta,
                SUM(CASE WHEN (" . Orden::sqlTipo() . ") = 'restauracion' THEN 1 ELSE 0 END) AS ordenes_restauracion,
                SUM(CASE WHEN (" . Orden::sqlTipo() . ") = 'fv2'          THEN 1 ELSE 0 END) AS ordenes_fv2
            ")
            ->first();

        // Cartera pendiente del período: lo que falta por cobrar de las órdenes
        // vendidas DENTRO del rango. Se acota por `o.created_at` igual que
        // `total_vendido` para que el recuadro de Resumen cuadre entre sí
        // (vendido, cobrado y saldo del mismo período). El saldo vivo total a
        // hoy —sin filtro de fecha— sigue estando en GET /api/stats/cartera,
        // que es la pestaña "Cartera".
        //
        // Incluye las entregadas que todavía deben: el mueble ya salió pero la
        // plata sigue debiéndose, y dejarlas fuera escondía deuda real.
        $carteraQ = DB::table('v_saldo_ordenes as v')
            ->join('ordenes as o', 'o.id', '=', 'v.orden_id')
            ->whereBetween('o.created_at', $rango)
            ->where('v.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES));
        if ($tiendaId)   $carteraQ->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $carteraQ->where('o.vendedor_id', $vendedorId);
        $cartera = (float) $carteraQ->sum('v.saldo_pendiente');

        return [
            'ingresos_totales'   => $ingresos,
            'cobranza_periodo'   => $cobranzaPeriodo,
            'total_vendido'      => $totalVendido,
            'ordenes_totales'    => $ordenesTotales,
            'ordenes_entregadas' => $entregadas,
            'ordenes_pendientes' => (int) ($ord->pendientes ?? 0),
            'ordenes_canceladas' => (int) ($ord->canceladas ?? 0),
            // Cuánto vale una venta en promedio. Antes era ingresos/entregadas,
            // que daba $0 mientras no hubiera entregas en el período aunque se
            // hubiera vendido de sobra.
            'ticket_promedio'    => $ordenesTotales > 0 ? round($totalVendido / $ordenesTotales) : 0,
            'cartera_pendiente'  => $cartera,
            // De qué tipo de orden viene lo vendido. Los tres suman
            // `total_vendido`, así que el desglose siempre cuadra con el
            // número grande de arriba.
            'por_tipo' => [
                'venta' => [
                    'monto'   => (float) ($porTipo->monto_venta ?? 0),
                    'ordenes' => (int)   ($porTipo->ordenes_venta ?? 0),
                ],
                'restauracion' => [
                    'monto'   => (float) ($porTipo->monto_restauracion ?? 0),
                    'ordenes' => (int)   ($porTipo->ordenes_restauracion ?? 0),
                ],
                'fv2' => [
                    'monto'   => (float) ($porTipo->monto_fv2 ?? 0),
                    'ordenes' => (int)   ($porTipo->ordenes_fv2 ?? 0),
                ],
            ],
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

        // El "% vs período anterior" va debajo de "Total vendido", así que se
        // compara lo vendido —no lo cobrado—, que es lo que el número de arriba
        // dice.
        $varPct = $anterior['total_vendido'] > 0
            ? round(($actual['total_vendido'] - $anterior['total_vendido'])
                    / $anterior['total_vendido'] * 100, 1)
            : null;

        return response()->json([
            'periodo'    => ['desde' => $f['desde'], 'hasta' => $f['hasta']],
            ...$actual,
            'comparativa' => [
                'vendido_anterior'  => $anterior['total_vendido'],
                'cobranza_anterior' => $anterior['cobranza_periodo'],
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
        $rango = $this->rangoUtc($desde, $hasta);

        // Formato MySQL y Carbon según agrupación
        $fmtMysql  = $agrupado === 'mes' ? '%Y-%m' : '%Y-%m-%d';
        $fmtCarbon = $agrupado === 'mes' ? 'Y-m'   : 'Y-m-d';

        // Al agrupar hay que pasar la marca de tiempo a hora de Colombia, o una
        // venta de las 8 de la noche aparece en la barra del día siguiente.
        // Colombia no tiene horario de verano, así que el -5 fijo es exacto.
        $aHoraLocal = "CONVERT_TZ(%s, '+00:00', '-05:00')";

        // Dinero cobrado por período
        $cobradoQ = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango)
            ->selectRaw("DATE_FORMAT(" . sprintf($aHoraLocal, 'p.created_at') . ", '{$fmtMysql}') AS periodo, SUM(p.monto) AS total")
            ->groupBy('periodo')->orderBy('periodo');
        if ($tiendaId)   $cobradoQ->where('o.tienda_id',   $tiendaId);
        if ($vendedorId) $cobradoQ->where('o.vendedor_id', $vendedorId);

        // Valor de órdenes creadas por período
        $ordenesQ = DB::table('ordenes')
            ->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw("DATE_FORMAT(" . sprintf($aHoraLocal, 'created_at') . ", '{$fmtMysql}') AS periodo, SUM(valor_total) AS total")
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
            ->whereBetween('o.created_at', $this->rangoUtc($f['desde'], $f['hasta']))
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
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
            ->whereBetween('o.created_at', $this->rangoUtc($f['desde'], $f['hasta']))
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
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
            // Se incluyen las entregadas que todavía deben: el mueble ya salió
            // pero la plata sigue debiéndose, y dejarlas fuera escondía deuda
            // real. La cartera es lo que falta por cobrar, entregado o no.
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
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

    /**
     * Ids de quienes venden por su cuenta: independientes y el ebanista.
     *
     * Sus ventas no son de ninguna tienda —cada uno lleva su caja— así que se
     * descuentan de los números de las tiendas y se listan en su propia fila.
     */
    private function idsPorSuCuenta(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = DB::table('usuarios')
                ->where('independiente', true)
                ->pluck('id')->all();
        }
        return $ids;
    }

    public function tiendas(Request $request)
    {
        $f     = $this->parseFechas($request);
        $desde = $f['desde']; $hasta = $f['hasta'];
        $rango = $this->rangoUtc($desde, $hasta);

        // La sede de los independientes no es una tienda: sus ventas van en la
        // fila de cada vendedor, no en una sede.
        //
        // Una tienda cerrada sigue saliendo en los meses en que operó: si
        // desapareciera del reporte, la suma de las tiendas dejaría de dar el
        // total de esos meses y nadie entendería el hueco.
        $conMovimiento = DB::table('ordenes')->whereBetween('created_at', $rango)
            ->distinct()->pluck('tienda_id')->filter()->all();

        $tiendas = DB::table('tiendas')
            ->where('es_independientes', false)
            ->where(fn ($q) => $q->where('activa', true)
                                 ->orWhereIn('id', $conMovimiento ?: [0]))
            ->get();

        $mesActual = Carbon::now(self::TZ_NEGOCIO)->format('Y-m');
        $metasVigentes = \App\Models\MetaTienda::vigentesEn($mesActual);
        // Se resuelve una vez para todas las tiendas, no una consulta por cada una.
        $ventasParaMeta = ComisionController::ventasParaMeta();
        $porSuCuenta = $this->idsPorSuCuenta();

        // Todo lo de todas las tiendas, de una vez. Antes se pedía ocho veces
        // por cada tienda —cincuenta y seis consultas, casi veinte segundos de
        // espera— y ahora son cinco para todas.
        $cobros   = $this->cobrosPorTienda($rango, $porSuCuenta);
        $cobradoP = $this->cobradoPeriodoPorTienda($rango, $porSuCuenta);
        $carteras = $this->carteraPorTienda($this->rangoUtc($desde, $hasta), $porSuCuenta);
        $ordenes  = $this->ordenesPorTienda($rango, $porSuCuenta);
        $tops     = $this->mejorVendedorPorTienda($rango, $porSuCuenta);

        $resultado = $tiendas->map(function ($t) use ($mesActual, $metasVigentes, $ventasParaMeta, $cobros, $cobradoP, $carteras, $ordenes, $tops) {
            // Todo esto viene ya resuelto de una sola pasada, agrupado por
            // tienda: antes eran ocho consultas por cada una.
            //
            // "Cobrado" es lo abonado a las órdenes DEL PERÍODO, para que cuadre
            // con "Total vendido" y "Cartera". La caja real —todo lo que entró
            // en el rango, sea de la orden que sea— va aparte en cobranza_periodo.
            $ingresos = (float) ($cobradoP[$t->id]['total'] ?? 0);

            $cobranzaPeriodo = (float) ($cobros['ppal'][$t->id]['total'] ?? 0)
                             + (float) ($cobros['co'][$t->id]['total'] ?? 0);

            $porTipo = [
                'venta'        => (float) ($cobradoP[$t->id]['venta'] ?? 0),
                'restauracion' => (float) ($cobradoP[$t->id]['restauracion'] ?? 0),
                'fv2'          => (float) ($cobradoP[$t->id]['fv2'] ?? 0),
            ];

            $cartera = (float) ($carteras[$t->id] ?? 0);

            $totalOrd    = (int)   ($ordenes[$t->id]['total'] ?? 0);
            $entregadas  = (int)   ($ordenes[$t->id]['entregadas'] ?? 0);
            $totalVendido = (float) ($ordenes[$t->id]['vendido'] ?? 0);

            $top = $tops[$t->id] ?? null;

            // La meta se arrastra: rige la ultima cargada hasta este mes.
            $metaReg        = $metasVigentes[$t->id] ?? null;
            // Lo mismo que ve la pantalla de comisiones, no una suma aparte.
            $totalTiendaMes = $ventasParaMeta[$t->id . '_' . $mesActual] ?? 0.0;
            $metaVal        = $metaReg ? (float) $metaReg->meta : null;
            $pct            = ($metaVal && $metaVal > 0) ? min(100, round($totalTiendaMes / $metaVal * 100, 1)) : null;

            return [
                'tienda_id'          => $t->id,
                'usuario_id'         => null,
                'nombre'             => $t->nombre,
                'ciudad'             => $t->ciudad,
                // La fábrica vende, pero no es una sede al público: se marca
                // para no compararla de tú a tú con las tiendas.
                'es_fabrica'         => (bool) $t->es_fabrica,
                'es_independiente'   => false,
                'ingresos'           => $ingresos,
                'cobranza_periodo'   => $cobranzaPeriodo,
                // De qué tipo de orden viene lo cobrado. Los tres suman `ingresos`.
                'ingresos_por_tipo'  => $porTipo,
                'cartera_pendiente'  => $cartera,
                'total_vendido'      => $totalVendido,
                // De qué tipo de orden es lo vendido. Los tres suman `total_vendido`.
                'vendido_por_tipo'   => [
                    'venta'        => (float) ($ordenes[$t->id]['venta'] ?? 0),
                    'restauracion' => (float) ($ordenes[$t->id]['restauracion'] ?? 0),
                    'fv2'          => (float) ($ordenes[$t->id]['fv2'] ?? 0),
                ],
                'ordenes_totales'    => $totalOrd,
                'ordenes_entregadas' => $entregadas,
                'ticket_promedio'    => $totalOrd > 0 ? round($totalVendido / $totalOrd) : 0,
                'vendedor_destacado' => $top,
                'meta_mes' => [
                    'mes'          => $mesActual,
                    'meta'         => $metaVal,
                    'total_tienda' => $totalTiendaMes,
                    'pct'          => $pct,
                    'cumplida'     => $pct !== null && $pct >= 100,
                ],
            ];
        });

        // De la que más vendió a la que menos, y con los independientes
        // mezclados en el mismo orden. Antes salían en el orden de la tabla y
        // los independientes siempre al final, así que había que ir leyendo
        // cifra por cifra para saber quién iba ganando — que es lo único que
        // se le pregunta a esta pantalla.
        return response()->json(
            $resultado->concat($this->filasPorSuCuenta($rango, $desde, $hasta))
                ->sortByDesc('total_vendido')
                ->values()
        );
    }

    /**
     * Una fila por cada vendedor que va por su cuenta, con la misma forma que
     * la de una tienda para poder ordenarlos y compararlos en la misma tabla.
     */
    private function filasPorSuCuenta(array $rango, string $desde, string $hasta): \Illuminate\Support\Collection
    {
        $rangoCreacion = $this->rangoUtc($desde, $hasta);

        $gente = DB::table('usuarios')
            ->where('activo', true)->where('independiente', true)
            ->orderBy('nombre')->get();

        if ($gente->isEmpty()) return collect();

        // Los tres datos de todos ellos, de una vez. Antes eran tres consultas
        // por cada independiente dentro del bucle.
        $ids = $gente->pluck('id')->all();

        // Caja del período: todo lo que le entró en el rango (de cualquier mes).
        $cobros = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereIn('o.vendedor_id', $ids)
            ->whereBetween('p.created_at', $rango)
            ->selectRaw('o.vendedor_id AS quien, SUM(p.monto) as total')
            ->groupBy('o.vendedor_id')->get()->keyBy('quien');

        // Cobrado de las órdenes del período: es el que va pegado a "Total vendido".
        $cobradoP = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereIn('o.vendedor_id', $ids)
            ->whereBetween('o.created_at', $rangoCreacion)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw('o.vendedor_id AS quien, SUM(p.monto) as total, ' . Orden::selectMontosPorTipo('p.monto'))
            ->groupBy('o.vendedor_id')->get()->keyBy('quien');

        $carteras = DB::table('v_saldo_ordenes as vs')->join('ordenes as o', 'o.id', '=', 'vs.orden_id')
            ->whereIn('o.vendedor_id', $ids)
            ->whereBetween('o.created_at', $rangoCreacion)
            ->where('vs.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            ->selectRaw('o.vendedor_id AS quien, SUM(vs.saldo_pendiente) AS total')
            ->groupBy('o.vendedor_id')->get()->keyBy('quien');

        $ordenes = DB::table('ordenes')
            ->whereIn('vendedor_id', $ids)
            ->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw("vendedor_id AS quien, COUNT(*) AS total, SUM(estado='entregado') AS entregadas, SUM(valor_total) AS vendido, "
                        . Orden::selectMontosPorTipo('valor_total', 'ordenes'))
            ->groupBy('vendedor_id')->get()->keyBy('quien');

        return $gente
            ->map(function ($u) use ($cobros, $cobradoP, $carteras, $ordenes) {
                $cobrado = $cobradoP[$u->id] ?? null;
                $ord     = $ordenes[$u->id] ?? null;

                $ingresos     = (float) ($cobrado->total ?? 0);
                $cobranzaPer  = (float) ($cobros[$u->id]->total ?? 0);
                $cartera      = (float) ($carteras[$u->id]->total ?? 0);
                $entregadas   = (int) ($ord->entregadas ?? 0);
                $totalOrd     = (int) ($ord->total ?? 0);
                $totalVendido = (float) ($ord->vendido ?? 0);

                return [
                    'tienda_id'          => null,
                    'usuario_id'         => $u->id,
                    'nombre'             => $u->nombre,
                    'ciudad'             => null,
                    'es_fabrica'         => false,
                    // Vende por su cuenta: su plata no entra a ninguna tienda.
                    'es_independiente'   => true,
                    'ingresos'           => $ingresos,
                    'cobranza_periodo'   => $cobranzaPer,
                    'ingresos_por_tipo'  => [
                        'venta'        => (float) ($cobrado->monto_venta ?? 0),
                        'restauracion' => (float) ($cobrado->monto_restauracion ?? 0),
                        'fv2'          => (float) ($cobrado->monto_fv2 ?? 0),
                    ],
                    'cartera_pendiente'  => $cartera,
                    'total_vendido'      => $totalVendido,
                    'vendido_por_tipo'   => [
                        'venta'        => (float) ($ord->monto_venta ?? 0),
                        'restauracion' => (float) ($ord->monto_restauracion ?? 0),
                        'fv2'          => (float) ($ord->monto_fv2 ?? 0),
                    ],
                    'ordenes_totales'    => $totalOrd,
                    'ordenes_entregadas' => $entregadas,
                    'ticket_promedio'    => $totalOrd > 0 ? round($totalVendido / $totalOrd) : 0,
                    'vendedor_destacado' => null,
                    'meta_mes' => ['mes' => null, 'meta' => null, 'total_tienda' => 0, 'pct' => null, 'cumplida' => false],
                ];
            });
    }

    /**
     * Lo cobrado por cada tienda, con su reparto por tipo.
     *
     * Dos grupos, que se suman después: lo que entró por sus propias órdenes
     * —acreditado a la tienda que RECIBIÓ la plata, que puede no ser la de la
     * orden— y la mitad de las compartidas donde el covendedor es de esa
     * tienda.
     *
     * @return array{ppal: array<int,array>, co: array<int,array>}
     */
    private function cobrosPorTienda(array $rango, array $porSuCuenta): array
    {
        $monto = 'CASE WHEN o.es_compartida = 1 THEN p.monto / 2 ELSE p.monto END';

        $ppal = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango)
            // Lo que vende quien va por su cuenta no es de esta tienda
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw("COALESCE(p.tienda_id, o.tienda_id) AS quien, SUM($monto) as total, "
                        . Orden::selectMontosPorTipo($monto))
            ->groupByRaw('COALESCE(p.tienda_id, o.tienda_id)')->get();

        $co = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->join('usuarios as u', 'u.id', '=', 'o.covendedor_id')
            ->where('o.es_compartida', true)
            ->whereBetween('p.created_at', $rango)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw('u.tienda_default_id AS quien, SUM(p.monto / 2) as total, '
                        . Orden::selectMontosPorTipo('p.monto / 2'))
            ->groupBy('u.tienda_default_id')->get();

        $mapear = fn ($filas) => collect($filas)->keyBy('quien')->map(fn ($f) => [
            'total'        => (float) $f->total,
            'venta'        => (float) $f->monto_venta,
            'restauracion' => (float) $f->monto_restauracion,
            'fv2'          => (float) $f->monto_fv2,
        ])->all();

        return ['ppal' => $mapear($ppal), 'co' => $mapear($co)];
    }

    /**
     * El saldo vivo de cada tienda, propio y como co-tienda.
     *
     * Mismo criterio que la pestaña Cartera y el Resumen: fuera las canceladas
     * (una orden cancelada no es deuda) y solo saldo positivo (una sobrepagada
     * no resta). Antes esta consulta no lo hacía y la cartera de Tiendas no
     * coincidía con la de la pestaña Cartera.
     *
     * @return array<int,float>
     */
    private function carteraPorTienda(array $rangoCreacion, array $porSuCuenta): array
    {
        $saldo   = 'CASE WHEN o.es_compartida = 1 THEN vs.saldo_pendiente / 2 ELSE vs.saldo_pendiente END';
        $estados = array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES);

        $ppal = DB::table('v_saldo_ordenes as vs')->join('ordenes as o', 'o.id', '=', 'vs.orden_id')
            ->whereBetween('o.created_at', $rangoCreacion)
            ->where('vs.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', $estados)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw("o.tienda_id AS quien, SUM($saldo) AS total")
            ->groupBy('o.tienda_id')->get();

        $co = DB::table('v_saldo_ordenes as vs')->join('ordenes as o', 'o.id', '=', 'vs.orden_id')
            ->join('usuarios as u', 'u.id', '=', 'o.covendedor_id')
            ->where('o.es_compartida', true)
            ->whereBetween('o.created_at', $rangoCreacion)
            ->where('vs.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', $estados)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw('u.tienda_default_id AS quien, SUM(vs.saldo_pendiente / 2) AS total')
            ->groupBy('u.tienda_default_id')->get();

        $out = [];
        foreach ([$ppal, $co] as $conjunto) {
            foreach ($conjunto as $f) {
                $out[(int) $f->quien] = ($out[(int) $f->quien] ?? 0) + (float) $f->total;
            }
        }

        return $out;
    }

    /**
     * Cuántas órdenes y cuánto vendió cada tienda en el rango. `vendido` es el
     * valor de las órdenes creadas dentro del rango (la compartida cuenta la
     * mitad para cada tienda), no "cobrado + cartera" como antes: esa suma
     * mezclaba caja de otros meses con saldo del período y no era "lo que
     * vendió la tienda".
     *
     * @return array<int,array>
     */
    private function ordenesPorTienda(array $rango, array $porSuCuenta): array
    {
        $valor = 'CASE WHEN o.es_compartida = 1 THEN o.valor_total / 2 ELSE o.valor_total END';
        // Y de qué tipo es lo vendido: la tarjeta lo muestra al lado del
        // total, porque el reparto de lo COBRADO (que ya se mostraba) no
        // explica un total que todavía tiene cartera por cobrar.
        $cols  = "COUNT(*) AS total, SUM(o.estado='entregado') AS entregadas, SUM($valor) AS vendido, "
               . Orden::selectMontosPorTipo($valor);

        $ppal = DB::table('ordenes as o')->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw("o.tienda_id AS quien, $cols")
            ->groupBy('o.tienda_id')->get();

        $co = DB::table('ordenes as o')->join('usuarios as u', 'u.id', '=', 'o.covendedor_id')
            ->where('o.es_compartida', true)
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw("u.tienda_default_id AS quien, $cols")
            ->groupBy('u.tienda_default_id')->get();

        $out = [];
        foreach ([$ppal, $co] as $conjunto) {
            foreach ($conjunto as $f) {
                $id = (int) $f->quien;
                $out[$id]['total']      = ($out[$id]['total']      ?? 0) + (int) $f->total;
                $out[$id]['entregadas'] = ($out[$id]['entregadas'] ?? 0) + (int) $f->entregadas;
                $out[$id]['vendido']    = ($out[$id]['vendido']    ?? 0) + (float) $f->vendido;
                foreach (['venta', 'restauracion', 'fv2'] as $tipo) {
                    $out[$id][$tipo] = ($out[$id][$tipo] ?? 0) + (float) $f->{"monto_$tipo"};
                }
            }
        }

        return $out;
    }

    /**
     * Lo cobrado DE LAS ÓRDENES CREADAS EN EL RANGO, por tienda (la de la
     * orden, no la que recibió la plata) y con su reparto por tipo. Es el que
     * va pegado a "Total vendido" para que el recuadro cuadre. La caja real del
     * período —todo lo que entró, sea de la orden que sea— va aparte, en
     * cobrosPorTienda().
     *
     * @return array<int,array{total:float,venta:float,restauracion:float,fv2:float}>
     */
    private function cobradoPeriodoPorTienda(array $rango, array $porSuCuenta): array
    {
        $monto = 'CASE WHEN o.es_compartida = 1 THEN p.monto / 2 ELSE p.monto END';

        $ppal = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw("o.tienda_id AS quien, SUM($monto) as total, " . Orden::selectMontosPorTipo($monto))
            ->groupBy('o.tienda_id')->get();

        $co = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->join('usuarios as u', 'u.id', '=', 'o.covendedor_id')
            ->where('o.es_compartida', true)
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw('u.tienda_default_id AS quien, SUM(p.monto / 2) as total, ' . Orden::selectMontosPorTipo('p.monto / 2'))
            ->groupBy('u.tienda_default_id')->get();

        $out = [];
        foreach ([$ppal, $co] as $conjunto) {
            foreach ($conjunto as $f) {
                $id = (int) $f->quien;
                $out[$id]['total']        = ($out[$id]['total']        ?? 0) + (float) $f->total;
                $out[$id]['venta']        = ($out[$id]['venta']        ?? 0) + (float) $f->monto_venta;
                $out[$id]['restauracion'] = ($out[$id]['restauracion'] ?? 0) + (float) $f->monto_restauracion;
                $out[$id]['fv2']          = ($out[$id]['fv2']          ?? 0) + (float) $f->monto_fv2;
            }
        }

        return $out;
    }

    /**
     * Quién cobró más en cada tienda. Se traen todos de una vez y se queda el
     * primero de cada una, en vez de preguntar tienda por tienda.
     *
     * @return array<int, array{id:int, nombre:string, ingresos:float}>
     */
    private function mejorVendedorPorTienda(array $rango, array $porSuCuenta): array
    {
        $filas = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->join('usuarios as u', 'u.id', '=', 'o.vendedor_id')
            ->whereBetween('p.created_at', $rango)
            ->when($porSuCuenta, fn ($q) => $q->whereNotIn('o.vendedor_id', $porSuCuenta))
            ->selectRaw('COALESCE(p.tienda_id, o.tienda_id) AS tienda, u.id, u.nombre,
                         SUM(CASE WHEN o.es_compartida = 1 THEN p.monto / 2 ELSE p.monto END) AS ingresos')
            ->groupByRaw('COALESCE(p.tienda_id, o.tienda_id), u.id, u.nombre')
            ->orderByDesc('ingresos')
            ->get();

        $out = [];
        foreach ($filas as $f) {
            // Vienen ordenadas de mayor a menor: el primero de cada tienda gana.
            $out[(int) $f->tienda] ??= [
                'id' => (int) $f->id, 'nombre' => $f->nombre, 'ingresos' => (float) $f->ingresos,
            ];
        }

        return $out;
    }

    /**
     * Lo cobrado por cada vendedor en el rango, con su reparto por tipo.
     *
     * Dos consultas: lo de sus propias órdenes y la mitad que le toca de las
     * compartidas donde figura como covendedor. Se suman por persona.
     *
     * @return array<int, array{total:float, venta:float, restauracion:float, fv2:float}>
     */
    private function cobrosPorVendedor(array $rango): array
    {
        $suyas = 'CASE WHEN o.es_compartida = 1 THEN p.monto / 2 ELSE p.monto END';

        $filas = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango)
            ->whereNotNull('o.vendedor_id')
            ->selectRaw("o.vendedor_id AS quien, SUM($suyas) as total, " . Orden::selectMontosPorTipo($suyas))
            ->groupBy('o.vendedor_id')->get();

        $comoCo = DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango)
            ->where('o.es_compartida', true)->whereNotNull('o.covendedor_id')
            ->selectRaw('o.covendedor_id AS quien, SUM(p.monto / 2) as total, ' . Orden::selectMontosPorTipo('p.monto / 2'))
            ->groupBy('o.covendedor_id')->get();

        $out = [];
        foreach ([$filas, $comoCo] as $conjunto) {
            foreach ($conjunto as $f) {
                $id = (int) $f->quien;
                $out[$id]['total']        = ($out[$id]['total']        ?? 0) + (float) $f->total;
                $out[$id]['venta']        = ($out[$id]['venta']        ?? 0) + (float) $f->monto_venta;
                $out[$id]['restauracion'] = ($out[$id]['restauracion'] ?? 0) + (float) $f->monto_restauracion;
                $out[$id]['fv2']          = ($out[$id]['fv2']          ?? 0) + (float) $f->monto_fv2;
            }
        }

        return $out;
    }

    /**
     * Cuántas órdenes y cuánto vendió cada uno en el rango.
     *
     * @return array<int, array{total:int, entregadas:int, canceladas:int, vendido:float}>
     */
    private function ordenesPorVendedor(array $rango): array
    {
        $valor = 'CASE WHEN es_compartida = 1 THEN valor_total / 2 ELSE valor_total END';
        $cols  = "COUNT(*) AS total, SUM(estado='entregado') AS entregadas,
                  SUM(estado='cancelado') AS canceladas, SUM($valor) AS vendido";

        $suyas = DB::table('ordenes')->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->whereNotNull('vendedor_id')
            ->selectRaw("vendedor_id AS quien, $cols")
            ->groupBy('vendedor_id')->get();

        $comoCo = DB::table('ordenes')->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->where('es_compartida', true)->whereNotNull('covendedor_id')
            ->selectRaw("covendedor_id AS quien, $cols")
            ->groupBy('covendedor_id')->get();

        $out = [];
        foreach ([$suyas, $comoCo] as $conjunto) {
            foreach ($conjunto as $f) {
                $id = (int) $f->quien;
                $out[$id]['total']      = ($out[$id]['total']      ?? 0) + (int) $f->total;
                $out[$id]['entregadas'] = ($out[$id]['entregadas'] ?? 0) + (int) $f->entregadas;
                $out[$id]['canceladas'] = ($out[$id]['canceladas'] ?? 0) + (int) $f->canceladas;
                $out[$id]['vendido']    = ($out[$id]['vendido']    ?? 0) + (float) $f->vendido;
            }
        }

        return $out;
    }

    /**
     * El saldo vivo de cada vendedor. Sin filtro de fecha a propósito: es un
     * saldo, no un movimiento del período.
     *
     * @return array<int, float>
     */
    private function carteraPorVendedor(): array
    {
        $saldo   = 'CASE WHEN o.es_compartida = 1 THEN vs.saldo_pendiente / 2 ELSE vs.saldo_pendiente END';
        $estados = array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES);

        $suyas = DB::table('v_saldo_ordenes as vs')->join('ordenes as o', 'o.id', '=', 'vs.orden_id')
            ->where('vs.saldo_pendiente', '>', 0)->whereNotIn('o.estado', $estados)
            ->whereNotNull('o.vendedor_id')
            ->selectRaw("o.vendedor_id AS quien, SUM($saldo) AS total")
            ->groupBy('o.vendedor_id')->get();

        $comoCo = DB::table('v_saldo_ordenes as vs')->join('ordenes as o', 'o.id', '=', 'vs.orden_id')
            ->where('vs.saldo_pendiente', '>', 0)->whereNotIn('o.estado', $estados)
            ->where('o.es_compartida', true)->whereNotNull('o.covendedor_id')
            ->selectRaw('o.covendedor_id AS quien, SUM(vs.saldo_pendiente / 2) AS total')
            ->groupBy('o.covendedor_id')->get();

        $out = [];
        foreach ([$suyas, $comoCo] as $conjunto) {
            foreach ($conjunto as $f) {
                $out[(int) $f->quien] = ($out[(int) $f->quien] ?? 0) + (float) $f->total;
            }
        }

        return $out;
    }

    // ─── GET /api/stats/vendedores  (solo supervisor) ─────────────────────────

    public function vendedores(Request $request)
    {
        $f        = $this->parseFechas($request);
        $tiendaId = $request->query('tienda_id');
        $rango    = $this->rangoUtc($f['desde'], $f['hasta']);

        $vendedores = DB::table('usuarios as u')
            ->leftJoin('tiendas as t', 't.id', '=', 'u.tienda_default_id')
            ->whereIn('u.rol', ['vendedor', 'supervisor'])->where('u.activo', true)
            ->when($tiendaId, fn($q) => $q->where('u.tienda_default_id', $tiendaId))
            ->selectRaw('u.id, u.nombre, u.rol, t.nombre AS tienda, t.id AS tienda_id')
            ->get();

        // Todo de una vez, agrupado por persona, en vez de cuatro consultas por
        // cada vendedor. Con 18 vendedores eran 73 consultas y veinte segundos
        // de espera; así son cinco.
        //
        // Cada cifra se pide dos veces —una por lo que vendió él y otra por lo
        // que le toca como covendedor— porque "suyo o compartido conmigo" no se
        // puede agrupar por una sola columna. Se suman en PHP.
        $porVendedor = $this->cobrosPorVendedor($rango);
        $porOrdenes  = $this->ordenesPorVendedor($rango);
        $porCartera  = $this->carteraPorVendedor();

        $resultado = $vendedores->map(function ($v) use ($porVendedor, $porOrdenes, $porCartera) {
            $cobro = $porVendedor[$v->id] ?? null;
            $ord   = $porOrdenes[$v->id]  ?? null;

            $ingresos       = (float) ($cobro['total'] ?? 0);
            $entregadas     = (int)   ($ord['entregadas'] ?? 0);
            $ordenesTotales = (int)   ($ord['total'] ?? 0);
            $totalVendido   = (float) ($ord['vendido'] ?? 0);
            $cartera        = (float) ($porCartera[$v->id] ?? 0);

            return [
                'id'                 => $v->id,
                'nombre'             => $v->nombre,
                'rol'                => $v->rol,
                'tienda'             => $v->tienda,
                'tienda_id'          => $v->tienda_id,
                'ingresos'           => $ingresos,
                // De qué tipo de orden viene lo que cobró. Suman `ingresos`.
                'ingresos_por_tipo'  => [
                    'venta'        => (float) ($cobro['venta'] ?? 0),
                    'restauracion' => (float) ($cobro['restauracion'] ?? 0),
                    'fv2'          => (float) ($cobro['fv2'] ?? 0),
                ],
                'total_vendido'      => $totalVendido,
                'ordenes_totales'    => $ordenesTotales,
                'ordenes_entregadas' => $entregadas,
                'ordenes_canceladas' => (int) ($ord->canceladas ?? 0),
                'ticket_promedio'    => $ordenesTotales > 0 ? round($totalVendido / $ordenesTotales) : 0,
                'cartera_pendiente'  => $cartera,
            ];
        // Por lo vendido, no por lo cobrado: es la columna que la tabla pone
        // en grande y la que decide el número del ranking. Ordenar por una
        // cifra y numerar por otra hacía que el "#1" no fuera el de arriba en
        // la columna que se está mirando.
        })->sortByDesc('total_vendido')->values();

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
            $rango       = $this->rangoUtc($f['desde'], $f['hasta']);
            $totalEquipo = (float) DB::table('pagos as p')
                ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
                ->whereBetween('p.created_at', $rango)->sum('p.monto');
            $nVendedores = DB::table('usuarios')->whereIn('rol', ['vendedor', 'supervisor'])->where('activo', true)->count();

            $perfil['comparativa_equipo'] = [
                'promedio_ingresos' => $nVendedores > 0 ? round($totalEquipo / $nVendedores) : 0,
                'total_equipo'      => $totalEquipo,
                'num_vendedores'    => $nVendedores,
                // Se compara flujo de caja contra flujo de caja: lo que entró
                // por esta persona vs. lo que entró en toda la empresa.
                'pct_del_total'     => $totalEquipo > 0
                    ? round($perfil['cobranza_periodo'] / $totalEquipo * 100, 1)
                    : 0,
            ];
        }

        return response()->json($perfil);
    }

    // ─── GET /api/stats/conductores  (solo supervisor) ───────────────────────

    public function conductores(Request $request)
    {
        $f     = $this->parseFechas($request);
        $rango = $this->rangoUtc($f['desde'], $f['hasta']);

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

            // Un abono cuenta UNA vez aunque la orden lleve varios renglones de
            // despacho: con el join, una orden partida en dos entregas sumaba
            // su pago dos veces.
            $cobrado = (float) DB::table('pagos as p')
                ->whereBetween('p.created_at', $rango)
                ->whereExists(fn ($q) => $q->from('despacho_items as di')
                    ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
                    ->whereColumn('di.orden_id', 'p.orden_id')
                    ->where('d.conductor_id', $c->id))
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
        $rango     = $this->rangoUtc($f['desde'], $f['hasta']);

        $entregas = DB::table('despacho_items as di')
            ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
            ->where('d.conductor_id', $conductor->id)
            ->where('di.estado', 'entregado')
            ->whereBetween('di.entregado_at', $rango)
            ->count();

        // Un abono cuenta UNA vez aunque la orden lleve varios renglones de
        // despacho (ver conductores()).
        $cobrado = (float) DB::table('pagos as p')
            ->whereBetween('p.created_at', $rango)
            ->whereExists(fn ($q) => $q->from('despacho_items as di')
                ->join('despachos as d', 'd.id', '=', 'di.despacho_id')
                ->whereColumn('di.orden_id', 'p.orden_id')
                ->where('d.conductor_id', $conductor->id))
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
            // El día se saca en hora de Colombia: las etiquetas del gráfico se
            // arman con ese calendario, y agrupando en UTC una entrega de la
            // tarde caía en la clave del día siguiente y no calzaba con ninguna.
            ->selectRaw("DATE(CONVERT_TZ(di.entregado_at, '+00:00', '-05:00')) AS dia, COUNT(*) AS total")
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
        $rango      = $this->rangoUtc($desde, $hasta);
        $esVendedor = $columna === 'vendedor_id';

        // Closure reutilizable: filtra órdenes del vendedor (principal + co-vendedor)
        $whereVendedor = function ($q) use ($valor) {
            $q->where('vendedor_id', $valor)
              ->orWhere(function ($q2) use ($valor) {
                  $q2->where('covendedor_id', $valor)->where('es_compartida', true);
              });
        };
        $whereVendedorO = function ($q) use ($valor) {
            $q->where('o.vendedor_id', $valor)
              ->orWhere(function ($q2) use ($valor) {
                  $q2->where('o.covendedor_id', $valor)->where('o.es_compartida', true);
              });
        };

        // El monto: para vendedor, las co-ventas cuentan a mitad; para tienda,
        // completo. Se filtra siempre por las órdenes de este vendedor/tienda.
        $montoExpr  = $esVendedor
            ? 'SUM(CASE WHEN o.es_compartida = 1 THEN p.monto / 2 ELSE p.monto END) as total'
            : 'SUM(p.monto) as total';
        $filtroVend = fn ($q) => $esVendedor
            ? $q->where($whereVendedorO)
            : $q->where("o.$columna", $valor);
        $pagosBase  = fn () => $filtroVend(
            DB::table('pagos as p')->join('ordenes as o', 'o.id', '=', 'p.orden_id')
        );

        // Cobranza del período: TODO lo que entró en el rango, sin importar de
        // qué mes es la orden. Es flujo de caja.
        $cobranzaPeriodo = (float) $pagosBase()
            ->whereBetween('p.created_at', $rango)
            ->selectRaw($montoExpr)->value('total') ?? 0;

        // Cobrado DE LO VENDIDO EN EL PERÍODO: abonos de órdenes creadas dentro
        // del rango. Este es el que va pegado a "Total vendido" para que el
        // recuadro cuadre. Antes aquí iba la cobranza de arriba y salía "más
        // cobrado que vendido" cuando el mes recibía plata de órdenes viejas.
        $ingresos = (float) $pagosBase()
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw($montoExpr)->value('total') ?? 0;

        // Conteo de órdenes
        $ordBase = $esVendedor
            ? DB::table('ordenes')->where($whereVendedor)
            : DB::table('ordenes')->where($columna, $valor);

        $ord = $ordBase->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw('
                COUNT(*)                                        AS total,
                SUM(estado = "entregado")                       AS entregadas,
                SUM(estado NOT IN ("entregado","cancelado"))    AS pendientes,
                SUM(estado = "cancelado")                       AS canceladas
            ')->first();

        $entregadas     = (int) ($ord->entregadas ?? 0);
        $ordenesCreadas = (int) ($ord->total ?? 0);

        // Lo vendido en el período: el valor de las órdenes hechas dentro de él.
        // Antes se devolvía "ingresos + cartera" con la cartera sin filtro de
        // fecha, así que a cada persona se le sumaba toda la deuda que venía
        // arrastrando de meses anteriores y sus ventas salían infladas.
        $vendidoBase = $esVendedor
            ? DB::table('ordenes')->where($whereVendedor)
            : DB::table('ordenes')->where($columna, $valor);

        $totalVendido = (float) $vendidoBase
            ->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw($esVendedor
                ? 'SUM(CASE WHEN es_compartida = 1 THEN valor_total / 2 ELSE valor_total END) as total'
                : 'SUM(valor_total) as total')
            ->value('total') ?? 0;

        // Cartera: saldo vivo de hoy, a propósito sin filtro de fecha. Incluye
        // las entregadas que todavía deben — el mueble salió pero la plata se
        // sigue debiendo, y dejarlas fuera escondía deuda real.
        if ($esVendedor) {
            $cartera = (float) DB::table('v_saldo_ordenes as v')
                ->join('ordenes as o', 'o.id', '=', 'v.orden_id')
                ->where($whereVendedorO)
                ->where('v.saldo_pendiente', '>', 0)
                ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
                ->selectRaw('SUM(CASE WHEN o.es_compartida = 1 THEN v.saldo_pendiente / 2 ELSE v.saldo_pendiente END) as total')
                ->value('total') ?? 0;
        } else {
            $cartera = (float) DB::table('v_saldo_ordenes as v')
                ->join('ordenes as o', 'o.id', '=', 'v.orden_id')
                ->where("o.$columna", $valor)
                ->where('v.saldo_pendiente', '>', 0)
                ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
                ->sum('v.saldo_pendiente');
        }

        // Top productos
        $topBase = DB::table('orden_items as oi')
            ->join('ordenes as o',   'o.id',  '=', 'oi.orden_id')
            ->join('productos as p', 'p.id',  '=', 'oi.producto_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES));

        if ($esVendedor) $topBase->where($whereVendedorO);
        else             $topBase->where("o.$columna", $valor);

        $topProductos = $topBase
            ->selectRaw('p.id, p.nombre, p.categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->orderByDesc('valor_total')->limit(5)->get();

        // Órdenes del período: las mismas que cuenta "N órdenes en el período"
        // y suman "Total vendido". Antes esto traía las últimas 5 sin filtro de
        // fecha, así que salían órdenes de otros meses y no cuadraba con el
        // número de arriba.
        $recientesBase = DB::table('ordenes as o')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('v_saldo_ordenes as v', 'v.orden_id', '=', 'o.id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES);

        if ($esVendedor) $recientesBase->where($whereVendedorO);
        else             $recientesBase->where("o.$columna", $valor);

        $ordenesRecientes = $recientesBase
            // serie/serie_numero: las FV2 no tienen numero_orden y sin esto se
            // mostrarían con el id interno de la tabla.
            ->selectRaw('o.id, o.numero_orden, o.serie, o.serie_numero, c.nombre AS cliente, o.estado, o.valor_total, COALESCE(v.saldo_pendiente, o.valor_total) AS saldo_pendiente, o.created_at, o.es_compartida')
            ->orderByDesc('o.created_at')->limit(50)->get();

        // Canales
        $canalesBase = DB::table('ordenes')
            ->whereBetween('created_at', $rango)
            ->whereNotIn('estado', Orden::ESTADOS_NO_COMERCIALES);
        if ($esVendedor) $canalesBase->where($whereVendedor);
        else             $canalesBase->where($columna, $valor);

        $canales = $canalesBase->selectRaw('canal, COUNT(*) AS total')->groupBy('canal')->get();

        return [
            'dinero_vendido'     => $ingresos,          // lo cobrado DE las órdenes del período
            'cobranza_periodo'   => $cobranzaPeriodo,   // todo lo que entró en el rango (flujo de caja)
            'total_vendido'      => $totalVendido,
            'ordenes_creadas'    => $ordenesCreadas,
            'ordenes_entregadas' => $entregadas,
            'ordenes_pendientes' => (int) ($ord->pendientes ?? 0),
            'ordenes_canceladas' => (int) ($ord->canceladas ?? 0),
            // Cuánto vale una venta suya en promedio. Antes era
            // cobrado/entregadas, que marcaba $0 mientras no hubiera entregas.
            'ticket_promedio'    => $ordenesCreadas > 0 ? round($totalVendido / $ordenesCreadas) : 0,
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
        $data['periodo']  = ['desde' => $desde, 'hasta' => $hasta];

        // Meta mensual de la tienda del vendedor (siempre mes actual, independiente del período)
        $mesActual  = Carbon::now(self::TZ_NEGOCIO)->format('Y-m');
        // La meta se arrastra: no hace falta volver a cargarla cada mes.
        $vigentes   = \App\Models\MetaTienda::vigentesEn($mesActual);

        // La tienda contra la que se mide NO es la de su ficha: es la del
        // equipo que cobra el pool (ver tiendaParaMetaDe). Antes se miraba
        // tienda_default_id, y a quien estaba cubriendo en otra sede, o vendía
        // desde otra tienda, le salía la meta y el "faltan" de una tienda que
        // no era la suya —distintos a los del reporte por tienda, que sí van
        // por donde entró la venta.
        $tiendaMeta = $this->tiendaParaMetaDe($vendedorId, $vendedor->tienda_id, $mesActual);
        $tiendaId   = $tiendaMeta['id'];
        $metaReg    = $tiendaId ? ($vigentes[$tiendaId] ?? null) : null;

        // Lo mismo que ve la pantalla de comisiones, no una suma aparte.
        $totalTiendaMes = $tiendaId
            ? (ComisionController::ventasParaMeta()[$tiendaId . '_' . $mesActual] ?? 0.0)
            : 0;

        $meta = $metaReg ? (float) $metaReg->meta : null;
        $pct  = ($meta && $meta > 0) ? min(100, round($totalTiendaMes / $meta * 100, 1)) : null;

        $data['meta_mes'] = [
            'mes'          => $mesActual,
            'tienda_id'    => $tiendaId,
            'tienda'       => $tiendaMeta['nombre'],
            'por_que'      => $tiendaMeta['por_que'],
            'meta'         => $meta,
            'total_tienda' => $totalTiendaMes,
            'pct'          => $pct,
            'cumplida'     => $pct !== null && $pct >= 100,
        ];

        return $data;
    }

    /**
     * Contra qué tienda se mide la meta de un vendedor este mes.
     *
     * La ficha del usuario (tienda_default_id) dice dónde suele estar, pero
     * la meta y el pool se pagan por la tienda donde de verdad está: si fue a
     * cubrir a otra sede, o se trasladó, ahí es donde le toca. Y si no está
     * en ningún equipo, manda donde entraron sus ventas del mes —que es lo
     * que el reporte por tienda le suma a esa tienda—. Solo al final se cae
     * a la ficha.
     *
     * @return array{id: ?int, nombre: ?string, por_que: string}
     */
    private function tiendaParaMetaDe(int $vendedorId, ?int $tiendaDefault, string $mes): array
    {
        $hoy = Carbon::now(self::TZ_NEGOCIO)->toDateString();

        // 1. Hoy está cubriendo en otra sede o se trasladó: ahí es donde está.
        $movimiento = DB::table('tienda_reemplazos')
            ->where('usuario_id', $vendedorId)
            ->whereDate('desde', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('hasta')->orWhereDate('hasta', '>=', $hoy))
            ->orderByDesc('desde')
            ->first();
        if ($movimiento) {
            return $this->tiendaConNombre((int) $movimiento->tienda_id,
                $movimiento->tipo === \App\Models\TiendaReemplazo::TRASLADO ? 'trasladado a esta tienda' : 'cubriendo en esta tienda');
        }

        // 2. Es del equipo fijo de una tienda este mes.
        foreach (\App\Models\TiendaAsesor::vigentesEn($mes) as $tid => $gente) {
            if ($gente->contains(fn ($a) => (int) $a->vendedor_id === $vendedorId)) {
                return $this->tiendaConNombre((int) $tid, 'equipo de la tienda');
            }
        }

        // 3. Donde entraron sus ventas del mes (la misma fuente que la meta).
        $conMasVentas = DB::table('comisiones as c')
            ->join('ordenes as o', 'o.id', '=', 'c.orden_id')
            ->where('c.vendedor_id', $vendedorId)
            ->where('c.mes_venta', $mes)
            ->whereNotIn('o.estado', Orden::ESTADOS_NO_COMERCIALES)
            ->selectRaw('c.tienda_id, SUM(c.valor_orden) AS total')
            ->groupBy('c.tienda_id')
            ->orderByDesc('total')
            ->value('tienda_id');
        if ($conMasVentas) {
            return $this->tiendaConNombre((int) $conMasVentas, 'donde entraron sus ventas del mes');
        }

        // 4. Lo que dice su ficha.
        return $tiendaDefault
            ? $this->tiendaConNombre($tiendaDefault, 'tienda de su ficha')
            : ['id' => null, 'nombre' => null, 'por_que' => 'sin tienda'];
    }

    private function tiendaConNombre(int $tiendaId, string $porQue): array
    {
        return [
            'id'      => $tiendaId,
            'nombre'  => DB::table('tiendas')->where('id', $tiendaId)->value('nombre'),
            'por_que' => $porQue,
        ];
    }
}
