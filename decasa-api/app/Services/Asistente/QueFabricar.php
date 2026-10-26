<?php

namespace App\Services\Asistente;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Services\RangoFechas;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "¿Qué producto deberíamos fabricar más?" para el asistente.
 *
 * Antes el asistente lo respondía con `analizar_rotacion_inventario`, que
 * contaba como venta las cotizaciones y los borradores, tomaba el stock físico
 * sin restar lo apartado para clientes, no veía lo que ya se está fabricando
 * para la Reserva y partía los días en UTC (lo vendido después de las 7 p. m.
 * caía en el día siguiente). La recomendación salía inflada justo en lo que
 * más se cotiza.
 *
 * Aquí el código hace todas las cuentas y el modelo solo las explica:
 *  - Lo vendido sale con el mismo criterio de Reportes → Productos: sin
 *    canceladas, cotizaciones ni borradores, en días de Colombia. Cuenta el
 *    modelo de catálogo (también cuando se vendió personalizado: es demanda
 *    del modelo) y deja fuera restauraciones y muebles únicos.
 *  - Lo que hay = lo libre en las tiendas (físico − apartado) + lo libre en la
 *    fábrica + lo que el taller ya está haciendo para la Reserva.
 *  - Cuánto fabricar = lo que se vende en SEMANAS_OBJETIVO semanas menos lo
 *    que hay. Si da cero o menos, no hace falta.
 *  - Tendencia: el período contra el anterior de la misma duración.
 */
class QueFabricar
{
    /** Cuántas semanas de venta se quieren tener cubiertas. */
    public const SEMANAS_OBJETIVO = 4;

    /** Producciones para la Reserva que todavía no llegan a la fábrica. */
    private const EN_TALLER = ['pendiente', 'en_proceso', 'retrasado', 'pendiente_despachador'];

