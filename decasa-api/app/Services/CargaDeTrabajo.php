<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto trabajo hay y cuánto viene: el tablero de Carga de trabajo.
 *
 * Pedido del dueño (2026-10-28): "pendientes para todo para saber cuánto
 * trabajo tenemos". Junta en una sola mirada lo que hoy está regado entre
 * Órdenes (qué hay por entregar, qué espera precio o anticipo), Producción
 * (qué piezas no han arrancado, en qué paso va cada una) y Mis pasos (cuántas
 * le esperan a cada proceso).
 *
 * Solo LEE. No cambia ningún estado ni ninguna regla: las cuentas salen de las
 * mismas preguntas que ya usan los módulos (`OrdenItem::vaAlTaller`,
 * `estaListoParaEntregar`, `pendienteEntregar`, el paso `en_proceso` como paso
 * actual, `Orden::visiblesPara`), para que el tablero no diga una cosa y la
 * lista otra.
 *
 * Tampoco trae plata: es carga de trabajo, la puede ver la supervisora del
 * taller sin tener acceso a reportes ni comisiones.
 *
 * Las fechas se razonan en hora de Bogotá: "vencida" es antes de hoy allá, no
 * de hoy en UTC (después de las 7 p. m. ya sería mañana).
 */
class CargaDeTrabajo
{
    /** Órdenes que todavía tienen trabajo por delante. */
    public const ESTADOS_ORDEN_ABIERTA = [
        'pendiente_cotizacion', 'pendiente_anticipo', 'en_produccion',
        'listo_entrega', 'en_camino', 'devuelto',
    ];

    /**
     * Piezas que siguen en el taller. `listo` no va: ya salió del taller y lo
     * que falta es entregarla, eso se cuenta del lado de las órdenes.
     */
    public const ESTADOS_PIEZA_EN_TALLER = ['pendiente', 'en_proceso', 'retrasado', 'pendiente_despachador'];

    /** Qué tanto hacia atrás se mira para sacar el ritmo de cada paso. */
    private const DIAS_DE_RITMO = 28;

    /** Y para saber cuánto tarda normalmente un paso. */
    private const DIAS_DE_DURACION = 90;

    /** Semanas que se muestran de lo que entra y sale del taller. */
    private const SEMANAS_DE_FLUJO = 8;

    private string $hoy;

    public function __construct(private Usuario $usuario, private ?int $tiendaId = null)
    {
        $this->hoy = CarbonImmutable::now('America/Bogota')->toDateString();
    }

    public function calcular(): array
    {
        $ordenes = $this->ordenesAbiertas();
        $piezas  = $this->piezasEnTaller();

        return [
            'hoy'        => $this->hoy,
            'generado_at'=> now()->toIso8601String(),
            'ordenes'    => $this->resumenOrdenes($ordenes),
            'produccion' => $this->resumenProduccion($piezas),
        ];
    }

    // ── Órdenes ─────────────────────────────────────────────────────────────

    private function ordenesAbiertas(): Collection
    {
        return Orden::query()
            ->whereIn('estado', self::ESTADOS_ORDEN_ABIERTA)
            ->visiblesPara($this->usuario)
            ->when($this->tiendaId, fn ($q) => $q->where('tienda_id', $this->tiendaId))
            ->with([
                'items.produccion:id,orden_item_id,estado',
                'cliente:id,nombre',
                'tienda:id,nombre',
            ])
            ->get();
    }

