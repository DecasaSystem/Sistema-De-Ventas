<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Http\Controllers\StatsController;
use App\Models\Orden;
use App\Models\Usuario;
use App\Support\ReglasComision;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La comisión de los vendedores independientes.
 *
 * No tiene nada que ver con la de las tiendas. Ahí hay una meta, un pool y un
 * reparto entre asesores; aquí es un porcentaje fijo, y solo una parte se
 * comparte.
 *
 * La regla:
 *
 *   RESTAURACIÓN  -> se reparte. Todas las restauraciones del mes, hechas
 *                    por cualquiera de los independientes, se suman en un
 *                    solo bolsón y cada uno cobra el 5% de ESE bolsón
 *                    completo — no la mitad, el 5% cada uno, sin importar
 *                    quién hizo cuál.
 *   VENTA         -> es de quien la hizo. 5% de lo suyo (÷1,19 primero),
 *                    solo para esa persona. No entra al bolsón.
 *
 *   Y aparte, si se comparte con un almacén, el almacén cobra su propio 5%
 *   (venta o restauración, da igual), sobre el valor completo.
 *
 *   RESTAURACIÓN DE UN ALMACÉN -> también entra al bolsón. Toda restauración
 *                    le suma a los independientes, la suba quien la suba: si
 *                    la hace alguien de Decasa Norte, la cuenta es la misma
 *                    que si la hubiera subido Henry compartida con Norte. El
 *                    bolsón la recibe entera (sin partir, sin IVA, sin lo del
 *                    datáfono) y la tienda sigue cobrando su 5% por su lado,
 *                    con sus reglas de siempre (el equipo o quien la hizo).
 *
 * Lo de las mitades es otra cosa: a la META del almacén le suma la mitad de
 * la venta, y solo si es venta —una restauración compartida le paga su 5% al
 * almacén pero no le cuenta para la meta.
 *
 * Sobre qué se saca ese 5% lo decide `conLaBaseQueDeVerdadComisiona()`: el
 * valor de la orden menos lo que se llevó la franquicia de la tarjeta. Es la
 * misma regla de las tiendas, y hasta ahora no estaba de este lado.
 */
class ComisionIndependientes
{
    /** Lo que cobra cada independiente, y cada almacén que ayudó. */
    public const PORCENTAJE = 0.05;

    /**
     * El renglón con que se registra lo pagado del bolsón de restauraciones.
     * No cuelga de ninguna orden: es la parte de cada independiente del 5% de
     * todas las restauraciones del mes.
     */
    public const ORIGEN_BOLSON = 'bolson_indep';

    /** Colombia no tiene horario de verano: siempre UTC−5. Para CONVERT_TZ en SQL. */
    private const DESFASE_COLOMBIA = '-05:00';

    /**
     * A una VENTA se le quita el IVA antes de sacar el 5%; a una restauración
     * no. Son cosas distintas: en la restauración se cobra mano de obra.
     */
    public const IVA = 1.19;

    /**
     * Deja la fila con la base sobre la que de verdad se comisiona.
     *
     * Dos cosas se le quitan al valor de la orden, en este orden:
     *
     *   1. Lo que se llevó la franquicia de la tarjeta. De cada peso cobrado
     *      por datáfono a la empresa le entra el 94,5%, y sobre lo que nunca
     *      llegó a la caja no comisiona nadie. Esto no estaba: la cuenta salía
     *      de `valor_total` a secas y nunca miraba cómo había pagado el
     *      cliente, así que la misma venta pagaba distinto según quién la
     *      hiciera —un independiente cobraba sobre los $10.000.000 y alguien
     *      de tienda sobre $9.450.000—.
     *   2. La mitad, si la venta se comparte con otro asesor.
     *
     * `valor_orden` se guarda aparte porque el requisito de que el cliente
     * haya pagado el 50% se mide contra la orden de verdad, no contra la base
     * ya recortada.
     */
    private static function conLaBaseQueDeVerdadComisiona(object $o, string $mes): object
    {
        $o->valor_orden = (float) $o->valor_orden;

        $base = $o->valor_orden - round(
            (float) $o->con_tarjeta * ReglasComision::numero('franquicia_pct', $mes)
        );

        $o->valor_total = $o->es_compartida
            ? round($base * ReglasComision::numero('compartida_parte_vendedor', $mes))
            : $base;

        return $o;
    }