    /**
     * @param array{dias?:int, tienda_id?:int|null, categoria?:string|null, con_plata?:bool, con_costos?:bool} $opc
     */
    public static function analizar(array $opc = []): array
    {
        $dias      = max(14, min(365, (int) ($opc['dias'] ?? 90)));
        $tiendaId  = ! empty($opc['tienda_id']) ? (int) $opc['tienda_id'] : null;
        $categoria = trim((string) ($opc['categoria'] ?? '')) ?: null;
        $conPlata  = (bool) ($opc['con_plata'] ?? false);
        $conCostos = (bool) ($opc['con_costos'] ?? false);

        $hoy          = Carbon::now(RangoFechas::TZ_NEGOCIO)->startOfDay();
        $desde        = $hoy->copy()->subDays($dias - 1);
        $desdeAntes   = $desde->copy()->subDays($dias);
        $corteUtc     = self::utc($desde);
        $inicioUtc    = self::utc($desdeAntes);
        $finUtc       = $hoy->copy()->endOfDay()->setTimezone('UTC')->toDateTimeString();
        $semanas      = $dias / 7;

        $ventas   = self::ventas($inicioUtc, $corteUtc, $finUtc, $tiendaId, $categoria);
        $stock    = self::stock($tiendaId, $categoria);
        $enTaller = self::enTaller();
        $costos   = $conCostos ? self::costos() : collect();

        $ids = $ventas->keys()->merge($stock->keys())->merge($enTaller->keys())->unique();
        $productos = DB::table('productos')
            ->whereIn('id', $ids)
            ->where('activo', true)
            ->when($categoria, fn ($q) => $q->whereRaw('LOWER(categoria) LIKE ?', ['%' . mb_strtolower($categoria) . '%']))
            ->get(['id', 'nombre', 'categoria', 'precio_base'])
            ->keyBy('id');

        $filas = $productos->map(function ($p) use ($ventas, $stock, $enTaller, $costos, $semanas, $conPlata) {
            $v        = $ventas->get($p->id);
            $s        = $stock->get($p->id);
            $vendidas = (int) ($v->vendidas ?? 0);
            $antes    = (int) ($v->vendidas_antes ?? 0);
            $libreT   = (int) ($s->libre_tiendas ?? 0);
            $libreF   = (int) ($s->libre_fabrica ?? 0);
            $taller   = (int) ($enTaller->get($p->id) ?? 0);
            $hay      = $libreT + $libreF + $taller;
            $porSem   = round($vendidas / $semanas, 2);
            $cubre    = $porSem > 0 ? round($hay / $porSem, 1) : null;

            $fila = [
                'producto_id'          => (int) $p->id,
                'producto'             => $p->nombre,
                'categoria'            => $p->categoria,
                'vendidas'             => $vendidas,
                'vendidas_periodo_anterior' => $antes,
                'tendencia_pct'        => $antes > 0 ? (int) round(($vendidas - $antes) / $antes * 100) : null,
                'por_semana'           => $porSem,
                'libre_en_tiendas'     => $libreT,
                'libre_en_fabrica'     => $libreF,
                'en_taller_para_stock' => $taller,
                'semanas_cubiertas'    => $cubre,
                'fabricar_sugerido'    => $porSem > 0 ? max(0, (int) ceil($porSem * self::SEMANAS_OBJETIVO - $hay)) : 0,
                'estado'               => self::estado($vendidas, $hay, $cubre),
            ];

            if ($conPlata) {
                $fila['valor_vendido'] = round((float) ($v->valor ?? 0));
            }
            if ($costos->has($p->id) && (float) $p->precio_base > 0) {
                $costo = (float) $costos->get($p->id);
                $fila['costo_fabricacion'] = round($costo);
                $fila['precio_lista']      = round((float) $p->precio_base);
                // Cuántas veces el costo se cobra: se compara con el factor de
                // venta sugerido de Costos → Tarifas (2,0 por defecto).
                $fila['precio_sobre_costo'] = $costo > 0 ? round((float) $p->precio_base / $costo, 2) : null;
            }

            return $fila;
        })->values();

        $fabricar = $filas
            ->filter(fn ($f) => in_array($f['estado'], ['urgente', 'pronto'], true) && $f['fabricar_sugerido'] > 0)
            ->sortBy([['fabricar_sugerido', 'desc'], ['por_semana', 'desc']])
            ->take(15)->values();

        $dondeFalta = self::dondeFalta($fabricar->pluck('producto_id')->all(), $corteUtc, $finUtc, $tiendaId);
        $fabricar = $fabricar->map(fn ($f) => $f + ['tiendas_que_vendieron_y_no_tienen' => $dondeFalta[$f['producto_id']] ?? []]);

        return [
            'periodo'          => ['desde' => $desde->toDateString(), 'hasta' => $hoy->toDateString(), 'dias' => $dias],
            'comparado_con'    => ['desde' => $desdeAntes->toDateString(), 'hasta' => $desde->copy()->subDay()->toDateString()],
            'tienda_id'        => $tiendaId,
            'como_se_calcula'  => 'vendidas = unidades del modelo en ventas reales (sin canceladas, cotizaciones, borradores, '
                . 'restauraciones ni muebles únicos). hay = libre en tiendas (físico − apartado para clientes) + libre en fábrica '
                . '+ lo que el taller hace para la Reserva. fabricar_sugerido = lo que se vende en ' . self::SEMANAS_OBJETIVO
                . ' semanas − lo que hay. urgente: alcanza < 2 semanas; pronto: 2 a 4; exceso: > 12.',
            'resumen'          => [
                'productos_analizados' => $filas->count(),
                'urgente'              => $filas->where('estado', 'urgente')->count(),
                'pronto'               => $filas->where('estado', 'pronto')->count(),
                'exceso'               => $filas->where('estado', 'exceso')->count(),
                'sin_ventas'           => $filas->where('estado', 'sin_ventas')->count(),
                'unidades_vendidas'    => $filas->sum('vendidas'),
                'unidades_periodo_anterior' => $filas->sum('vendidas_periodo_anterior'),
            ],
            'fabricar_primero' => $fabricar,
            'mas_vendidos'     => $filas->where('vendidas', '>', 0)->sortByDesc('vendidas')->take(10)->values(),
            'subiendo'         => $filas->filter(fn ($f) => $f['vendidas'] >= 3 && ($f['tendencia_pct'] ?? 0) >= 30)
                ->sortByDesc('tendencia_pct')->take(8)->values(),
            'bajando'          => $filas->filter(fn ($f) => $f['vendidas_periodo_anterior'] >= 3 && ($f['tendencia_pct'] ?? 0) <= -30)
                ->sortBy('tendencia_pct')->take(8)->values(),
            'sin_salida'       => $filas->where('estado', 'sin_ventas')->sortByDesc(fn ($f) => $f['libre_en_tiendas'] + $f['libre_en_fabrica'])
                ->take(10)->values(),
            'exceso'           => $filas->where('estado', 'exceso')->sortByDesc('semanas_cubiertas')->take(10)->values(),
            'por_categoria'    => self::porCategoria($filas),
            'disenos_especiales_repetidos' => self::disenosRepetidos($corteUtc, $finUtc, $tiendaId),
            'demanda_interesados' => self::demandaInteresados($tiendaId),
        ];
    }