    private function resumenOrdenes(Collection $ordenes): array
    {
        $porEstado = array_fill_keys(self::ESTADOS_ORDEN_ABIERTA, 0);

        // Las clases de lo que se fabrica, como se ven en Nueva orden.
        $tipos = [];
        foreach (['fabricar', 'personalizado', 'diseno_especial', 'retapizar', 'restauracion'] as $t) {
            $tipos[$t] = ['ordenes' => 0, 'unidades' => 0];
        }

        $conTaller = 0;
        $conTallerSinArrancar = 0;
        $conAlgoListo = 0;
        $parciales = 0;
        $atrasadas = 0;
        $calendario = $this->cubetasVacias();
        $porTienda = [];
        $paraEntregar = [];
        $sinArrancar = [];

        foreach ($ordenes as $o) {
            $porEstado[$o->estado]++;

            $vivos = $o->items->filter(fn (OrdenItem $i) => $i->pendienteEntregar() > 0);

            // Lo que le falta al cliente y pasa por el taller (se fabrica, se
            // personaliza, se retapiza o se arregla por garantía).
            $deTaller = $vivos->filter(fn (OrdenItem $i) => $i->pasaPorElTaller());
            $tiposDeLaOrden = [];
            foreach ($deTaller as $i) {
                $t = $i->tipo_item;
                if (! isset($tipos[$t])) continue;
                $tipos[$t]['unidades'] += $i->pendienteEntregar();
                $tiposDeLaOrden[$t] = true;
            }
            foreach (array_keys($tiposDeLaOrden) as $t) $tipos[$t]['ordenes']++;

            // Sin arrancar: la pieza está creada pero nadie le ha puesto pasos.
            $piezasSinArrancar = $deTaller->filter(fn (OrdenItem $i) => $i->produccion?->estado === 'pendiente');
            if ($deTaller->isNotEmpty()) $conTaller++;
            if ($piezasSinArrancar->isNotEmpty()) {
                $conTallerSinArrancar++;
                $sinArrancar[] = $this->filaOrden($o, $vivos) + [
                    'piezas_sin_arrancar' => $piezasSinArrancar->count(),
                ];
            }

            // Lo que se puede llevar hoy: ya está hecho o es de la tienda. Una
            // orden que espera precio no sale todavía, aunque traiga stock.
            $listos = in_array($o->estado, Orden::ESTADOS_ENTREGABLES, true)
                ? $vivos->filter(fn (OrdenItem $i) => $i->estaListoParaEntregar())
                : collect();
            if ($listos->isNotEmpty()) {
                $conAlgoListo++;
                $paraEntregar[] = $this->filaOrden($o, $vivos) + [
                    'unidades_listas' => (int) $listos->sum(fn ($i) => $i->pendienteEntregar()),
                    'todo_listo'      => $listos->count() === $vivos->count(),
                ];
            }

            if ($o->items->some(fn ($i) => (int) $i->cantidad_entregada > 0) && $vivos->isNotEmpty()) {
                $parciales++;
            }

            $fecha = $this->fechaDeLaOrden($vivos);
            $cubeta = $this->cubeta($fecha);
            // En camino ya salió: no es trabajo por programar.
            if ($o->estado !== 'en_camino') $calendario[$cubeta]++;

            // Atrasada como en la lista de Órdenes ("Retrasadas"): cualquier
            // producto que falte con la fecha vencida, no solo el último. La
            // devuelta no cuenta: esa espera decisión, no fecha.
            $atrasada = $o->estado !== 'devuelto' && $vivos->contains(
                fn ($i) => ($f = $this->fecha($i->fecha_entrega_prom)) !== null && $f < $this->hoy
            );
            if ($atrasada) $atrasadas++;

            $tid = (int) $o->tienda_id;
            $porTienda[$tid] ??= [
                'tienda_id' => $tid ?: null,
                'nombre'    => $o->tienda?->nombre ?? 'Sin tienda',
                'abiertas' => 0, 'para_entregar' => 0, 'en_produccion' => 0,
                'con_taller' => 0, 'atrasadas' => 0,
            ];
            $porTienda[$tid]['abiertas']++;
            if ($listos->isNotEmpty()) $porTienda[$tid]['para_entregar']++;
            if ($o->estado === 'en_produccion') $porTienda[$tid]['en_produccion']++;
            if ($deTaller->isNotEmpty()) $porTienda[$tid]['con_taller']++;
            if ($atrasada) $porTienda[$tid]['atrasadas']++;
        }

        // Lo más urgente arriba: lo vencido primero, lo que no tiene fecha al final.
        $porFecha = fn ($a, $b) => [$a['fecha'] === null, $a['fecha']] <=> [$b['fecha'] === null, $b['fecha']];
        usort($paraEntregar, $porFecha);
        usort($sinArrancar, $porFecha);

        $tiendas = array_values($porTienda);
        usort($tiendas, fn ($a, $b) => $b['abiertas'] <=> $a['abiertas']);

        return [
            'abiertas'       => $ordenes->count(),
            'por_estado'     => $porEstado,
            'para_entregar'  => [
                'con_algo_listo' => $conAlgoListo,
                'todo_listo'     => count(array_filter($paraEntregar, fn ($f) => $f['todo_listo'])),
                'en_camino'      => $porEstado['en_camino'],
                'parciales'      => $parciales,
                'lista'          => $paraEntregar,
            ],
            'con_taller'     => $conTaller,
            'con_taller_sin_arrancar' => $conTallerSinArrancar,
            'sin_arrancar'   => $sinArrancar,
            'por_tipo'       => $tipos,
            'atrasadas'      => $atrasadas,
            'calendario'     => $calendario,
            'por_tienda'     => $tiendas,
        ];
    }