    /**
     * @return array{
     *   mes:string, base:float, porcentaje:float,
     *   independientes:array, almacenes:array, ordenes:array
     * }
     */
    /** Quiénes son independientes. Ver delMes() y olvidarCache(). */
    private static ?\Illuminate\Support\Collection $independientes = null;

    public static function olvidarCache(): void
    {
        self::$independientes = null;
    }

    public static function delMes(string $mes): array
    {
        [$desde, $hasta] = self::rangoUtc($mes);

        // La misma lista para cada mes del cálculo: se pide una vez.
        $independientes = self::$independientes ??= Usuario::where('independiente', true)->get(['id', 'nombre']);
        if ($independientes->isEmpty()) {
            return ['mes' => $mes, 'base' => 0.0, 'base_venta' => 0.0, 'base_restauracion' => 0.0,
                    'base_restauracion_almacenes' => 0.0, 'bolson_restauraciones' => 0.0,
                    'base_lista' => 0.0, 'base_pendiente' => 0.0,
                    'se_cobra_el' => ComisionController::diaDePagoDe($mes)->toDateString(),
                    'llego_la_fecha' => false, 'porcentaje' => ReglasComision::numero('indep_pct_venta', $mes),
                    'comision_restauraciones' => 0.0, 'comision_restauraciones_lista' => 0.0,
                    'independientes' => [], 'almacenes' => [], 'ordenes' => [],
                    'restauraciones_almacenes' => []];
        }

        $ordenes = self::ordenesDelMes($desde, $hasta, $mes)
            ->whereIn('o.vendedor_id', $independientes->pluck('id'))
            ->get()
            ->map(fn ($o) => self::conLaBaseQueDeVerdadComisiona($o, $mes));

        // Una restauración no le suma a la meta del almacén aunque se comparta.
        $idsRestauracion = self::idsDeRestauracion($ordenes->pluck('id')->all());

        // Las restauraciones que subió alguien de un almacén. Cuentan como si
        // las hubiera subido Henry compartidas con esa tienda: entran al
        // bolsón por el valor entero. `es_compartida` entre dos asesores de
        // tienda no la parte aquí —eso es cómo se reparten ellos su 5%, no
        // cuánto entra al bolsón—, así que se ignora.
        $deAlmacenes = self::ordenesDelMes($desde, $hasta, $mes)
            ->whereNotIn('o.vendedor_id', $independientes->pluck('id'))
            ->whereIn('o.id', function ($q) {
                $q->from('orden_items')->select('orden_id')
                  ->groupBy('orden_id')
                  ->havingRaw('COUNT(*) = SUM(es_restauracion)');
            })
            ->get()
            ->map(function ($o) use ($mes) {
                $o->es_compartida = false;
                return self::conLaBaseQueDeVerdadComisiona($o, $mes);
            });

        // Las reglas del mes (ReglasComision). Las de porcentaje que se pueden
        // cambiar por persona se piden adentro, con cada independiente.
        $pctCobrar      = ReglasComision::numero('req_pagado_pct', $mes);
        $restEspera     = ReglasComision::si('req_pagado_restauracion', $mes);
        $pctBolson      = ReglasComision::numero('indep_pct_bolson', $mes);
        $conAlmacenes   = ReglasComision::si('bolson_incluye_almacenes', $mes);
        $pctVentaDe     = fn ($vendedorId) => ReglasComision::numero('indep_pct_venta', $mes, null, (int) $vendedorId);
        $pctBolsonDe    = fn ($vendedorId) => ReglasComision::numero('indep_pct_bolson', $mes, null, (int) $vendedorId);

        // Se cobra igual que en las tiendas: cuando el cliente ha pagado la
        // mitad Y llega el 20 del mes siguiente a la venta.
        $hoy       = Carbon::today(StatsController::TZ_NEGOCIO);
        $seCobraEl = ComisionController::diaDePagoDe($mes);
        $llegoLaFecha = $hoy->gte($seCobraEl);

        // Contra el valor de la ORDEN, no contra la base que comisiona: esa ya
        // viene sin el datáfono y partida por la mitad si es compartida, así
        // que comparar el abono del cliente con ella daba por cumplida la
        // mitad cuando llevaba pagado bastante menos.
        //
        // Las restauraciones también esperan la mitad (dueño, 2026-10-23: "si
        // deben tener el 50 % pagado las restauraciones"). Antes se pagaban con
        // solo llegar la fecha; el bolsón crecía con restauraciones que el
        // cliente apenas había abonado (R-1113 de Henry, $0 pagado, ya "lista").
        $estaLista = fn ($o, bool $esRestauracion) => $llegoLaFecha
            && (($esRestauracion && ! $restEspera) || (float) $o->pagado >= (float) $o->valor_orden * $pctCobrar);
        $listas            = $ordenes->filter(fn ($o) => $estaLista($o, in_array($o->id, $idsRestauracion, true)));
        // Las de los almacenes son todas restauraciones: la misma regla.
        $deAlmacenesListas = $deAlmacenes->filter(fn ($o) => $estaLista($o, true));

        /** Lo que paga un grupo de órdenes, mezclando venta y restauración — para el almacén, que cobra igual sobre las dos. */
        $pagaPor = function ($grupo, int $tiendaId) use ($idsRestauracion, $mes) {
            $venta = (float) $grupo->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                ->sum('valor_total');
            $rest  = (float) $grupo->filter(fn ($o) => in_array($o->id, $idsRestauracion, true))
                ->sum('valor_total');
            return (ReglasComision::sinIva($venta, false, $mes, $tiendaId) + ReglasComision::sinIva($rest, true, $mes, $tiendaId))
                * ReglasComision::numero('almacen_pct', $mes, $tiendaId);
        };

        /** Solo la parte de restauración de un grupo — lo que se reparte. */
        $pagaRestauracion = fn ($grupo, float $pct) => ReglasComision::sinIva((float) $grupo
            ->filter(fn ($o) => in_array($o->id, $idsRestauracion, true))
            ->sum('valor_total'), true, $mes) * $pct;

        /** Solo la parte de venta de un grupo — de quien la hizo, nadie más. */
        /** A una FV2 marcada no se le quita el IVA: ver `ivaDe()`. */
        $pagaVenta = fn ($grupo, float $pct) => (float) $grupo
            ->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
            ->sum(fn ($o) => (float) $o->valor_total / self::ivaDe($o, $mes)) * $pct;

        /** El bolsón con un porcentaje: lo de los independientes y, si la regla lo dice, lo de los almacenes. */
        $bolson = fn ($ordenesGrupo, $almacenesGrupo, float $pct) => $pagaRestauracion($ordenesGrupo, $pct)
            + ($conAlmacenes ? (float) $almacenesGrupo->sum('valor_total') * $pct : 0.0);

        $base          = (float) $ordenes->sum('valor_total');
        $baseLista     = (float) $listas->sum('valor_total');
        $basePendiente = $base - $baseLista;

        // Cuanto de la base es venta y cuanto restauracion. En pantalla el
        // total solo no explica el monto: las dos partes llevan cuentas
        // distintas y una de ellas no descuenta IVA.
        $baseVenta = (float) $ordenes->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
            ->sum('valor_total');
        $baseRest  = $base - $baseVenta;

        // Lo que subieron los almacenes: todo es restauración.
        $baseRestAlmacenes = (float) $deAlmacenes->sum('valor_total');

        // El bolsón de las restauraciones: se suman TODAS, de cualquiera de
        // los independientes y de cualquier almacén, y cada uno cobra el 5%
        // de esa suma completa. Esto sí es igual para todos (salvo que a
        // alguien se le ponga otro porcentaje del bolsón: se calcula abajo).
        $comisionRestauraciones      = $bolson($ordenes, $deAlmacenes, $pctBolson);
        $comisionRestauracionesLista = $bolson($listas, $deAlmacenesListas, $pctBolson);

        // Lo que ya se les pagó este mes. Se lleva por orden (la venta está
        // pagada o no) y el bolsón por monto, porque no cuelga de ninguna
        // orden: se le debe lo listo del bolsón menos lo que ya se le dio.
        $pagos = DB::table('comisiones')
            ->where('mes_venta', $mes)->where('estado', 'pagada')
            ->whereIn('vendedor_id', $independientes->pluck('id'))
            ->get(['vendedor_id', 'orden_id', 'origen', 'monto_comision']);
        $ordenPagada = $pagos->whereNotNull('orden_id')
            ->mapWithKeys(fn ($p) => [$p->vendedor_id . '_' . $p->orden_id => true])->all();

        // La venta es de quien la hizo: cada uno cobra solo sobre sus propias
        // órdenes, sin sumarse con las del otro independiente.
        $porIndependiente = $independientes->map(function ($u) use (
            $ordenes, $listas, $idsRestauracion, $pagaVenta, $bolson, $pctVentaDe, $pctBolsonDe,
            $deAlmacenes, $deAlmacenesListas, $pagos, $ordenPagada
        ) {
            $suyas       = $ordenes->where('vendedor_id', $u->id);
            $suyasListas = $listas->where('vendedor_id', $u->id);
            $venta       = (float) $suyas->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                ->sum('valor_total');

            $pctVenta  = $pctVentaDe($u->id);
            $pctBolsonSuyo = $pctBolsonDe($u->id);
            $comisionRestauraciones      = $bolson($ordenes, $deAlmacenes, $pctBolsonSuyo);
            $comisionRestauracionesLista = $bolson($listas, $deAlmacenesListas, $pctBolsonSuyo);

            $comisionVenta      = $pagaVenta($suyas, $pctVenta);
            $comisionVentaLista = $pagaVenta($suyasListas, $pctVenta);

            $comisionTotal  = $comisionVenta + $comisionRestauraciones;
            $comisionLista  = $comisionVentaLista + $comisionRestauracionesLista;

            // Lo que todavía se le puede pagar: sus ventas listas que no se le
            // han pagado, y lo que falta del bolsón.
            $ventasSinPagar = $suyasListas->reject(fn ($o) => isset($ordenPagada[$u->id . '_' . $o->id]));
            $bolsonPagado   = (float) $pagos->where('vendedor_id', $u->id)
                ->where('origen', self::ORIGEN_BOLSON)->sum('monto_comision');
            $ventasPorPagar = round($pagaVenta($ventasSinPagar, $pctVenta));
            $bolsonPorPagar = max(0, round($comisionRestauracionesLista) - round($bolsonPagado));

            return [
                'vendedor_id' => $u->id,
                'nombre'      => $u->nombre,
                'vendio'      => (float) $suyas->sum('valor_total'),
                'vendio_venta'        => $venta,
                'vendio_restauracion' => (float) $suyas->sum('valor_total') - $venta,
                'comision'           => round($comisionTotal),
                'comision_lista'     => round($comisionLista),
                'comision_pendiente' => round($comisionTotal - $comisionLista),
                // El desglose: cuánto es solo suyo y cuánto viene del bolsón
                // compartido de restauraciones. Las dos suman el total.
                'comision_ventas_propias' => round($comisionVenta),
                'comision_restauraciones' => round($comisionRestauraciones),
                // Lo ya pagado (lo que de verdad quedó registrado) y lo que
                // falta pagarle de lo que ya está listo.
                'comision_pagada'          => round((float) $pagos->where('vendedor_id', $u->id)->sum('monto_comision')),
                'por_pagar_ventas'         => $ventasPorPagar,
                'por_pagar_bolson'         => $bolsonPorPagar,
                'comision_por_pagar'       => $ventasPorPagar + $bolsonPorPagar,
            ];
        })->values()->all();

        // Cada almacén cobra sobre lo que se compartió con él.
        $almacenes = $ordenes->whereNotNull('tienda_abonada_id')
            ->groupBy('tienda_abonada_id')
            ->map(function ($grupo) use ($idsRestauracion, $listas, $pagaPor, $mes) {
                $idsListas = $listas->pluck('id')->all();
                $tiendaId  = (int) $grupo->first()->tienda_abonada_id;
                $compartido = (float) $grupo->sum('valor_total');
                // A la meta solo le suma la mitad de lo que NO es restauración.
                $paraMeta = (float) $grupo
                    ->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                    ->sum('valor_total') * ReglasComision::numero('almacen_fraccion_meta', $mes, $tiendaId);

                return [
                    'tienda_id'   => $tiendaId,
                    'nombre'      => $grupo->first()->almacen,
                    'compartido'  => $compartido,
                    'comision'    => round($pagaPor($grupo, $tiendaId)),
                    'comision_lista' => round(
                        $pagaPor($grupo->filter(fn ($o) => in_array($o->id, $idsListas, true)), $tiendaId)
                    ),
                    'suma_a_meta' => $paraMeta,
                    'ordenes'     => $grupo->count(),
                ];
            })->values()->all();

        return [
            'mes'            => $mes,
            'base'           => $base,
            'base_venta'         => $baseVenta,
            'base_restauracion'  => $baseRest,
            // Las que subieron los almacenes: no son "vendido" de ningún
            // independiente, pero sí le suman al bolsón.
            'base_restauracion_almacenes' => $baseRestAlmacenes,
            'bolson_restauraciones'       => $baseRest + ($conAlmacenes ? $baseRestAlmacenes : 0.0),
            'base_lista'     => $baseLista,
            'base_pendiente' => $basePendiente,
            'se_cobra_el'    => $seCobraEl->toDateString(),
            'llego_la_fecha' => $llegoLaFecha,
            'porcentaje'     => ReglasComision::numero('indep_pct_venta', $mes),
            // El bolsón de restauraciones: un solo número, igual para todos
            // los independientes — es lo único que de verdad se comparte.
            'comision_restauraciones'       => round($comisionRestauraciones),
            'comision_restauraciones_lista' => round($comisionRestauracionesLista),
            'independientes' => $porIndependiente,
            'almacenes'      => $almacenes,
            'ordenes'        => $ordenes->map(fn ($o) => [
                'id'             => $o->id,
                'referencia'     => $o->serie ? "{$o->serie}-{$o->serie_numero}" : ('#' . ($o->numero_orden ?? $o->id)),
                'cliente'        => $o->cliente,
                'vendedor'       => $o->vendedor,
                'vendedor_id'    => (int) $o->vendedor_id,
                'valor'          => (float) $o->valor_total,
                'estado'         => $o->estado,
                'fecha'          => $o->created_at,
                'almacen'        => $o->almacen,
                'es_restauracion'=> in_array($o->id, $idsRestauracion, true),
                'suma_a_meta'    => ($o->tienda_abonada_id && ! in_array($o->id, $idsRestauracion, true))
                                    ? (float) $o->valor_total * ReglasComision::numero('almacen_fraccion_meta', $mes, (int) $o->tienda_abonada_id) : 0.0,
                'pagado'         => (float) $o->pagado,
                'pago_completo'  => (float) $o->pagado >= (float) $o->valor_orden * $pctCobrar,
                'lista'          => $listas->contains('id', $o->id),
                'pagada'         => isset($ordenPagada[$o->vendedor_id . '_' . $o->id]),
                'paga'           => round(in_array($o->id, $idsRestauracion, true)
                                    ? ReglasComision::sinIva((float) $o->valor_total, true, $mes) * $pctBolsonDe($o->vendedor_id)
                                    : (float) $o->valor_total / self::ivaDe($o, $mes) * $pctVentaDe($o->vendedor_id)),
                // Una FV2 marcada: a su venta no se le quitó el IVA.
                'sin_descontar_iva' => (bool) $o->sin_descontar_iva,
            ])->values()->all(),
            // Aparte de `ordenes`: esas son de los independientes y la
            // pantalla las filtra por vendedor. Estas no son de ninguno.
            'restauraciones_almacenes' => $deAlmacenes->map(fn ($o) => [
                'id'         => $o->id,
                'referencia' => $o->serie ? "{$o->serie}-{$o->serie_numero}" : ('#' . ($o->numero_orden ?? $o->id)),
                'cliente'    => $o->cliente,
                'vendedor'   => $o->vendedor,
                'vendedor_id'=> (int) $o->vendedor_id,
                'almacen'    => $o->tienda,
                'valor'      => (float) $o->valor_total,
                'estado'     => $o->estado,
                'fecha'      => $o->created_at,
                'pagado'     => (float) $o->pagado,
                'lista'      => $deAlmacenesListas->contains('id', $o->id),
                'paga'       => round(ReglasComision::sinIva((float) $o->valor_total, true, $mes) * $pctBolson),
            ])->values()->all(),
        ];
    }