    private static function utc(Carbon $diaBogota): string
    {
        return $diaBogota->copy()->startOfDay()->setTimezone('UTC')->toDateTimeString();
    }

    private static function estado(int $vendidas, int $hay, ?float $cubre): string
    {
        if ($vendidas === 0) {
            return $hay > 0 ? 'sin_ventas' : 'sin_movimiento';
        }
        return match (true) {
            $cubre < 2   => 'urgente',
            $cubre < 4   => 'pronto',
            $cubre <= 12 => 'cubierto',
            default      => 'exceso',
        };
    }

    /** Lo vendido de cada modelo en el período y en el anterior, en una sola lectura. */
    private static function ventas(string $inicio, string $corte, string $fin, ?int $tiendaId, ?string $categoria): Collection
    {
        return self::itemsDeVenta($inicio, $fin, $tiendaId)
            ->when($categoria, fn ($q) => $q->whereRaw('LOWER(p.categoria) LIKE ?', ['%' . mb_strtolower($categoria) . '%']))
            ->selectRaw('oi.producto_id AS id,
                SUM(CASE WHEN o.created_at >= ? THEN oi.cantidad ELSE 0 END) AS vendidas,
                SUM(CASE WHEN o.created_at <  ? THEN oi.cantidad ELSE 0 END) AS vendidas_antes,
                SUM(CASE WHEN o.created_at >= ? THEN oi.cantidad * oi.precio_unitario ELSE 0 END) AS valor', [$corte, $corte, $corte])
            ->groupBy('oi.producto_id')
            ->get()->keyBy('id');
    }

    /** Ítems de ventas reales del modelo (la base de las cuentas). */
    private static function itemsDeVenta(string $desde, string $hasta, ?int $tiendaId)
    {
        return DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', [$desde, $hasta])
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->whereRaw(OrdenItem::CLASE_REPORTE_SQL . " IN ('catalogo', 'personalizado')")
            ->when($tiendaId, fn ($q) => $q->where('o.tienda_id', $tiendaId));
    }

    /** Lo libre (físico − apartado) por producto, aparte tiendas y fábrica. */
    private static function stock(?int $tiendaId, ?string $categoria): Collection
    {
        $libre    = 'CASE WHEN i.cantidad_disponible > i.cantidad_reservada THEN i.cantidad_disponible - i.cantidad_reservada ELSE 0 END';
        $esFab    = Schema::hasColumn('tiendas', 'es_fabrica') ? 'COALESCE(t.es_fabrica, 0) = 1' : '1 = 0';
        // Con una tienda escogida, lo de las demás tiendas no cuenta (es lo que
        // ella tiene para vender); lo de la fábrica sí, porque se le puede mandar.
        $enTiendas = $tiendaId ? "NOT ({$esFab}) AND i.tienda_id = " . (int) $tiendaId : "NOT ({$esFab})";

        return DB::table('inventario as i')
            ->join('tiendas as t', 't.id', '=', 'i.tienda_id')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->where('t.activa', true)
            ->when($categoria, fn ($q) => $q->whereRaw('LOWER(p.categoria) LIKE ?', ['%' . mb_strtolower($categoria) . '%']))
            ->selectRaw("i.producto_id AS id,
                SUM(CASE WHEN {$enTiendas} THEN {$libre} ELSE 0 END) AS libre_tiendas,
                SUM(CASE WHEN {$esFab} THEN {$libre} ELSE 0 END)     AS libre_fabrica")
            ->groupBy('i.producto_id')
            ->get()->keyBy('id');
    }

    /** Unidades que el taller ya está haciendo para la Reserva (no para un cliente). */
    private static function enTaller(): Collection
    {
        if (! Schema::hasColumn('produccion', 'destino')) {
            return collect();
        }

        return DB::table('produccion')
            ->where('destino', 'reserva')
            ->whereIn('estado', self::EN_TALLER)
            ->whereNotNull('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) AS unidades')
            ->groupBy('producto_id')
            ->pluck('unidades', 'producto_id')
            ->map(fn ($u) => (int) $u);
    }

    /** Costo de la ficha técnica vinculada al producto (la más barata si hay varias). */
    private static function costos(): Collection
    {
        if (! Schema::hasTable('fichas_tecnicas') || ! Schema::hasColumn('fichas_tecnicas', 'producto_id')) {
            return collect();
        }

        return DB::table('fichas_tecnicas')
            ->whereNotNull('producto_id')
            ->where('costo_total', '>', 0)
            ->selectRaw('producto_id, MIN(costo_total) AS costo')
            ->groupBy('producto_id')
            ->pluck('costo', 'producto_id');
    }

    /**
     * Para cada producto a fabricar, las tiendas que lo vendieron en el período
     * y hoy no tienen ni una libre: a dónde mandar lo que salga del taller.
     *
     * @return array<int, string[]>
     */
    private static function dondeFalta(array $ids, string $desde, string $hasta, ?int $tiendaId): array
    {
        if (! $ids) {
            return [];
        }

        $vendieron = self::itemsDeVenta($desde, $hasta, $tiendaId)
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->whereIn('oi.producto_id', $ids)
            ->selectRaw('oi.producto_id, o.tienda_id, t.nombre, SUM(oi.cantidad) AS vendidas')
            ->groupBy('oi.producto_id', 'o.tienda_id', 't.nombre')
            ->get();

        $libres = DB::table('inventario')
            ->whereIn('producto_id', $ids)
            ->whereColumn('cantidad_disponible', '>', 'cantidad_reservada')
            ->get(['producto_id', 'tienda_id'])
            ->map(fn ($r) => $r->producto_id . ':' . $r->tienda_id)
            ->flip();

        $faltan = [];
        foreach ($vendieron as $v) {
            if (! $libres->has($v->producto_id . ':' . $v->tienda_id)) {
                $faltan[$v->producto_id][] = $v->nombre . ' (vendió ' . (int) $v->vendidas . ')';
            }
        }
        return $faltan;
    }

    private static function porCategoria(Collection $filas): Collection
    {
        return $filas->groupBy(fn ($f) => $f['categoria'] ?: 'Sin categoría')
            ->map(function ($g, $cat) {
                $antes = $g->sum('vendidas_periodo_anterior');
                $ahora = $g->sum('vendidas');
                return [
                    'categoria'        => $cat,
                    'vendidas'         => $ahora,
                    'periodo_anterior' => $antes,
                    'tendencia_pct'    => $antes > 0 ? (int) round(($ahora - $antes) / $antes * 100) : null,
                    'hay'              => $g->sum(fn ($f) => $f['libre_en_tiendas'] + $f['libre_en_fabrica'] + $f['en_taller_para_stock']),
                    'urgentes'         => $g->where('estado', 'urgente')->count(),
                ];
            })
            ->sortByDesc('vendidas')->values();
    }

    /**
     * Diseños especiales (fuera del catálogo) que se vendieron más de una vez
     * con el mismo nombre: candidatos a volverse producto de catálogo.
     */
    private static function disenosRepetidos(string $desde, string $hasta, ?int $tiendaId): Collection
    {
        return DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', [$desde, $hasta])
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->whereRaw(OrdenItem::CLASE_REPORTE_SQL . " = 'diseno_especial'")
            ->whereNotNull('oi.nombre_custom')
            ->when($tiendaId, fn ($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw('MIN(oi.nombre_custom) AS nombre, COUNT(DISTINCT o.id) AS ordenes, SUM(oi.cantidad) AS unidades')
            ->groupBy(DB::raw('LOWER(TRIM(oi.nombre_custom))'))
            ->havingRaw('COUNT(DISTINCT o.id) >= 2')
            ->orderByDesc('ordenes')
            ->limit(5)
            ->get();
    }

    /**
     * Lo que más preguntan los interesados, por categoría (señal de demanda):
     * los interesados de Clientes y los de Clientes → Redes (lo que pidieron
     * ver a Elena o lo que se anotó a mano).
     */
    public static function demandaInteresados(?int $tiendaId): array
    {
        $conteo = [];
        foreach (['clientes', 'clientes_redes'] as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'categorias_interes')) {
                continue;
            }
            DB::table($tabla)
                ->whereNotNull('categorias_interes')
                ->when($tiendaId && Schema::hasColumn($tabla, 'tienda_id'), fn ($q) => $q->where('tienda_id', $tiendaId))
                ->pluck('categorias_interes')
                ->each(function ($json) use (&$conteo) {
                    foreach ((array) (json_decode((string) $json, true) ?? []) as $cat) {
                        $cat = mb_strtolower(trim((string) $cat));
                        if ($cat !== '') {
                            $conteo[$cat] = ($conteo[$cat] ?? 0) + 1;
                        }
                    }
                });
        }
        arsort($conteo);

        return array_map(fn ($c, $n) => ['categoria' => $c, 'personas' => $n],
            array_keys(array_slice($conteo, 0, 8, true)), array_slice($conteo, 0, 8, true));
    }
}