    /** Lo que se muestra de una orden en las listas del tablero. */
    private function filaOrden(Orden $o, Collection $vivos): array
    {
        $fecha = $this->fechaDeLaOrden($vivos);

        return [
            'id'         => $o->id,
            'referencia' => $o->referencia,
            'estado'     => $o->estado,
            'cliente'    => $o->cliente?->nombre ?? $o->contacto_nombre,
            'tienda'     => $o->tienda?->nombre,
            'fecha'      => $fecha,
            'dias'       => $fecha ? $this->diasEntre($this->hoy, $fecha) : null,
            'unidades'   => (int) $vivos->sum(fn ($i) => $i->pendienteEntregar()),
        ];
    }

    /**
     * La fecha de una orden es la del producto que falte MÁS LEJANO: el
     * cliente recibe todo cuando esté lo último. Es la misma regla de la
     * lista de Órdenes "Por entregar".
     */
    private function fechaDeLaOrden(Collection $vivos): ?string
    {
        return $vivos->map(fn ($i) => $this->fecha($i->fecha_entrega_prom))->filter()->max();
    }

    // ── Producción ──────────────────────────────────────────────────────────

    private function piezasEnTaller(): Collection
    {
        $piezas = Produccion::query()
            ->whereIn('estado', self::ESTADOS_PIEZA_EN_TALLER)
            // Una pieza cuya orden ya se canceló, se entregó o es solo un
            // borrador no es trabajo de nadie. Las de la Reserva no tienen orden.
            ->where(function ($q) {
                $q->whereNull('orden_item_id')
                  ->orWhereHas('ordenItem', fn ($i) => $i->whereNull('devuelto_en')
                      ->whereHas('orden', fn ($o) => $o->whereIn('estado', self::ESTADOS_ORDEN_ABIERTA)));
            })
            // El mismo filtro que la lista de Producción.
            ->when($this->usuario->soloVeSusOrdenes(), fn ($q) => $q
                ->whereHas('ordenItem.orden', fn ($o) => $o->visiblesPara($this->usuario)))
            ->when($this->tiendaId, fn ($q) => $q
                ->whereHas('ordenItem.orden', fn ($o) => $o->where('tienda_id', $this->tiendaId)))
            ->with([
                'ordenItem.producto:id,nombre',
                'ordenItem.orden:id,numero_orden,serie,serie_numero,cotizacion_numero,estado,contacto_nombre,cliente_id,tienda_id',
                'ordenItem.orden.cliente:id,nombre',
                'ordenItem.orden.tienda:id,nombre',
                'producto:id,nombre',
                'variante:id,marca,marca_tela,nombre_color,medida',
                'pasos',
                'pasos.participantes.usuario:id,nombre',
            ])
            ->get();

        OrdenItem::precargarVariantes($piezas->pluck('ordenItem'));

        return $piezas;
    }