    /**
     * Por cuánto se divide la venta antes de sacar el 5%.
     *
     * 1,19 siempre, salvo en una FV2 marcada como "no se resta el IVA" —la
     * que hace Henry cuando llega alguien de la familia del dueño—. Es solo
     * para quien la vendió: el almacén con el que se comparte cobra con la
     * regla de siempre.
     */
    private static function ivaDe(object $o, string $mes): float
    {
        if (! empty($o->sin_descontar_iva) || ! ReglasComision::si('iva_en_venta', $mes)) return 1.0;

        return ReglasComision::numero('iva_divisor', $mes);
    }

    /** Las órdenes del mes con lo que hace falta para comisionarlas. */
    private static function ordenesDelMes(string $desde, string $hasta, string $mes)
    {
        return DB::table('ordenes as o')
            ->join('usuarios as u', 'u.id', '=', 'o.vendedor_id')
            ->leftJoin('tiendas as t', 't.id', '=', 'o.tienda_abonada_id')
            ->leftJoin('tiendas as tv', 'tv.id', '=', 'o.tienda_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->whereBetween('o.created_at', [$desde, $hasta])
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            // Marcada por un supervisor: no paga comisión ni entra al bolsón.
            ->tap(fn ($q) => Orden::sinLasFueraDelPool($q))
            ->select(
                'o.id', 'o.numero_orden', 'o.serie', 'o.serie_numero',
                'o.valor_total as valor_orden', 'o.es_compartida',
                'o.estado', 'o.created_at', 'o.tienda_abonada_id', 'o.sin_descontar_iva',
                'u.nombre as vendedor', 'o.vendedor_id',
                't.nombre as almacen', 'tv.nombre as tienda', 'c.nombre as cliente'
            )
            ->selectSub(
                DB::table('pagos')->selectRaw('COALESCE(SUM(monto),0)')->whereColumn('orden_id','o.id'),
                'pagado'
            )
            ->selectSub(
                DB::table('pagos')->selectRaw('COALESCE(SUM(monto),0)')
                    ->whereColumn('orden_id', 'o.id')->whereIn('metodo', (array) ReglasComision::valor('franquicia_metodos', $mes)),
                'con_tarjeta'
            )
            ->orderBy('o.created_at');
    }

    /**
     * Lo que los independientes le abonaron a cada tienda para su META.
     *
     * Es la mitad de cada venta compartida, PERO solo de las ventas: una
     * restauración compartida le paga su 5% al almacén y no le cuenta para la
     * meta. Vive aquí y no en el controlador para que la meta y lo que se
     * muestra en pantalla salgan del mismo sitio: estaban duplicados y decían
     * cosas distintas.
     *
     * @return array<string,float>  ['tienda_mes' => monto]
     */
    public static function abonadoParaMeta(bool $soloConLaMitadPagada = false): array
    {
        // El mes de cada orden en hora de Colombia, y las reglas de cada
        // almacén y mes dentro del SQL (ReglasComision::sql).
        $mesSql = "DATE_FORMAT(CONVERT_TZ(ordenes.created_at,'+00:00','" . self::DESFASE_COLOMBIA . "'),'%Y-%m')";

        return DB::table('ordenes')
            ->whereNotNull('tienda_abonada_id')
            // Para el pool: una venta cuenta cuando el cliente ya pagó la
            // mitad, igual que las de la propia tienda.
            ->when($soloConLaMitadPagada, fn ($q) => $q->whereRaw(
                ComisionController::sqlCuentaParaMeta('ordenes', 'ordenes.tienda_abonada_id', $mesSql)
            ))
            ->whereNotIn('estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            ->tap(fn ($q) => Orden::sinLasFueraDelPool($q, 'ordenes'))
            // Fuera las que son íntegramente restauración
            ->whereNotIn('id', function ($q) {
                $q->from('orden_items')->select('orden_id')
                  ->groupBy('orden_id')
                  ->havingRaw('COUNT(*) = SUM(es_restauracion)');
            })
            ->selectRaw(
                "tienda_abonada_id, DATE_FORMAT(CONVERT_TZ(created_at,'+00:00','" . self::DESFASE_COLOMBIA . "'),'%Y-%m') as mes, " .
                // Sin lo que se llevó el datáfono, igual que las ventas propias
                // de la tienda: lo que suman las comisiones ya viene neto, y si
                // esto entrara bruto la meta se movería según quién vendió.
                // Los medios con franquicia salen de la misma lista que usa el
                // resto del módulo: escritos aquí a mano, uno nuevo (como pasó
                // con Addi) movía la comisión pero no la meta.
                // La lista y el porcentaje son los de la regla de comisiones
                // (franquicia_metodos, franquicia_pct), y lo que le suma al
                // almacén, su parte (almacen_fraccion_meta, la mitad hoy).
                'SUM(valor_total - ROUND(COALESCE(' .
                '  (SELECT SUM(p.monto) FROM pagos p' .
                '   WHERE p.orden_id = ordenes.id AND ' . ReglasComision::sqlEnLista('franquicia_metodos', 'p.metodo', $mesSql) . '), 0) * ' .
                ReglasComision::sql('franquicia_pct', 'ordenes.tienda_abonada_id', $mesSql) . ')) * ' .
                ReglasComision::sql('almacen_fraccion_meta', 'ordenes.tienda_abonada_id', $mesSql) . ' as total'
            )
            ->groupBy('tienda_abonada_id', 'mes')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->tienda_abonada_id . '_' . $r->mes => (float) $r->total])
            ->all();
    }

    /** Órdenes cuyos ítems son todos restauración. */
    private static function idsDeRestauracion(array $ordenIds): array
    {
        if (! $ordenIds) return [];

        return DB::table('orden_items')
            ->whereIn('orden_id', $ordenIds)
            ->groupBy('orden_id')
            ->selectRaw('orden_id, COUNT(*) as total, SUM(es_restauracion) as restauraciones')
            ->get()
            ->filter(fn ($r) => (int) $r->total > 0 && (int) $r->total === (int) $r->restauraciones)
            ->pluck('orden_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** El mes en hora de Colombia, no en la del servidor. */
    private static function rangoUtc(string $mes): array
    {
        $tz = StatsController::TZ_NEGOCIO;
        return [
            Carbon::parse($mes . '-01', $tz)->startOfMonth()->setTimezone('UTC')->toDateTimeString(),
            Carbon::parse($mes . '-01', $tz)->endOfMonth()->setTimezone('UTC')->toDateTimeString(),
        ];
    }
}
