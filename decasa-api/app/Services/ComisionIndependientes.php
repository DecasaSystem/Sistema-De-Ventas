<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Http\Controllers\StatsController;
use App\Models\Orden;
use App\Models\Usuario;
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
    private static function conLaBaseQueDeVerdadComisiona(object $o): object
    {
        $o->valor_orden = (float) $o->valor_orden;

        $base = $o->valor_orden - round(
            (float) $o->con_tarjeta * ComisionController::COSTO_TARJETA
        );

        $o->valor_total = $o->es_compartida ? round($base / 2) : $base;

        return $o;
    }

    /**
     * @return array{
     *   mes:string, base:float, porcentaje:float,
     *   independientes:array, almacenes:array, ordenes:array
     * }
     */
    public static function delMes(string $mes): array
    {
        [$desde, $hasta] = self::rangoUtc($mes);

        $independientes = Usuario::where('independiente', true)->get(['id', 'nombre']);
        if ($independientes->isEmpty()) {
            return ['mes' => $mes, 'base' => 0.0, 'base_venta' => 0.0, 'base_restauracion' => 0.0,
                    'base_restauracion_almacenes' => 0.0, 'bolson_restauraciones' => 0.0,
                    'base_lista' => 0.0, 'base_pendiente' => 0.0,
                    'se_cobra_el' => Carbon::parse($mes.'-01')->addMonth()->day(20)->toDateString(),
                    'llego_la_fecha' => false, 'porcentaje' => self::PORCENTAJE,
                    'comision_restauraciones' => 0.0, 'comision_restauraciones_lista' => 0.0,
                    'independientes' => [], 'almacenes' => [], 'ordenes' => [],
                    'restauraciones_almacenes' => []];
        }

        $ordenes = self::ordenesDelMes($desde, $hasta)
            ->whereIn('o.vendedor_id', $independientes->pluck('id'))
            ->get()
            ->map(fn ($o) => self::conLaBaseQueDeVerdadComisiona($o));

        // Una restauración no le suma a la meta del almacén aunque se comparta.
        $idsRestauracion = self::idsDeRestauracion($ordenes->pluck('id')->all());

        // Las restauraciones que subió alguien de un almacén. Cuentan como si
        // las hubiera subido Henry compartidas con esa tienda: entran al
        // bolsón por el valor entero. `es_compartida` entre dos asesores de
        // tienda no la parte aquí —eso es cómo se reparten ellos su 5%, no
        // cuánto entra al bolsón—, así que se ignora.
        $deAlmacenes = self::ordenesDelMes($desde, $hasta)
            ->whereNotIn('o.vendedor_id', $independientes->pluck('id'))
            ->whereIn('o.id', function ($q) {
                $q->from('orden_items')->select('orden_id')
                  ->groupBy('orden_id')
                  ->havingRaw('COUNT(*) = SUM(es_restauracion)');
            })
            ->get()
            ->map(function ($o) {
                $o->es_compartida = false;
                return self::conLaBaseQueDeVerdadComisiona($o);
            });

        // Se cobra igual que en las tiendas: cuando el cliente ha pagado la
        // mitad Y llega el 20 del mes siguiente a la venta.
        $hoy       = Carbon::today(StatsController::TZ_NEGOCIO);
        $seCobraEl = Carbon::parse($mes . '-01')->addMonth()->day(20);
        $llegoLaFecha = $hoy->gte($seCobraEl);

        // Contra el valor de la ORDEN, no contra la base que comisiona: esa ya
        // viene sin el datáfono y partida por la mitad si es compartida, así
        // que comparar el abono del cliente con ella daba por cumplida la
        // mitad cuando llevaba pagado bastante menos.
        //
        // Las restauraciones no esperan la mitad: se pagan con solo llegar la
        // fecha, haya pagado el cliente lo que haya pagado.
        $estaLista = fn ($o) => $llegoLaFecha && (
            in_array($o->id, $idsRestauracion, true)
            || (float) $o->pagado >= (float) $o->valor_orden / 2
        );
        $listas            = $ordenes->filter($estaLista);
        // Las de los almacenes son todas restauraciones: solo miran la fecha.
        $deAlmacenesListas = $deAlmacenes->filter(fn ($o) => $llegoLaFecha);

        /** Lo que paga un grupo de órdenes, mezclando venta y restauración — para el almacén, que cobra igual sobre las dos. */
        $pagaPor = function ($grupo) use ($idsRestauracion) {
            $venta = (float) $grupo->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                ->sum('valor_total');
            $rest  = (float) $grupo->filter(fn ($o) => in_array($o->id, $idsRestauracion, true))
                ->sum('valor_total');
            return ($venta / self::IVA + $rest) * self::PORCENTAJE;
        };

        /** Solo la parte de restauración de un grupo — lo que se reparte. */
        $pagaRestauracion = fn ($grupo) => (float) $grupo
            ->filter(fn ($o) => in_array($o->id, $idsRestauracion, true))
            ->sum('valor_total') * self::PORCENTAJE;

        /** Solo la parte de venta de un grupo — de quien la hizo, nadie más. */
        /** A una FV2 marcada no se le quita el IVA: ver `ivaDe()`. */
        $pagaVenta = fn ($grupo) => (float) $grupo
            ->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
            ->sum(fn ($o) => (float) $o->valor_total / self::ivaDe($o)) * self::PORCENTAJE;

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
        // de esa suma completa. Esto sí es igual para todos.
        $comisionRestauraciones      = $pagaRestauracion($ordenes)
            + $baseRestAlmacenes * self::PORCENTAJE;
        $comisionRestauracionesLista = $pagaRestauracion($listas)
            + (float) $deAlmacenesListas->sum('valor_total') * self::PORCENTAJE;

        // La venta es de quien la hizo: cada uno cobra solo sobre sus propias
        // órdenes, sin sumarse con las del otro independiente.
        $porIndependiente = $independientes->map(function ($u) use (
            $ordenes, $listas, $idsRestauracion, $pagaVenta,
            $comisionRestauraciones, $comisionRestauracionesLista
        ) {
            $suyas       = $ordenes->where('vendedor_id', $u->id);
            $suyasListas = $listas->where('vendedor_id', $u->id);
            $venta       = (float) $suyas->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                ->sum('valor_total');

            $comisionVenta      = $pagaVenta($suyas);
            $comisionVentaLista = $pagaVenta($suyasListas);

            $comisionTotal  = $comisionVenta + $comisionRestauraciones;
            $comisionLista  = $comisionVentaLista + $comisionRestauracionesLista;

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
            ];
        })->values()->all();

        // Cada almacén cobra sobre lo que se compartió con él.
        $almacenes = $ordenes->whereNotNull('tienda_abonada_id')
            ->groupBy('tienda_abonada_id')
            ->map(function ($grupo) use ($idsRestauracion, $listas, $pagaPor) {
                $idsListas = $listas->pluck('id')->all();
                $compartido = (float) $grupo->sum('valor_total');
                // A la meta solo le suma la mitad de lo que NO es restauración.
                $paraMeta = (float) $grupo
                    ->reject(fn ($o) => in_array($o->id, $idsRestauracion, true))
                    ->sum('valor_total') / 2;

                return [
                    'tienda_id'   => (int) $grupo->first()->tienda_abonada_id,
                    'nombre'      => $grupo->first()->almacen,
                    'compartido'  => $compartido,
                    'comision'    => round($pagaPor($grupo)),
                    'comision_lista' => round(
                        $pagaPor($grupo->filter(fn ($o) => in_array($o->id, $idsListas, true)))
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
            'bolson_restauraciones'       => $baseRest + $baseRestAlmacenes,
            'base_lista'     => $baseLista,
            'base_pendiente' => $basePendiente,
            'se_cobra_el'    => $seCobraEl->toDateString(),
            'llego_la_fecha' => $llegoLaFecha,
            'porcentaje'     => self::PORCENTAJE,
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
                                    ? (float) $o->valor_total / 2 : 0.0,
                'pagado'         => (float) $o->pagado,
                'pago_completo'  => (float) $o->pagado >= (float) $o->valor_orden / 2,
                'lista'          => $listas->contains('id', $o->id),
                'paga'           => round(in_array($o->id, $idsRestauracion, true)
                                    ? (float) $o->valor_total * self::PORCENTAJE
                                    : (float) $o->valor_total / self::ivaDe($o) * self::PORCENTAJE),
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
                'paga'       => round((float) $o->valor_total * self::PORCENTAJE),
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
    private static function ivaDe(object $o): float
    {
        return ! empty($o->sin_descontar_iva) ? 1.0 : self::IVA;
    }

    /** Las órdenes del mes con lo que hace falta para comisionarlas. */
    private static function ordenesDelMes(string $desde, string $hasta)
    {
        return DB::table('ordenes as o')
            ->join('usuarios as u', 'u.id', '=', 'o.vendedor_id')
            ->leftJoin('tiendas as t', 't.id', '=', 'o.tienda_abonada_id')
            ->leftJoin('tiendas as tv', 'tv.id', '=', 'o.tienda_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->whereBetween('o.created_at', [$desde, $hasta])
            ->whereNotIn('o.estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
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
                    ->whereColumn('orden_id', 'o.id')->whereIn('metodo', Orden::METODOS_CON_FRANQUICIA),
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
        return DB::table('ordenes')
            ->whereNotNull('tienda_abonada_id')
            // Para el pool: una venta cuenta cuando el cliente ya pagó la
            // mitad, igual que las de la propia tienda.
            ->when($soloConLaMitadPagada, fn ($q) => $q->whereRaw(
                '(SELECT COALESCE(SUM(p.monto), 0) FROM pagos p WHERE p.orden_id = ordenes.id) >= ordenes.valor_total / 2'
            ))
            ->whereNotIn('estado', array_merge(['cancelado'], Orden::ESTADOS_NO_COMERCIALES))
            // Fuera las que son íntegramente restauración
            ->whereNotIn('id', function ($q) {
                $q->from('orden_items')->select('orden_id')
                  ->groupBy('orden_id')
                  ->havingRaw('COUNT(*) = SUM(es_restauracion)');
            })
            ->selectRaw(
                "tienda_abonada_id, DATE_FORMAT(CONVERT_TZ(created_at,'+00:00','-05:00'),'%Y-%m') as mes, " .
                // Sin lo que se llevó el datáfono, igual que las ventas propias
                // de la tienda: lo que suman las comisiones ya viene neto, y si
                // esto entrara bruto la meta se movería según quién vendió.
                'SUM(valor_total - ROUND(COALESCE(' .
                '  (SELECT SUM(p.monto) FROM pagos p' .
                "   WHERE p.orden_id = ordenes.id AND p.metodo IN ('tarjeta', 'addi')), 0) * " .
                ComisionController::COSTO_TARJETA . ')) / 2 as total'
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