    private function resumenProduccion(Collection $piezas): array
    {
        $tipos = TipoProceso::orderBy('orden')->get(['id', 'clave', 'nombre', 'color', 'orden', 'activo']);

        $lista = $piezas->map(fn (Produccion $p) => $this->filaPieza($p))->values();

        $porEstado = [
            'sin_arrancar' => $lista->where('etapa', 'sin_arrancar'),
            'en_taller'    => $lista->where('etapa', 'en_taller'),
            'en_despacho'  => $lista->where('etapa', 'en_despacho'),
        ];

        $cuenta = fn (Collection $c) => ['piezas' => $c->count(), 'unidades' => (int) $c->sum('cantidad')];

        $calendario = $this->cubetasVacias();
        foreach ($lista as $f) $calendario[$this->cubeta($f['fecha_compromiso'])]++;

        $porTipo = [];
        foreach ($lista as $f) {
            $porTipo[$f['tipo']] ??= ['piezas' => 0, 'unidades' => 0];
            $porTipo[$f['tipo']]['piezas']++;
            $porTipo[$f['tipo']]['unidades'] += $f['cantidad'];
        }

        // Las que llevan más tiempo esperando, arriba; después por fecha.
        $ordenadas = $lista->sort(function ($a, $b) {
            return [$a['fecha_compromiso'] === null, $a['fecha_compromiso'], $a['id']]
               <=> [$b['fecha_compromiso'] === null, $b['fecha_compromiso'], $b['id']];
        })->values();

        return [
            'en_taller_total' => $cuenta($lista),
            'sin_arrancar'    => $cuenta($porEstado['sin_arrancar']),
            'empezadas'       => $cuenta($porEstado['en_taller']->merge($porEstado['en_despacho'])),
            'en_proceso'      => $cuenta($porEstado['en_taller']),
            'en_despacho'     => $cuenta($porEstado['en_despacho']),
            'vencidas'        => $calendario['vencidas'],
            'sin_responsable' => $lista->filter(fn ($f) => $f['paso_actual'] && ! $f['paso_actual']['responsables']
                                    && $f['paso_actual']['clave'] !== ProduccionPaso::DESPACHO)->count(),
            'por_tipo'        => $porTipo,
            'calendario'      => $calendario,
            'pasos'           => $this->resumenPasos($piezas, $tipos),
            'flujo'           => $this->flujoSemanal(),
            'piezas'          => $ordenadas,
        ];
    }

    /** Una pieza en el tablero: qué es, de quién y en qué va. */
    private function filaPieza(Produccion $p): array
    {
        $item  = $p->ordenItem;
        $orden = $item?->orden;
        $pasos = $p->pasos->reject(fn ($x) => $x->estado === 'cancelado')->values();
        $actual = $pasos->firstWhere('estado', 'en_proceso');

        $etapa = match (true) {
            $p->estado === 'pendiente' || ! $actual         => 'sin_arrancar',
            $actual->tipo_proceso === ProduccionPaso::DESPACHO => 'en_despacho',
            default                                         => 'en_taller',
        };

        $fechaCompromiso = $this->fecha($p->fecha_compromiso) ?? $this->fecha($item?->fecha_entrega_prom);
        $fechaInicio     = $this->fecha($p->fecha_inicio);

        return [
            'id'          => $p->id,
            'producto'    => $p->productoNombre() ?? 'Producto',
            'detalle'     => $item?->variante_texto ?? $this->detalleReserva($p),
            'cantidad'    => $this->unidades($p),
            'tipo'        => $this->tipoPieza($p),
            'estado'      => $p->estado,
            'etapa'       => $etapa,
            'orden'       => $orden ? [
                'id'         => $orden->id,
                'referencia' => $orden->referencia,
                'cliente'    => $orden->cliente?->nombre ?? $orden->contacto_nombre,
                'tienda'     => $orden->tienda?->nombre,
            ] : null,
            'fecha_compromiso' => $fechaCompromiso,
            'dias_restantes'   => $fechaCompromiso ? $this->diasEntre($this->hoy, $fechaCompromiso) : null,
            // Cuánto lleva esperando a que alguien la arranque.
            'dias_esperando'   => $etapa === 'sin_arrancar' && $fechaInicio ? max(0, $this->diasEntre($fechaInicio, $this->hoy)) : null,
            'paso_actual' => $actual ? [
                'clave'        => $actual->tipo_proceso,
                'nombre'       => TipoProceso::nombreDe($actual->tipo_proceso),
                'dias'         => $actual->iniciado_at ? round($actual->iniciado_at->diffInHours(now()) / 24, 1) : null,
                'responsables' => $actual->nombresParticipantes(),
            ] : null,
            'pasos' => $pasos->map(fn ($x) => [
                'clave'  => $x->tipo_proceso,
                'estado' => $x->estado,
            ])->all(),
            'hechos' => $pasos->where('estado', 'completado')->count(),
        ];
    }

    /**
     * Cuántas unidades lleva la pieza. `produccion.cantidad` solo se llena en
     * lo de la Reserva; en una venta se queda en 1 aunque se vendieran tres
     * sillas, así que ahí manda el ítem de la orden.
     */
    private function unidades(Produccion $p): int
    {
        return max(1, (int) ($p->ordenItem ? $p->ordenItem->cantidad : ($p->cantidad ?? 1)));
    }

    private function detalleReserva(Produccion $p): ?string
    {
        $v = $p->variante;
        if (! $v) return $p->variante_detalle ?: null;
        $txt = implode(' · ', array_filter([$v->marca_tela ?? $v->marca, $v->nombre_color, $v->medida]));
        return $txt !== '' ? $txt : null;
    }

    /**
     * De qué es la pieza, en las palabras del taller. Una garantía va aparte
     * aunque el mueble sea de catálogo: es un arreglo, no una venta nueva.
     */
    private function tipoPieza(Produccion $p): string
    {
        if ($p->esReserva()) return 'reserva';

        $arreglo = $p->pasos->contains(fn ($x) => $x->garantia_id && in_array($x->estado, ['pendiente', 'en_proceso'], true));
        if ($arreglo) return 'garantia';

        $t = $p->ordenItem?->tipo_item ?? 'catalogo';
        if ($t === 'catalogo' && (int) $p->ordenItem?->cantidad_en_garantia > 0) return 'garantia';

        return $t;
    }

    /**
     * Cada proceso del taller: cuántas piezas tiene encima ahora, cuántas le
     * llegan (porque ya lo tienen puesto en su flujo), a qué ritmo saca y
     * cuánto le tomaría ponerse al día.
     */
    private function resumenPasos(Collection $piezas, Collection $tipos): array
    {
        $pasos = [];
        $fila = function (string $clave) use (&$pasos, $tipos) {
            if (isset($pasos[$clave])) return;
            $t = $tipos->firstWhere('clave', $clave);
            $pasos[$clave] = [
                'clave'  => $clave,
                'nombre' => $t?->nombre ?? TipoProceso::nombreDe($clave),
                'color'  => $t?->color,
                'orden'  => $t?->orden ?? 999,
                'activo' => (bool) ($t?->activo ?? true),
                'en_proceso'      => 0, 'unidades_en_proceso' => 0,
                'siguiente'       => 0, // es el próximo paso de la pieza
                'en_cola'         => 0, 'unidades_en_cola' => 0,
                'sin_responsable' => 0,
                'dias_promedio_actual' => null,
                'dias_max_actual'      => null,
                '_edades'         => [],
            ];
        };

        foreach ($tipos as $t) $fila($t->clave);

        foreach ($piezas as $p) {
            if ($p->estado === 'pendiente') continue;
            $cant  = $this->unidades($p);
            $vivos = $p->pasos->whereIn('estado', ['pendiente', 'en_proceso'])->sortBy('orden')->values();
            $actual = $vivos->firstWhere('estado', 'en_proceso');

            if ($actual) {
                $fila($actual->tipo_proceso);
                $c = &$pasos[$actual->tipo_proceso];
                $c['en_proceso']++;
                $c['unidades_en_proceso'] += $cant;
                if (! $actual->nombresParticipantes() && $actual->tipo_proceso !== ProduccionPaso::DESPACHO) $c['sin_responsable']++;
                if ($actual->iniciado_at) $c['_edades'][] = $actual->iniciado_at->diffInHours(now()) / 24;
                unset($c);
            }

            $primeroEnCola = true;
            foreach ($vivos->where('estado', 'pendiente') as $x) {
                $fila($x->tipo_proceso);
                $pasos[$x->tipo_proceso]['en_cola']++;
                $pasos[$x->tipo_proceso]['unidades_en_cola'] += $cant;
                if ($primeroEnCola) $pasos[$x->tipo_proceso]['siguiente']++;
                $primeroEnCola = false;
            }
        }

        [$hechos, $duracion] = $this->historiaDePasos();
        $personas = $this->personasPorProceso($tipos);

        foreach ($pasos as $clave => &$c) {
            $edades = $c['_edades'];
            unset($c['_edades']);
            if ($edades) {
                $c['dias_promedio_actual'] = round(array_sum($edades) / count($edades), 1);
                $c['dias_max_actual']      = round(max($edades), 1);
            }

            $n = $hechos[$clave] ?? 0;
            $c['hechos_recientes'] = $n;
            $c['ritmo_semana']     = round($n / self::DIAS_DE_RITMO * 7, 1);
            $c['dias_normales']    = $duracion[$clave] ?? null;
            $c['personas']         = $personas[$clave] ?? 0;

            // Con el ritmo de las últimas semanas, cuántos días de trabajo hay
            // encima (lo de ahora más lo que ya le llega). Sin ritmo no se
            // puede estimar: es mejor no decir nada que inventar.
            $carga = $c['en_proceso'] + $c['en_cola'];
            $c['dias_para_ponerse_al_dia'] = $n > 0 && $carga > 0
                ? round($carga / ($n / self::DIAS_DE_RITMO), 1)
                : null;
        }
        unset($c);

        // Sin trabajo, sin historia y apagado no aporta nada: se esconde.
        $pasos = array_filter($pasos, fn ($c) => $c['activo'] || $c['en_proceso'] || $c['en_cola']);

        uasort($pasos, fn ($a, $b) => [$a['clave'] === ProduccionPaso::DESPACHO, $a['orden']]
                                  <=> [$b['clave'] === ProduccionPaso::DESPACHO, $b['orden']]);

        return array_values($pasos);
    }

    /**
     * Lo que cada proceso cerró en las últimas semanas (su ritmo) y cuánto
     * tarda normalmente un paso (la mediana: un paso olvidado abierto un mes
     * no debe volver "lento" a todo el proceso).
     */
    private function historiaDePasos(): array
    {
        $desde = now()->subDays(self::DIAS_DE_DURACION);
        $ritmoDesde = now()->subDays(self::DIAS_DE_RITMO);

        $filas = ProduccionPaso::query()
            ->where('estado', 'completado')
            ->where('completado_at', '>=', $desde)
            ->get(['tipo_proceso', 'iniciado_at', 'completado_at']);

        $hechos = [];
        $tiempos = [];
        foreach ($filas as $f) {
            if ($f->completado_at >= $ritmoDesde) {
                $hechos[$f->tipo_proceso] = ($hechos[$f->tipo_proceso] ?? 0) + 1;
            }
            if ($f->iniciado_at && $f->completado_at > $f->iniciado_at) {
                $tiempos[$f->tipo_proceso][] = $f->iniciado_at->diffInHours($f->completado_at) / 24;
            }
        }

        $duracion = [];
        foreach ($tiempos as $clave => $lista) {
            sort($lista);
            $n = count($lista);
            $mediana = $n % 2 ? $lista[intdiv($n, 2)] : ($lista[$n / 2 - 1] + $lista[$n / 2]) / 2;
            $duracion[$clave] = round($mediana, 1);
        }

        return [$hechos, $duracion];
    }

    /** Cuántas personas activas tienen asignado cada proceso. */
    private function personasPorProceso(Collection $tipos): array
    {
        if (! Schema::hasTable('proceso_trabajadores')) return [];

        $porId = DB::table('proceso_trabajadores as pt')
            ->join('usuarios as u', 'u.id', '=', 'pt.usuario_id')
            ->where('u.activo', true)
            ->groupBy('pt.tipo_proceso_id')
            ->selectRaw('pt.tipo_proceso_id, COUNT(DISTINCT pt.usuario_id) AS n')
            ->pluck('n', 'tipo_proceso_id');

        $res = [];
        foreach ($tipos as $t) $res[$t->clave] = (int) ($porId[$t->id] ?? 0);
        return $res;
    }

    /**
     * Lo que entró al taller y lo que salió, semana por semana. Si entra más
     * de lo que sale, el trabajo se está acumulando.
     */
    private function flujoSemanal(): array
    {
        $lunes = CarbonImmutable::parse($this->hoy)->startOfWeek();
        $desde = $lunes->subWeeks(self::SEMANAS_DE_FLUJO - 1)->toDateString();

        $base = fn () => Produccion::query()
            ->when($this->tiendaId, fn ($q) => $q
                ->whereHas('ordenItem.orden', fn ($o) => $o->where('tienda_id', $this->tiendaId)))
            ->when($this->usuario->soloVeSusOrdenes(), fn ($q) => $q
                ->whereHas('ordenItem.orden', fn ($o) => $o->visiblesPara($this->usuario)));

        $entraron = $base()->whereNotNull('fecha_inicio')->where('fecha_inicio', '>=', $desde)
            ->where('estado', '!=', 'cancelado')->pluck('fecha_inicio');
        $salieron = $base()->whereNotNull('fecha_real')->where('fecha_real', '>=', $desde)
            ->whereIn('estado', ['listo', 'entregado', 'en_reserva'])->pluck('fecha_real');

        $semanas = [];
        for ($i = 0; $i < self::SEMANAS_DE_FLUJO; $i++) {
            $ini = $lunes->subWeeks(self::SEMANAS_DE_FLUJO - 1 - $i);
            $semanas[$ini->toDateString()] = ['desde' => $ini->toDateString(), 'entraron' => 0, 'salieron' => 0];
        }

        $semanaDe = fn ($f) => CarbonImmutable::parse($this->fecha($f))->startOfWeek()->toDateString();
        foreach ($entraron as $f) if (isset($semanas[$k = $semanaDe($f)])) $semanas[$k]['entraron']++;
        foreach ($salieron as $f) if (isset($semanas[$k = $semanaDe($f)])) $semanas[$k]['salieron']++;

        return array_values($semanas);
    }

    // ── Fechas ──────────────────────────────────────────────────────────────

    private function cubetasVacias(): array
    {
        return ['vencidas' => 0, 'esta_semana' => 0, 'proxima_semana' => 0, 'este_mes' => 0, 'mas_adelante' => 0, 'sin_fecha' => 0];
    }

    /** En qué tramo cae una fecha de entrega, contando desde hoy. */
    private function cubeta(?string $fecha): string
    {
        if ($fecha === null) return 'sin_fecha';
        $d = $this->diasEntre($this->hoy, $fecha);

        return match (true) {
            $d < 0   => 'vencidas',
            $d <= 6  => 'esta_semana',
            $d <= 13 => 'proxima_semana',
            $d <= 30 => 'este_mes',
            default  => 'mas_adelante',
        };
    }

    private function fecha($v): ?string
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
        return substr((string) $v, 0, 10);
    }

    /** Días de calendario de $desde a $hasta (negativo si $hasta ya pasó). */
    private function diasEntre(string $desde, string $hasta): int
    {
        $a = new \DateTimeImmutable($desde, new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($hasta, new \DateTimeZone('UTC'));
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }
}
