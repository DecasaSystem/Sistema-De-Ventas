<?php

namespace App\Http\Controllers;

use App\Models\Comision;
use App\Models\MetaTienda;
use App\Models\Orden;
use App\Models\Tienda;
use App\Models\TiendaAsesor;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComisionController extends Controller
{
    /** Lo que se paga cuando no hay pool de por medio. */
    public const PORCENTAJE_DIRECTO = 0.05;

    /** El IVA se descuenta antes de sacar el % de una venta, no de una restauración. */
    public const IVA = 1.19;

    /**
     * Lo que se queda la franquicia de cada peso cobrado con datáfono.
     *
     * Baja la base de la comisión, no el valor de la orden: el cliente sigue
     * debiendo lo mismo, es la empresa la que recibe menos.
     */
    public const COSTO_TARJETA = 0.055;

    /**
     * Estados en los que una orden no es una venta y no debe comisionar:
     * los que nunca lo fueron, más la que se canceló después.
     */
    public const ESTADOS_SIN_VENTA = ['cancelado', ...Orden::ESTADOS_NO_COMERCIALES];

    // GET /api/comisiones
    public function index(Request $request)
    {
        $usuario    = $request->user();
        $vendedorId = $request->query('vendedor_id');
        $mes        = $request->query('mes');
        $estado     = $request->query('estado');

        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $query = Comision::with(['orden.pagos', 'vendedor:id,nombre', 'tienda:id,nombre', 'pagadaPor:id,nombre'])
            ->orderBy('fecha_disponible', 'asc');

        if ($vendedorId) $query->where('vendedor_id', $vendedorId);
        if ($mes)        $query->where('mes_venta', $mes);
        if ($estado)     $query->where('estado', $estado);

        $comisiones = $query->get();

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $poolsTrimestrales = $this->cargarPoolsTrimestrales($metas, $totalesTienda);
        $hoy = Carbon::today();

        $result = $comisiones->map(fn($c) => $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, $hoy));

        return response()->json($result);
    }

    // GET /api/comisiones/vendedores
    public function vendedores(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        // Del mes que se está viendo, no de todos. El selector decía "6
        // comisiones" mientras la pantalla, filtrada a un mes, mostraba 2.
        $mes = $request->query('mes');

        // Y con el estado calculado, no el guardado. El guardado solo se pone
        // al día cuando alguien pulsa Recalcular, así que al llegar el 20 el
        // selector decía "0 listas" con plata lista para pagar en la lista de
        // al lado. Quien paga se guía por ese contador.
        $comisiones = Comision::with(['orden.pagos', 'vendedor:id,nombre', 'tienda:id,nombre'])
            ->when($mes, fn ($q) => $q->where('mes_venta', $mes))
            ->get();

        if ($comisiones->isEmpty()) {
            return response()->json([]);
        }

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $pools = $this->cargarPoolsTrimestrales($metas, $totalesTienda);
        $hoy   = Carbon::today();

        $vendedores = $comisiones
            ->map(fn ($c) => $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $pools, $hoy))
            ->groupBy('vendedor_id')
            ->map(fn ($items) => [
                'id'        => (int) $items->first()['vendedor_id'],
                'nombre'    => $items->first()['vendedor_nombre'],
                'tienda'    => $items->first()['tienda_nombre'],
                'pendiente' => $items->where('estado_calculado', 'pendiente')->count(),
                'lista'     => $items->where('estado_calculado', 'lista')->count(),
                'pagada'    => $items->where('estado_calculado', 'pagada')->count(),
            ])
            ->sortBy('nombre')
            ->values();

        return response()->json($vendedores);
    }

    // POST /api/comisiones/{id}/pagar
    public function marcarPagada(Request $request, int $id)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $comision = Comision::with('orden.pagos')->findOrFail($id);

        if ($comision->estado === 'pagada') {
            return response()->json(['error' => 'Ya está pagada.'], 409);
        }

        // Calcular estado real en el momento del pago (no depender del campo guardado en BD)
        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $poolsTrimestrales = $this->cargarPoolsTrimestrales($metas, $totalesTienda);
        $enriquecida = $this->enriquecer($comision, $metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, Carbon::today());

        if ($enriquecida['estado_calculado'] !== 'lista') {
            return response()->json(['error' => 'La comisión no está lista para pagar aún.'], 422);
        }

        $comision->update([
            'estado'          => 'pagada',
            'monto_comision'  => $enriquecida['monto_comision'],
            'fecha_pago'      => now(),
            'pagada_por'      => $usuario->id,
        ]);

        // Liquidado el trimestre, se queda quieto.
        $this->cerrarTrimestre($comision, $poolsTrimestrales);

        return response()->json($comision->fresh('pagadaPor:id,nombre'));
    }

    // GET /api/comisiones/metas
    public function getMetas(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes     = $request->query('mes', Carbon::now()->format('Y-m'));
        $tiendas = Tienda::where('activa', true)->get();
        // La meta se arrastra: si nadie la cargo este mes, rige la ultima puesta.
        $metas   = MetaTienda::vigentesEn($mes);

        try {
            $asesoresAsignados = TiendaAsesor::with('vendedor:id,nombre')
                ->where('mes', $mes)
                ->get()
                ->groupBy('tienda_id');
        } catch (\Exception $e) {
            $asesoresAsignados = collect([]);
        }

        return response()->json($tiendas->map(function ($t) use ($metas, $asesoresAsignados, $mes) {
            $asesores = isset($asesoresAsignados[$t->id])
                ? $asesoresAsignados[$t->id]->map(fn($a) => [
                    'id'          => $a->id,
                    'vendedor_id' => $a->vendedor_id,
                    'nombre'      => $a->vendedor?->nombre ?? '—',
                ])->values()
                : collect([]);

            // Divisor = count of assigned asesores if any; otherwise DB value (default 1)
            $divisor = $asesores->isNotEmpty()
                ? $asesores->count()
                : (isset($metas[$t->id]) ? (int) $metas[$t->id]->divisor_asesores : 1);

            return [
                'tienda_id'        => $t->id,
                'nombre'           => $t->nombre,
                'mes'              => $mes,
                'meta'             => isset($metas[$t->id]) ? (float) $metas[$t->id]->meta : null,
                'divisor_asesores' => $divisor,
                'asesores'         => $asesores,
            ];
        }));
    }

    // GET /api/comisiones/resumen?mes=YYYY-MM
    public function resumen(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes = $request->query('mes', Carbon::now()->format('Y-m'));

        // Le abre su renglón a quien no vendió: en una tienda con meta el pool
        // se parte entre todos los integrantes, venda cada uno o no.
        $this->asegurarPartesDePool($mes);

        $comisiones = Comision::with(['orden.pagos', 'vendedor:id,nombre', 'tienda:id,nombre'])
            ->where('mes_venta', $mes)
            ->get();

        if ($comisiones->isEmpty()) {
            return response()->json([]);
        }

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $poolsTrimestrales = $this->cargarPoolsTrimestrales($metas, $totalesTienda);
        $hoy = Carbon::today();

        $enriquecidas = $comisiones->map(fn($c) => $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, $hoy));

        $grouped = $enriquecidas->groupBy('vendedor_id')->map(function ($items) {
            $first = $items->first();
            return [
                'vendedor_id'     => (int) $first['vendedor_id'],
                'vendedor_nombre' => $first['vendedor_nombre'],
                'tienda_id'       => (int) $first['tienda_id'],
                'tienda_nombre'   => $first['tienda_nombre'],
                // Sin contar el renglón de quien no vendió: no es una orden,
                // y decir "1 orden" de alguien que no vendió nada confunde.
                'total_ordenes'   => $items->where('forma_pago', '!=', self::ORIGEN_PARTE_POOL)->count(),
                'total_ventas'    => $items->sum(fn($i) => (float) $i['valor_orden']),
                'comision_total'  => $items->sum(fn($i) => (float) $i['monto_comision']),
                'comision_asesor' => (float) $first['comision_asesor'],
                // De dónde sale cada peso, para poder rehacer la cuenta a mano
                // sin abrir orden por orden.
                'desglose'        => $this->desglosarComision($items),
                // Como va el trimestre: en Pereira y Circunvalar el pool mira
                // los 3 meses, y a mitad de camino los que faltan cuentan como
                // cero vendido contra meta entera.
                'periodicidad'     => $first['periodicidad'] ?? 'mensual',
                'avance_trimestre' => $first['avance_trimestre'] ?? null,
                'pendientes'      => $items->where('estado_calculado', 'pendiente')->count(),
                'listas'          => $items->where('estado_calculado', 'lista')->count(),
                'pagadas'         => $items->where('estado_calculado', 'pagada')->count(),
                'ordenes'         => $items->map(fn($i) => [
                    'id'             => $i['id'],
                    'orden_id'       => $i['orden_id'],
                    'orden_numero'   => $i['orden_numero'],
                    'orden_referencia' => $i['orden_referencia'] ?? null,
                    'es_descuento_especial' => $i['es_descuento_especial'] ?? false,
                    // Como se pago esta orden: por el pool de la meta, el 5%
                    // de una restauracion, o el 5% de una tienda sin meta.
                    'forma_pago'     => $i['forma_pago'] ?? 'pool',
                    'es_restauracion'=> $i['es_restauracion'] ?? false,
                    'valor_orden'    => (float) $i['valor_orden'],
                    'monto_comision' => (float) $i['monto_comision'],
                    'estado'         => $i['estado_calculado'],
                    'fecha_venta'    => $i['fecha_venta'],
                ])->values(),
            ];
        })->keyBy('vendedor_id');

        // Los independientes no van por este camino: no tienen meta ni pool, y
        // sobre todo REPARTEN entre ellos, así que su cifra no sale de sus
        // propias órdenes. Enriquecer los trataba como "vendedor sin meta" y la
        // misma pantalla mostraba dos números distintos para la misma persona:
        // aquí lo suyo, y en el bloque de independientes lo que de verdad cobra.
        $indep = \App\Services\ComisionIndependientes::delMes($mes);
        foreach ($indep['independientes'] as $i) {
            if (! $grouped->has($i['vendedor_id'])) continue;

            $fila  = $grouped[$i['vendedor_id']];
            $suyas = collect($indep['ordenes'])->where('vendedor_id', $i['vendedor_id']);

            // Las órdenes salen del servicio, no de comisiones: ahí una venta
            // compartida con un almacén está partida por la mitad, y a ellos el
            // 5% se les paga sobre el valor entero. Lo de la mitad es para la
            // meta del almacén, no para lo que cobran.
            $fila['es_independiente'] = true;
            $fila['comision_total']   = (float) $i['comision'];
            $fila['total_ventas']     = (float) $i['vendio'];
            $fila['total_ordenes']    = $suyas->count();
            // El desglose por órdenes no cuadra para ellos y no se debe usar:
            // el bolsón de restauraciones se reparte entre los independientes,
            // así que lo que cobran no sale de sus propias filas. Su ficha ya
            // muestra el reparto correcto abajo.
            unset($fila['desglose']);
            $fila['ordenes'] = $suyas->map(fn ($o) => [
                'id'               => $o['id'],
                'orden_id'         => $o['id'],
                'orden_numero'     => null,
                'orden_referencia' => $o['referencia'],
                'es_descuento_especial' => false,
                'forma_pago'       => $o['es_restauracion'] ? 'restauracion_5' : 'sin_meta_5',
                'es_restauracion'  => $o['es_restauracion'],
                'valor_orden'      => $o['valor'],
                'monto_comision'   => $o['paga'],
                'estado'           => $o['lista'] ? 'lista' : 'pendiente',
                'fecha_venta'      => $o['fecha'],
            ])->values();

            // El desglose real: sus ventas son solo suyas, las restauraciones
            // se reparten con el otro independiente. Las dos suman el total,
            // así que las órdenes de la ficha nunca cuadran solas con la
            // restauración — esa parte no es "de esta orden", es del bolsón.
            $fila['de_sus_ventas']         = round($i['comision_ventas_propias']);
            $fila['de_restauraciones_compartidas'] = round($i['comision_restauraciones']);
            $fila['pendientes'] = $suyas->where('lista', false)->count();
            $fila['listas']     = $suyas->where('lista', true)->count();
            $grouped[$i['vendedor_id']] = $fila;
        }

        $grouped = $grouped->sortByDesc('comision_total')->values();

        return response()->json($grouped);
    }

    /**
     * Lo que gana una persona en un mes, explicado.
     *
     * Para el asistente de la página: cuando alguien pregunta "¿cuánto llevo?"
     * o "¿por qué me dio tan poco?", esto le da la cifra real y el porqué.
     * Usa el mismo enriquecer() que la pantalla, así que nunca puede contestar
     * un número distinto al que ve el usuario.
     */
    public function resumenParaAgente(int $vendedorId, string $mes): array
    {
        $comisiones = Comision::with(['orden.pagos', 'vendedor:id,nombre', 'tienda:id,nombre'])
            ->where('vendedor_id', $vendedorId)->where('mes_venta', $mes)->get();

        $vendedor = Usuario::find($vendedorId);

        // Su parte del 5% que dejó un independiente: ya son filas propias,
        // asi que vienen dentro de $comisiones como una mas.

        if ($comisiones->isEmpty()) {
            return [
                'tipo' => 'vendedor', 'mes' => $mes,
                'vendedor'   => $vendedor?->nombre,
                'sin_ventas' => true,
                'comision'   => 0,
                'nota' => "No tiene ninguna venta registrada en {$mes}.",
            ];
        }

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $pools = $this->cargarPoolsTrimestrales($metas, $totalesTienda);
        $hoy   = Carbon::today();

        $filas = $comisiones->map(fn ($c) => $this->enriquecer(
            $c, $metas, $totalesTienda, $totalesVendedor, $pools, $hoy
        ));

        $explica = [
            'restauracion_5' => 'restauración: 5% del valor, aparte del pool y sin depender de la meta',
            'sin_meta_5'     => 'no está en una tienda con meta: valor ÷ 1,19 × 5%, sin dividir con nadie',
            'pool'           => 'por el pool de la tienda, a prorrata de lo que vendió ese mes',
            'abono_almacen'  => 'su parte del 5% que dejó un independiente al compartir una venta con su tienda',
        ];
        $primera = $filas->first();

        return [
            'tipo'      => 'vendedor',
            'mes'       => $mes,
            'vendedor'  => $vendedor?->nombre,
            'tienda'    => $primera['tienda_nombre'] ?? null,
            'periodicidad' => $primera['periodicidad'] ?? 'mensual',
            'comision_total'  => $filas->sum('monto_comision'),
            'de_sus_ventas'   => $filas->reject(fn ($f) => $f['abono_de_almacen'])->sum('monto_comision'),
            'del_5_por_ciento_de_almacen' => $filas->where('abono_de_almacen', true)->sum('monto_comision'),
            'ya_puede_cobrar' => $filas->where('estado_calculado', 'lista')->sum('monto_comision'),
            'ya_pagada'       => $filas->where('estado_calculado', 'pagada')->sum('monto_comision'),
            'todavia_no'      => $filas->where('estado_calculado', 'pendiente')->sum('monto_comision'),
            'vendio'          => $filas->sum('valor_orden'),
            'meta_de_su_tienda'   => $primera['meta_tienda'] ?? 0,
            'lleva_la_tienda'     => $primera['total_tienda_mes'] ?? 0,
            'asesores_que_reparten' => $primera['divisor_asesores'] ?? 1,
            'ordenes' => $filas->map(fn ($f) => [
                'orden'          => $f['orden_referencia'] ?? ('#' . $f['orden_numero']),
                'valor'          => $f['valor_orden'],
                'es_restauracion' => $f['es_restauracion'],
                'comision'       => $f['monto_comision'],
                'como_se_paga'   => $explica[$f['forma_pago']] ?? $f['forma_pago'],
                'estado'         => $f['estado_calculado'],
                'se_paga_el'     => $f['fecha_disponible'],
                'cliente_ya_pago_la_mitad' => $f['req_50_pct'],
                'pct_pagado_por_el_cliente' => $f['pct_pagado'],
            ])->values()->all(),
            'para_cobrar_hacen_falta_dos_cosas' =>
                'que llegue la fecha de pago (el 20 del mes siguiente; en Pereira y Circunvalar '
                . 'el 20 del mes siguiente al cierre del trimestre) y que el cliente haya pagado '
                . 'al menos el 50% de la orden.',
        ];
    }

    /** Cómo va la meta de una tienda, para el asistente de la página. */
    public function avanceMetaParaAgente(int $tiendaId, string $mes): array
    {
        [$metas, $totalesTienda, ] = $this->cargarTotales();

        $clave  = $tiendaId . '_' . $mes;
        $meta   = isset($metas[$clave]) ? (float) $metas[$clave]->meta : 0.0;
        $ventas = isset($totalesTienda[$clave]) ? (float) $totalesTienda[$clave]->total : 0.0;
        $tienda = Tienda::find($tiendaId);

        if ($meta <= 0) {
            return [
                'tienda' => $tienda?->nombre, 'mes' => $mes,
                'tiene_meta' => false,
                'vendio' => $ventas,
                'nota' => 'Esta tienda no tiene meta, así que no hay pool: cada quien cobra el 5% de lo suyo.',
            ];
        }

        $pool = max(0, ($ventas - $meta) / self::IVA * self::PORCENTAJE_DIRECTO);
        $div  = isset($metas[$clave]) ? max(1, (int) $metas[$clave]->divisor_asesores) : 1;

        return [
            'tienda' => $tienda?->nombre,
            'mes'    => $mes,
            'tiene_meta'   => true,
            'periodicidad' => self::esTiendaTrimestral($tienda?->nombre) ? 'trimestral' : 'mensual',
            'meta'   => $meta,
            'vendio' => $ventas,
            'ya_alcanzo_la_meta' => $ventas >= $meta,
            'le_falta_vender'    => max(0, $meta - $ventas),
            'pool_del_mes'       => round($pool),
            'asesores_que_reparten' => $div,
            'a_cada_asesor'      => round($pool / $div),
            'nota' => 'El pool es (lo vendido − la meta) ÷ 1,19 × 5%, repartido entre los asesores '
                    . 'y prorrateado según lo que vendió cada uno. Las restauraciones no entran aquí.',
        ];
    }

    // GET /api/comisiones/asesores-asignados?mes=YYYY-MM
    public function getAsesoresAsignados(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes = $request->query('mes', Carbon::now()->format('Y-m'));

        try {
            // Se arrastra la última lista puesta: el equipo casi nunca cambia y
            // nadie lo vuelve a registrar cada mes. Antes se filtraba por el
            // mes exacto y en agosto salían todas las tiendas sin gente.
            $asignados = collect(TiendaAsesor::vigentesEn($mes))
                ->flatten(1)
                ->map(fn ($a) => [
                    'id'              => $a->id,
                    'tienda_id'       => $a->tienda_id,
                    'tienda_nombre'   => $a->tienda?->nombre,
                    'vendedor_id'     => $a->vendedor_id,
                    'vendedor_nombre' => $a->vendedor?->nombre,
                    // Viene de un mes anterior. Al tocar el equipo se copia a
                    // este mes primero, para no reescribir uno ya pagado.
                    'heredado_de'     => $a->mes === $mes ? null : $a->mes,
                ])
                ->values();
        } catch (\Exception $e) {
            $asignados = collect([]);
        }

        return response()->json($asignados);
    }

    // POST /api/comisiones/asesores-asignados
    public function addAsesor(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $data = $request->validate([
            'tienda_id'   => 'required|integer|exists:tiendas,id',
            'mes'         => 'required|string|regex:/^\d{4}-\d{2}$/',
            'vendedor_id' => 'required|integer|exists:usuarios,id',
        ]);

        // Si el equipo de este mes venía arrastrado de uno anterior, se copia
        // primero: agregar a alguien no puede terminar cambiando la lista de un
        // mes que ya se pagó.
        TiendaAsesor::materializar($data['tienda_id'], $data['mes']);

        $asesor = TiendaAsesor::firstOrCreate([
            'tienda_id'   => $data['tienda_id'],
            'mes'         => $data['mes'],
            'vendedor_id' => $data['vendedor_id'],
        ]);

        $count = $this->sincronizarDivisor($data['tienda_id'], $data['mes']);

        $asesor->load('vendedor:id,nombre');

        return response()->json([
            'id'              => $asesor->id,
            'tienda_id'       => $asesor->tienda_id,
            'vendedor_id'     => $asesor->vendedor_id,
            'vendedor_nombre' => $asesor->vendedor?->nombre,
            'divisor'         => $count,
        ], 201);
    }

    // DELETE /api/comisiones/asesores-asignados/{id}
    public function removeAsesor(Request $request, int $id)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $asesor   = TiendaAsesor::findOrFail($id);
        $tiendaId = $asesor->tienda_id;
        // El mes que se está viendo en pantalla, que puede no ser el de la
        // fila: si la lista viene arrastrada de un mes anterior, la fila que
        // se toca es la vieja. Quitarla ahí cambiaría un mes ya pagado.
        $mes = $request->query('mes', $asesor->mes);

        if ($mes !== $asesor->mes) {
            TiendaAsesor::materializar($tiendaId, $mes);
            TiendaAsesor::where('tienda_id', $tiendaId)
                ->where('mes', $mes)
                ->where('vendedor_id', $asesor->vendedor_id)
                ->delete();
        } else {
            $asesor->delete();
        }

        return response()->json(['divisor' => $this->sincronizarDivisor($tiendaId, $mes)]);
    }

    /**
     * El divisor es cuánta gente hay en el equipo ese mes.
     *
     * Se guarda en la meta del mes, y si esa meta todavía no existe se crea
     * copiando la que rige por arrastre: sin fila propia el divisor se quedaba
     * en el del mes anterior y cambiar el equipo no movía el reparto.
     */
    /**
     * Le abre su renglón a quien no vendió nada en el mes.
     *
     * El pool de una tienda con meta se reparte entre todos los integrantes,
     * venda cada uno o no —en Norte siempre se parte en 3—. Quien no vendió no
     * tenía ninguna fila, así que su parte se calculaba pero no se la llevaba
     * nadie: no había dónde escribirla ni dónde marcarla como pagada.
     *
     * Se llama al abrir la pantalla y es idempotente: si ya tiene renglón —sea
     * por ventas suyas o por una parte creada antes— no hace nada.
     *
     * Solo para tiendas con meta: donde no hay pool, cada uno cobra el 5% de lo
     * suyo y sin ventas no hay nada que cobrar.
     */
    /**
     * De dónde sale cada peso de lo que se le va a pagar a alguien.
     *
     * La pantalla mostraba un solo número y para comprobarlo había que abrir
     * las órdenes una por una y sumar de cabeza. Acá quedan separadas las
     * cuatro formas en que se cobra —el pool, la restauración, lo que dejó un
     * independiente y el 5% suelto— más lo que entró por datáfono, que es lo
     * que explica por qué la base no es igual al valor de las ventas.
     *
     * @param  \Illuminate\Support\Collection  $items  Comisiones ya enriquecidas.
     */
    private function desglosarComision($items): array
    {
        $porForma = fn (string $forma) => $items->where('forma_pago', $forma);
        $suma     = fn ($col, string $campo) => round($col->sum(fn ($i) => (float) ($i[$campo] ?? 0)));

        $pool        = $porForma('pool');
        $partePool   = $porForma(self::ORIGEN_PARTE_POOL);
        $restaura    = $items->where('es_restauracion', true)->where('forma_pago', '!=', self::ORIGEN_ABONO);
        $abonos      = $porForma(self::ORIGEN_ABONO);
        $sinMeta     = $porForma('sin_meta_5');

        $tarjeta = $suma($items, 'pagado_tarjeta');

        return [
            // Lo que se le paga, partido por el camino que lo genera.
            'pool'            => ['ordenes' => $pool->count(),      'base' => $suma($pool, 'valor_orden'),     'comision' => $suma($pool, 'monto_comision')],
            'parte_equipo'    => ['ordenes' => $partePool->count(), 'base' => 0,                               'comision' => $suma($partePool, 'monto_comision')],
            'restauraciones'  => ['ordenes' => $restaura->count(),  'base' => $suma($restaura, 'valor_orden'), 'comision' => $suma($restaura, 'monto_comision')],
            'de_independiente'=> ['ordenes' => $abonos->count(),    'base' => $suma($abonos, 'valor_orden'),   'comision' => $suma($abonos, 'monto_comision')],
            'individual'      => ['ordenes' => $sinMeta->count(),   'base' => $suma($sinMeta, 'valor_orden'),  'comision' => $suma($sinMeta, 'monto_comision')],

            // Cómo se cobró: lo que pasó por datáfono no comisiona completo,
            // y esto es lo que explica la diferencia contra el valor vendido.
            'pagado_tarjeta'  => $tarjeta,
            'costo_datafono'  => $suma($items, 'costo_datafono'),
            'sin_tarjeta'     => max(0, $suma($items, 'valor_orden') - $tarjeta),
        ];
    }

    private function asegurarPartesDePool(string $mes): void
    {
        try {
            $this->crearPartesDePool($mes);
        } catch (\Throwable $e) {
            // Abrir renglones no puede tumbar la pantalla: si algo falla —la
            // columna todavía sin migrar, por ejemplo— se sigue mostrando lo
            // que sí hay, que es lo que la gente viene a mirar.
            \Log::warning('[DECASA] No se pudieron crear las partes de pool de ' . $mes, ['error' => $e->getMessage()]);
        }
    }

    private function crearPartesDePool(string $mes): void
    {
        $conMeta = MetaTienda::vigentesEn($mes);
        if (! $conMeta) return;

        $equipos = TiendaAsesor::vigentesEn($mes);

        // Quién ya tiene renglón este mes, para no duplicarlo.
        $conRenglon = Comision::where('mes_venta', $mes)
            ->get(['vendedor_id', 'tienda_id'])
            ->map(fn ($c) => $c->vendedor_id . '_' . $c->tienda_id)
            ->flip();

        $finDeMes = Carbon::parse($mes . '-01')->endOfMonth();

        foreach ($equipos as $tiendaId => $integrantes) {
            if (! isset($conMeta[$tiendaId])) continue;

            foreach ($integrantes as $asesor) {
                if ($conRenglon->has($asesor->vendedor_id . '_' . $tiendaId)) continue;

                Comision::create([
                    'orden_id'         => null,
                    'vendedor_id'      => $asesor->vendedor_id,
                    'tienda_id'        => $tiendaId,
                    'origen'           => self::ORIGEN_PARTE_POOL,
                    'mes_venta'        => $mes,
                    'valor_orden'      => 0,
                    'fecha_venta'      => $finDeMes->toDateString(),
                    'fecha_disponible' => self::calcularFechaDisponible($finDeMes, $tiendaId),
                    'estado'           => 'pendiente',
                ]);
            }
        }
    }

    private function sincronizarDivisor(int $tiendaId, string $mes): int
    {
        $divisor = max(1, TiendaAsesor::where('tienda_id', $tiendaId)->where('mes', $mes)->count());

        $meta = MetaTienda::where('tienda_id', $tiendaId)->where('mes', $mes)->first();

        if ($meta) {
            $meta->update(['divisor_asesores' => $divisor]);
        } elseif ($vigente = (MetaTienda::vigentesEn($mes)[$tiendaId] ?? null)) {
            MetaTienda::create([
                'tienda_id'        => $tiendaId,
                'mes'              => $mes,
                'meta'             => $vigente->meta,
                'divisor_asesores' => $divisor,
            ]);
        }

        return $divisor;
    }

    // POST /api/comisiones/metas
    public function setMeta(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $data = $request->validate([
            'tienda_id'        => 'required|integer|exists:tiendas,id',
            'mes'              => 'required|string|regex:/^\d{4}-\d{2}$/',
            'meta'             => 'required|numeric|min:0',
            'divisor_asesores' => 'sometimes|integer|min:1|max:20',
        ]);

        $meta = MetaTienda::updateOrCreate(
            ['tienda_id' => $data['tienda_id'], 'mes' => $data['mes']],
            [
                'meta'             => $data['meta'],
                'divisor_asesores' => $data['divisor_asesores'] ?? 1,
            ]
        );

        return response()->json($meta);
    }

    // POST /api/comisiones/pagar-listas
    public function pagarListas(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $data = $request->validate([
            'vendedor_id' => 'required|integer|exists:usuarios,id',
            'mes'         => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $poolsTrimestrales = $this->cargarPoolsTrimestrales($metas, $totalesTienda);
        $hoy     = Carbon::today();
        $pagadas = 0;

        Comision::with('orden.pagos')
            ->where('vendedor_id', $data['vendedor_id'])
            ->where('mes_venta', $data['mes'])
            ->where('estado', '!=', 'pagada')
            ->get()
            ->each(function ($c) use ($metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, $hoy, $usuario, &$pagadas) {
                $e = $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, $hoy);
                if ($e['estado_calculado'] === 'lista') {
                    $c->update([
                        'estado'         => 'pagada',
                        'monto_comision' => $e['monto_comision'],
                        'fecha_pago'     => now(),
                        'pagada_por'     => $usuario->id,
                    ]);
                    $this->cerrarTrimestre($c, $poolsTrimestrales);
                    $pagadas++;
                }
            });

        return response()->json(['pagadas' => $pagadas]);
    }

    // POST /api/comisiones/recalcular
    public function recalcular(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        // Primero poner al día el valor de las órdenes que cambiaron de precio
        // después de creadas; si no, se recalcula sobre cifras viejas. Va antes
        // de cargarTotales() porque esos totales salen de valor_orden.
        $revaluadas = 0;
        Comision::with('orden')->where('estado', '!=', 'pagada')
            ->get()->pluck('orden')->filter()->unique('id')
            ->each(function ($orden) use (&$revaluadas) {
                $revaluadas += self::sincronizarValorOrden($orden);
            });

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        // La unica que guarda: es la accion que existe para poner al dia.
        $poolsTrimestrales = $this->cargarPoolsTrimestrales($metas, $totalesTienda, true);
        $hoy          = Carbon::today();
        $actualizadas = 0;
        $notificadas  = 0;

        Comision::with('orden.pagos')->where('estado', '!=', 'pagada')
            ->chunk(100, function ($chunk) use ($metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, $hoy, &$actualizadas, &$notificadas) {
                foreach ($chunk as $c) {
                    $enriquecida = $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, $hoy);
                    $nuevoEstado = $enriquecida['estado_calculado'];

                    $cambios = ['monto_comision' => $enriquecida['monto_comision']];

                    if ($nuevoEstado !== $c->estado) {
                        $cambios['estado'] = $nuevoEstado;
                        $actualizadas++;
                    }

                    if ($nuevoEstado === 'lista' && ! $c->notificado_lista) {
                        $cambios['notificado_lista'] = true;
                        $this->notificarComisionLista($c, $enriquecida);
                        $notificadas++;
                    }

                    $c->update($cambios);
                }
            });

        return response()->json([
            'actualizadas' => $actualizadas,
            'notificadas'  => $notificadas,
            'revaluadas'   => $revaluadas,
        ]);
    }

    /**
     * Pone al día el valor sobre el que se comisiona cuando la orden cambió de
     * precio después de creada.
     *
     * `comisiones.valor_orden` es una foto del momento en que se creó la orden.
     * Si luego se corrige un precio, se aplica un descuento o se agrega un ítem,
     * la foto queda vieja y la comisión se calcula sobre una cifra que ya no
     * existe. Pasó con la #4276: nació en $0 por un descuento mal escrito, y
     * aunque el total se corrigió a $1.750.000, la comisión seguía en cero.
     *
     * No se tocan las ya pagadas: eso ya se liquidó y se cerró.
     *
     * @return int cuántas comisiones quedaron actualizadas
     */
    public static function sincronizarValorOrden(Orden $orden): int
    {
        $valor = self::valorComisionable($orden);

        $cambiadas = 0;
        self::sincronizarAbonoAlmacen($orden);

        // El reparto del almacen lleva su propia base (el pedazo de cada uno),
        // asi que no puede recibir el valor de la orden entera.
        foreach (Comision::where('orden_id', $orden->id)
                     ->where('origen', '!=', self::ORIGEN_ABONO)
                     ->where('estado', '!=', 'pagada')->get() as $c) {
            if (abs((float) $c->valor_orden - $valor) < 0.01) continue;
            $c->update(['valor_orden' => $valor]);
            $cambiadas++;
        }

        return $cambiadas;
    }

    // Llamar desde OrdenController al confirmar una orden
    public static function crearParaOrden(Orden $orden): void
    {
        if (! $orden->vendedor_id || ! $orden->tienda_id) return;

        // Una cotización o un borrador todavía no son venta: no generan comisión.
        if (in_array($orden->estado, Orden::ESTADOS_NO_COMERCIALES, true)) return;

        // En hora de Colombia, no en el reloj UTC del servidor: una venta del 31
        // después de las 7 p.m. se registraría en el mes siguiente y la comisión
        // caería en el período equivocado.
        $fechaVenta    = Carbon::parse($orden->created_at)->setTimezone(StatsController::TZ_NEGOCIO);
        $mes           = $fechaVenta->format('Y-m');
        $fechaVentaStr = $fechaVenta->toDateString();

        $esCompartida  = (bool) $orden->es_compartida;
        $covendedorId  = $orden->covendedor_id;

        $valorPrincipal = self::valorComisionable($orden);

        // Registro del vendedor principal
        Comision::firstOrCreate(
            ['orden_id' => $orden->id, 'vendedor_id' => $orden->vendedor_id],
            [
                'tienda_id'        => $orden->tienda_id,
                'mes_venta'        => $mes,
                'valor_orden'      => $valorPrincipal,
                'fecha_venta'      => $fechaVentaStr,
                'fecha_disponible' => self::calcularFechaDisponible($fechaVenta, $orden->tienda_id),
                'estado'           => 'pendiente',
            ]
        );

        self::sincronizarAbonoAlmacen($orden);

        // Registro del co-vendedor si la venta es compartida
        if ($esCompartida && $covendedorId) {
            $covendedor = Usuario::find($covendedorId);
            if ($covendedor && $covendedor->tienda_default_id) {
                Comision::firstOrCreate(
                    ['orden_id' => $orden->id, 'vendedor_id' => $covendedorId],
                    [
                        'tienda_id'        => $covendedor->tienda_default_id,
                        'mes_venta'        => $mes,
                        'valor_orden'      => $valorPrincipal,
                        'fecha_venta'      => $fechaVentaStr,
                        'fecha_disponible' => self::calcularFechaDisponible($fechaVenta, $covendedor->tienda_default_id),
                        'estado'           => 'pendiente',
                    ]
                );
            }
        }
    }

    /** Marca de las comisiones que salen del 5% que deja un independiente. */
    public const ORIGEN_ABONO = 'abono_almacen';

    /**
     * La parte del pool de alguien que no vendió nada ese mes.
     *
     * En una tienda con meta la comisión es de la TIENDA, no de la venta: el
     * pool se parte entre los integrantes y a cada uno le toca lo mismo. Esta
     * fila no cuelga de ninguna orden porque no hay ninguna — es justamente el
     * caso que antes se quedaba sin pagar.
     */
    public const ORIGEN_PARTE_POOL = 'parte_pool';

    /**
     * Le arma (o le pone al día) su fila a cada persona del almacén con el que
     * un independiente compartió la venta.
     *
     * Ese 5% es de la gente de la tienda, no de la tienda. Se divide en partes
     * iguales entre quienes trabajan ahí, y a cada uno se le guarda su pedazo
     * como `valor_orden` para que la cuenta de siempre (5% a la restauración,
     * ÷1,19 antes del 5% a la venta) le dé justo lo suyo.
     *
     * Se vuelve a llamar cuando la orden cambia: si entra o sale gente de la
     * tienda, el reparto se rehace. No toca las ya pagadas — esa plata ya salió.
     */
    public static function sincronizarAbonoAlmacen(Orden $orden): void
    {
        $existentes = Comision::where('orden_id', $orden->id)
            ->where('origen', self::ORIGEN_ABONO)->get();

        $sigueCompartida = $orden->tienda_abonada_id
            && ! in_array($orden->estado, self::ESTADOS_SIN_VENTA, true);

        if (! $sigueCompartida) {
            $existentes->where('estado', '!=', 'pagada')->each->delete();
            return;
        }

        $gente = Usuario::where('tienda_default_id', $orden->tienda_abonada_id)
            ->where('activo', true)
            ->whereIn('rol', ['vendedor', 'supervisor'])
            ->pluck('id');

        if ($gente->isEmpty()) return;

        $fechaVenta = Carbon::parse($orden->created_at)->setTimezone(StatsController::TZ_NEGOCIO);
        // Su pedazo del valor de la orden, ya sin lo que se llevó el datáfono.
        // El 5% se lo saca enriquecer() con la misma regla que a todo lo demás,
        // así que basta con darle la base.
        $porCabeza = round(((float) $orden->valor_total - self::costoDatafono($orden)) / $gente->count());

        // Al que ya no está en la tienda se le quita, salvo que ya se le pagara.
        $existentes->whereNotIn('vendedor_id', $gente->all())
            ->where('estado', '!=', 'pagada')->each->delete();

        foreach ($gente as $vendedorId) {
            $fila = $existentes->firstWhere('vendedor_id', $vendedorId);

            if ($fila?->estado === 'pagada') continue;

            Comision::updateOrCreate(
                ['orden_id' => $orden->id, 'vendedor_id' => $vendedorId],
                [
                    'origen'           => self::ORIGEN_ABONO,
                    'tienda_id'        => $orden->tienda_abonada_id,
                    'mes_venta'        => $fechaVenta->format('Y-m'),
                    'valor_orden'      => $porCabeza,
                    'fecha_venta'      => $fechaVenta->toDateString(),
                    'fecha_disponible' => self::calcularFechaDisponible($fechaVenta, $orden->tienda_abonada_id),
                    'estado'           => $fila->estado ?? 'pendiente',
                ]
            );
        }
    }

    /**
     * Sobre cuánto comisiona el vendedor de esta orden.
     *
     * Se parte por la mitad cuando la venta se comparte, sea con otro vendedor
     * (`es_compartida`) o con el almacén que pasó el contacto
     * (`tienda_abonada_id`): la mitad es suya y la otra mitad del que ayudó.
     *
     * Vive en un solo lugar porque estaba en dos y se separaron: al crear la
     * comisión se partía por las dos razones, pero al sincronizarla solo por
     * `es_compartida`, así que la primera pasada de recalcular le devolvía el
     * valor entero a las compartidas con un almacén y les duplicaba la base.
     */
    public static function valorComisionable(Orden $orden): float
    {
        $base = (float) $orden->valor_total - self::costoDatafono($orden);

        $compartida = $orden->es_compartida || $orden->tienda_abonada_id;

        return $compartida ? round($base / 2) : $base;
    }

    /**
     * Lo que se queda la franquicia de la tarjeta, y sobre lo que nadie comisiona.
     *
     * De cada peso cobrado por datáfono a la empresa le entra el 94,5%: el resto
     * se lo lleva la franquicia. Comisionar sobre el total sería pagarle al
     * vendedor un 5% de plata que nunca llegó a la caja.
     *
     * Se calcula sobre lo REALMENTE pagado con tarjeta, no sobre la orden
     * entera: en un pago mixto —parte efectivo, parte datáfono— solo ese pedazo
     * tiene el costo. Y si el cliente aún no ha pagado nada con tarjeta, no se
     * descuenta nada; el día que lo haga, el cobro vuelve a sincronizar la base.
     */
    public static function costoDatafono(Orden $orden): float
    {
        return round($orden->pagadoConTarjeta() * self::COSTO_TARJETA);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    // Tiendas de Pereira: la comisión se paga trimestral en vez de mensual.
    // (mismo agrupamiento usado en OrdenController::GRUPOS_SECUENCIA)
    private const TIENDAS_TRIMESTRALES = ['Decasa Unicentro Pereira', 'Decasa Circunvalar'];

    // El arrastre de déficit entre trimestres solo cuenta desde este trimestre en adelante
    // (no se recalculan retroactivamente trimestres anteriores ya cerrados/pagados).
    private const TRIMESTRE_BASE = '2026-Q3';

    private static function esTiendaTrimestral(?string $tiendaNombre): bool
    {
        return in_array($tiendaNombre, self::TIENDAS_TRIMESTRALES, true);
    }

    private static function trimestreDeMes(string $mesVenta): string
    {
        [$anio, $mes] = explode('-', $mesVenta);
        $q = intdiv((int) $mes - 1, 3) + 1;
        return $anio . '-Q' . $q;
    }

    private static function trimestreSiguiente(string $trimestre): string
    {
        [$anio, $q] = explode('-Q', $trimestre);
        $anio = (int) $anio; $q = (int) $q;
        return $q === 4 ? ($anio + 1) . '-Q1' : $anio . '-Q' . ($q + 1);
    }

    private static function mesesDeTrimestre(string $trimestre): array
    {
        [$anio, $q] = explode('-Q', $trimestre);
        $anio = (int) $anio; $q = (int) $q;
        $mesInicio = ($q - 1) * 3 + 1;
        return [
            sprintf('%s-%02d', $anio, $mesInicio),
            sprintf('%s-%02d', $anio, $mesInicio + 1),
            sprintf('%s-%02d', $anio, $mesInicio + 2),
        ];
    }

    /**
     * Diferencial de ventas vs meta sumado en los 3 meses del trimestre
     * (puede ser negativo). No incluye arrastre de déficit.
     */
    /**
     * Como va un trimestre que todavia no termina.
     *
     * El pool suma (ventas - meta) de los tres meses, asi que a mitad de
     * trimestre los meses que no han pasado cuentan como cero vendido contra
     * una meta entera y el resultado sale en rojo aunque se vaya bien. Aqui se
     * separan las dos cosas: lo que va acumulado de verdad, y cuanto falta por
     * vender para que el trimestre cierre en positivo.
     */
    private function avanceTrimestre(int $tiendaId, string $trimestre, $metas, $totalesTienda): array
    {
        $mesActual  = Carbon::now(StatsController::TZ_NEGOCIO)->format('Y-m');
        $acumulado  = 0.0;
        $metaFutura = 0.0;
        $cumplidos  = 0;
        $restantes  = 0;

        foreach (self::mesesDeTrimestre($trimestre) as $mes) {
            $key  = $tiendaId . '_' . $mes;
            $meta = isset($metas[$key]) ? (float) $metas[$key]->meta : 0;

            if ($mes <= $mesActual) {
                $ventas = isset($totalesTienda[$key]) ? (float) $totalesTienda[$key]->total : 0;
                $acumulado += ($ventas - $meta);
                $cumplidos++;
            } else {
                $metaFutura += $meta;
                $restantes++;
            }
        }

        return [
            'acumulado'       => round($acumulado),
            'meses_cumplidos' => $cumplidos,
            'meses_restantes' => $restantes,
            // Lo que hay que vender en lo que queda para cerrar en positivo.
            'falta_vender'    => round(max(0, $metaFutura - $acumulado)),
            'en_curso'        => $restantes > 0,
        ];
    }

    private function diferencialTrimestre(int $tiendaId, string $trimestre, $metas, $totalesTienda): float
    {
        $diferencial = 0.0;
        foreach (self::mesesDeTrimestre($trimestre) as $mes) {
            $key    = $tiendaId . '_' . $mes;
            $meta   = isset($metas[$key]) ? (float) $metas[$key]->meta : 0;
            $ventas = isset($totalesTienda[$key]) ? (float) $totalesTienda[$key]->total : 0;
            $diferencial += ($ventas - $meta);
        }
        return $diferencial;
    }

    /**
     * Resuelve el pool de comisión trimestral de una tienda de Pereira, encadenando
     * el déficit no cubierto de un trimestre hacia el siguiente:
     * - poolBruto  = Σ(ventas_mes − meta_mes) de los 3 meses / 1.19 × 5%
     * - poolNeto   = poolBruto − deficit arrastrado del trimestre anterior
     * - Si poolNeto >= 0: se paga poolNeto y el déficit se limpia.
     * - Si poolNeto <  0: no se paga nada este trimestre y el faltante pasa al siguiente.
     * Devuelve un mapa "tiendaId_trimestre" => [pool_bruto, pool_pagado, deficit_inicial, deficit_final]
     * para todos los trimestres entre la línea base y el trimestre actual.
     */
    /**
     * El pool de cada trimestre de Pereira y Circunvalar, encadenando el déficit.
     *
     * Un trimestre ya liquidado no se vuelve a calcular: se lee tal como quedó
     * (`cerrado_at`). Antes se recalculaba la cadena entera en cada consulta,
     * así que corregirle el precio a una orden vieja movía el déficit de un
     * trimestre ya pagado y, de rebote, lo que se debía en el siguiente.
     *
     * Tampoco guarda nada: consultar comisiones no debe escribir. Lo persiste
     * `recalcular()`, que es la acción que sí existe para eso.
     */
    private function cargarPoolsTrimestrales($metas, $totalesTienda, bool $persistir = false): array
    {
        $tiendaIds = DB::table('tiendas')->whereIn('nombre', self::TIENDAS_TRIMESTRALES)->pluck('id');
        $trimestreActual = self::trimestreDeMes(Carbon::now(StatsController::TZ_NEGOCIO)->format('Y-m'));

        $guardados = DB::table('tienda_trimestres')->get()
            ->keyBy(fn ($r) => $r->tienda_id . '_' . $r->trimestre);

        $pools = [];

        foreach ($tiendaIds as $tiendaId) {
            $trimestre     = self::TRIMESTRE_BASE;
            $deficitPrevio = 0.0;

            while (true) {
                $clave    = $tiendaId . '_' . $trimestre;
                $guardado = $guardados[$clave] ?? null;

                if ($guardado && $guardado->cerrado_at) {
                    // Ya se liquidó: rige lo que se usó para pagar.
                    $info = [
                        'pool_bruto'      => (float) $guardado->pool_bruto,
                        'pool_pagado'     => (float) $guardado->pool_pagado,
                        'deficit_inicial' => (float) $guardado->deficit_inicial,
                        'deficit_final'   => (float) $guardado->deficit_final,
                        'cerrado'         => true,
                    ];
                } else {
                    $diferencial  = $this->diferencialTrimestre((int) $tiendaId, $trimestre, $metas, $totalesTienda);
                    $poolBruto    = $diferencial / self::IVA * self::PORCENTAJE_DIRECTO;
                    $poolNeto     = $poolBruto - $deficitPrevio;
                    $info = [
                        'pool_bruto'      => $poolBruto,
                        'pool_pagado'     => max(0, $poolNeto),
                        'deficit_inicial' => $deficitPrevio,
                        'deficit_final'   => $poolNeto < 0 ? abs($poolNeto) : 0.0,
                        'cerrado'         => false,
                    ];

                    if ($persistir) {
                        DB::table('tienda_trimestres')->updateOrInsert(
                            ['tienda_id' => $tiendaId, 'trimestre' => $trimestre],
                            [
                                'deficit_inicial' => $info['deficit_inicial'],
                                'pool_bruto'      => $info['pool_bruto'],
                                'pool_pagado'     => $info['pool_pagado'],
                                'deficit_final'   => $info['deficit_final'],
                                'created_at'      => now(),
                                'updated_at'      => now(),
                            ]
                        );
                    }
                }

                $pools[$clave] = $info;

                if ($trimestre === $trimestreActual) break;
                $deficitPrevio = $info['deficit_final'];
                $trimestre     = self::trimestreSiguiente($trimestre);
            }
        }

        return $pools;
    }

    /**
     * Deja el trimestre quieto: lo que se usó para pagar es lo que rige.
     *
     * Se llama al marcar pagada una comisión de Pereira o Circunvalar. A
     * partir de ahí, tocar una orden de esos meses ya no le mueve el déficit
     * ni a ese trimestre ni al siguiente.
     */
    private function cerrarTrimestre(Comision $c, array $pools): void
    {
        if (! self::esTiendaTrimestral($c->tienda?->nombre)) return;

        $trimestre = self::trimestreDeMes($c->mes_venta);
        $clave     = $c->tienda_id . '_' . $trimestre;
        $info      = $pools[$clave] ?? null;

        if (! $info || ! empty($info['cerrado'])) return;

        DB::table('tienda_trimestres')->updateOrInsert(
            ['tienda_id' => $c->tienda_id, 'trimestre' => $trimestre],
            [
                'deficit_inicial' => $info['deficit_inicial'],
                'pool_bruto'      => $info['pool_bruto'],
                'pool_pagado'     => $info['pool_pagado'],
                'deficit_final'   => $info['deficit_final'],
                'cerrado_at'      => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]
        );
    }

    /**
     * Fecha en que la comisión queda disponible para pago:
     * - Tiendas mensuales: día 20 del mes siguiente a la venta.
     * - Tiendas trimestrales (Pereira): día 20 del mes siguiente al cierre
     *   del trimestre calendario (mar/jun/sep/dic) en que cae la venta.
     */
    private static function calcularFechaDisponible(Carbon $fechaVenta, int $tiendaId): string
    {
        $tiendaNombre = DB::table('tiendas')->where('id', $tiendaId)->value('nombre');

        if (self::esTiendaTrimestral($tiendaNombre)) {
            $mesCierre = intdiv($fechaVenta->month - 1, 3) * 3 + 3; // 3, 6, 9 o 12
            return Carbon::create($fechaVenta->year, $mesCierre, 1)
                ->addMonth()->day(20)->toDateString();
        }

        return Carbon::create($fechaVenta->year, $fechaVenta->month, 1)
            ->addMonth()->day(20)->toDateString();
    }

    /**
     * Carga las tres tablas de lookup necesarias para el cálculo:
     * - metas       : keyed por "tienda_id_mes"
     * - totalesTienda: total de ventas de TODA la tienda por mes (pool de comisión)
     * - totalesVendedor: total de ventas por vendedor+tienda+mes (para distribución proporcional)
     */
    /**
     * Lo que los independientes le abonaron a cada tienda, por mes.
     *
     * Se cuenta la mitad de la venta, que es lo que le toca a la tienda. La
     * otra mitad ya esta en comisiones, a nombre del vendedor.
     */
    /**
     * Lo que los independientes le abonaron a cada tienda.
     *
     * Delega en el servicio para que la meta y lo que se ve en pantalla no
     * puedan volver a desacoplarse: estaban calculados por separado y la
     * pantalla descontaba las restauraciones pero la meta no.
     */
    public static function abonadoPorTienda(): array
    {
        return \App\Services\ComisionIndependientes::abonadoParaMeta();
    }

    /** Se llena en cargarTotales() y se descarta al empezar el siguiente cálculo. */
    private ?array $idsRestauracion = null;

    /**
     * Órdenes cuyos ítems son todos restauración.
     *
     * Se resuelve sola. Antes se pasaba a enriquecer() como parámetro y tres
     * veces se olvidó enhebrarla: dos closures se quedaron sin el `use` (500 en
     * recalcular y en pagar-listas) y marcarPagada la calculaba sin usarla, con
     * lo que una restauración se liquidaba como si fuera venta.
     *
     * No se puede memoizar por instancia ni en un `static`: Laravel guarda el
     * controlador dentro del objeto Route, que vive todo el proceso, así que
     * una restauración creada después seguiría viendo la lista vieja. Por eso
     * la caché la invalida cargarTotales(), que es justo lo que corre al
     * empezar cada cálculo.
     */
    private function idsDeRestauracion(): array
    {
        return $this->idsRestauracion ??= DB::table('orden_items')->groupBy('orden_id')
            ->havingRaw('COUNT(*) = SUM(es_restauracion)')
            ->pluck('orden_id')->map(fn ($v) => (int) $v)->flip()->all();
    }

    /**
     * ¿Esta persona cobra por fuera del pool de una tienda?
     *
     * Lo es quien no pertenece a ninguna tienda con meta ese mes: los
     * independientes, y los de Vía Jardines y Tienda Virtual, que son casi
     * todos supervisores. Ahí no hay meta, así que no hay pool que repartir y
     * cada uno cobra sobre lo suyo. Antes esto se decidía con la tienda de la
     * orden, y un supervisor que escogiera una tienda con meta se llevaba una
     * parte de un pool ajeno.
     */
    private function cobraIndividual(int $vendedorId, string $mes): bool
    {
        // Por mes, y se descarta en cargarTotales() por lo mismo que la lista de
        // restauraciones: el controlador sobrevive al request.
        if (! isset($this->individuales[$mes])) {
            $conMeta = array_keys(MetaTienda::vigentesEn($mes));
            $this->individuales[$mes] = DB::table('usuarios')
                ->where(fn ($q) => $q->whereNull('tienda_default_id')
                                     ->orWhereNotIn('tienda_default_id', $conMeta ?: [0]))
                ->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
        }

        return isset($this->individuales[$mes][$vendedorId]);
    }

    /** Quién cobra por fuera del pool, por mes. Ver cobraIndividual(). */
    private array $individuales = [];

    /**
     * Lo que lleva vendido una tienda contra su meta, por mes.
     *
     * Es la MISMA cuenta que usa la pantalla de comisiones, y existe para que
     * nadie la vuelva a escribir por su lado. Las estadísticas del vendedor la
     * hacían con un `SUM(valor_orden)` pelado y contaban cuatro cosas que no
     * van: las ventas de un independiente, las restauraciones, las órdenes
     * canceladas y el 5% que un independiente le deja al almacén. A Gladys y a
     * Sebastián les inflaba la meta en $1.300.000.
     *
     * @return array<string,float>  ['tienda_mes' => vendido]
     */
    public static function ventasParaMeta(): array
    {
        $c = app(self::class);
        [, $totales, ] = $c->cargarTotales();

        return collect($totales)->map(fn ($r) => (float) $r->total)->all();
    }

    private function cargarTotales(): array
    {
        // Arranca un cálculo nuevo: lo de la pasada anterior ya no sirve.
        $this->idsRestauracion = null;
        $this->individuales    = [];

        // Los meses que hay que resolver salen de las comisiones que existen;
        // para cada uno rige la ultima meta cargada hasta ese mes.
        $meses = DB::table('comisiones')->distinct()->pluck('mes_venta')
            ->merge(DB::table('metas_tienda')->distinct()->pluck('mes'))
            ->merge([now()->format('Y-m')])
            ->unique()->values()->all();
        // Las tiendas trimestrales miran los tres meses del trimestre, que
        // pueden no tener comisiones todavia. Sin esto, un mes sin ventas
        // contaria meta 0 y el diferencial saldria inflado.
        foreach ($meses as $m) {
            foreach (self::mesesDeTrimestre(self::trimestreDeMes($m)) as $mm) $meses[] = $mm;
        }
        $meses = array_values(array_unique($meses));
        $metas = MetaTienda::mapaVigente($meses);

        // Las ventas de un independiente NO alimentan la meta de ninguna
        // tienda por esta via: ellos cobran por su porcentaje fijo, no del pool.
        // Lo unico que le llega a una tienda es la mitad que le abonen, que se
        // suma abajo. Sin esto, un independiente cuya orden llevara el
        // tienda_id de una tienda real le sumaba dos veces.
        $totalesTienda = DB::table('comisiones as c')
            ->join('usuarios as u', 'u.id', '=', 'c.vendedor_id')
            ->join('ordenes as o', 'o.id', '=', 'c.orden_id')
            ->where('u.independiente', false)
            // El 5% que deja un independiente no es una venta de la tienda:
            // paga a su gente pero no le empuja la meta ni le arma pool.
            ->where('c.origen', '!=', self::ORIGEN_ABONO)
            // Solo cuentan las ventas vivas. Una orden cancelada dejaba su
            // comision atras y su valor seguia empujando la meta de la tienda.
            ->whereNotIn('o.estado', self::ESTADOS_SIN_VENTA)
            // Una restauracion no es una venta de mueble: no le suma a la meta.
            ->whereNotIn('c.orden_id', function ($q) {
                $q->from('orden_items')->select('orden_id')
                  ->groupBy('orden_id')->havingRaw('COUNT(*) = SUM(es_restauracion)');
            })
            ->selectRaw('c.tienda_id, c.mes_venta, SUM(c.valor_orden) as total')
            ->groupBy('c.tienda_id', 'c.mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->tienda_id . '_' . $r->mes_venta);

        // Y lo que le abonaron los independientes: la mitad de cada venta que
        // se cerro con un contacto de esa tienda. No sale de comisiones porque
        // ahi la fila es del vendedor, no de la tienda que ayudo.
        foreach (self::abonadoPorTienda() as $clave => $monto) {
            if (isset($totalesTienda[$clave])) {
                $totalesTienda[$clave]->total = (float) $totalesTienda[$clave]->total + $monto;
            } else {
                [$tid, $mes] = explode('_', $clave, 2);
                $totalesTienda[$clave] = (object) [
                    'tienda_id' => (int) $tid, 'mes_venta' => $mes, 'total' => $monto,
                ];
            }
        }

        $totalesVendedor = DB::table('comisiones as c')
            ->join('ordenes as o', 'o.id', '=', 'c.orden_id')
            ->whereNotIn('o.estado', self::ESTADOS_SIN_VENTA)
            ->where('c.origen', '!=', self::ORIGEN_ABONO)
            ->selectRaw('c.vendedor_id, c.tienda_id, c.mes_venta, SUM(c.valor_orden) as total')
            ->groupBy('c.vendedor_id', 'c.tienda_id', 'c.mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->vendedor_id . '_' . $r->tienda_id . '_' . $r->mes_venta);

        return [$metas, $totalesTienda, $totalesVendedor];
    }

    private function enriquecer(Comision $c, $metas, $totalesTienda, $totalesVendedor, $poolsTrimestrales, Carbon $hoy): array
    {
        $idsRestauracion = $this->idsDeRestauracion();

        $metaKey = $c->tienda_id . '_' . $c->mes_venta;
        $meta    = isset($metas[$metaKey]) ? (float) $metas[$metaKey]->meta    : 0;
        $divisor = isset($metas[$metaKey]) ? (int)   $metas[$metaKey]->divisor_asesores : 1;

        // Total de ventas de toda la tienda en el mes (pool de comisión)
        $tiendaKey   = $c->tienda_id . '_' . $c->mes_venta;
        $totalTienda = isset($totalesTienda[$tiendaKey]) ? (float) $totalesTienda[$tiendaKey]->total : 0;

        // Total de ventas del vendedor en esa tienda ese mes (para distribución proporcional)
        $vendedorKey   = $c->vendedor_id . '_' . $c->tienda_id . '_' . $c->mes_venta;
        $totalVendedor = isset($totalesVendedor[$vendedorKey])
            ? (float) $totalesVendedor[$vendedorKey]->total
            : (float) $c->valor_orden;

        $esTrimestral   = self::esTiendaTrimestral($c->tienda?->nombre);
        $deficitInicial = 0.0;
        $deficitFinal   = 0.0;

        if ($esTrimestral) {
            $trimestre = self::trimestreDeMes($c->mes_venta);
            $infoPool  = $poolsTrimestrales[$c->tienda_id . '_' . $trimestre] ?? null;

            if ($infoPool) {
                // Trimestre dentro de la línea base: pool con arrastre de déficit.
                $comisionPool   = $infoPool['pool_pagado'];
                $metaCumplida   = $infoPool['pool_bruto'] > 0;
                $deficitInicial = $infoPool['deficit_inicial'];
                $deficitFinal   = $infoPool['deficit_final'];
            } else {
                // Trimestre anterior a la línea base: sin arrastre, se floorea en 0.
                $diferencial  = $this->diferencialTrimestre($c->tienda_id, $trimestre, $metas, $totalesTienda);
                $comisionPool = max(0, $diferencial / 1.19 * 0.05);
                $metaCumplida = $diferencial > 0;
            }
        } else {
            // La meta se compara contra el total de la tienda (no del vendedor)
            $metaCumplida = $meta > 0 && $totalTienda >= $meta;

            // Pool de comisión de la tienda = (ventas_tienda - meta) / 1.19 × 5%
            $comisionPool = $metaCumplida ? ($totalTienda - $meta) / 1.19 * 0.05 : 0;
        }

        // Comisión de cada asesor = pool / divisor
        $comisionAsesor = $divisor > 0 ? $comisionPool / $divisor : $comisionPool;

        // Cómo se paga depende de qué se vendió y de si su tienda tiene meta:
        //
        //   restauración        -> 5% del valor, solo para quien la hizo. No
        //                          pasa por el pool ni depende de la meta.
        //   venta sin meta      -> valor / 1,19 x 5%, igual que un independiente
        //   venta con meta      -> su parte del pool, prorrateada
        //
        // "Su tienda" es la de la persona, no la de la orden. Un supervisor
        // puede escoger la tienda al crear una orden, y si escogía una con meta
        // se llevaba una parte de ese pool sin pertenecer al equipo. Quien no
        // está en una tienda con meta cobra individual, caiga donde caiga la
        // orden. Al revés no: nadie pasa a cobrar por pool por dónde vendió.
        $esRestauracion = isset($idsRestauracion[(int) $c->orden_id]);
        $tieneMeta      = $meta > 0 && ! $this->cobraIndividual((int) $c->vendedor_id, $c->mes_venta);

        // Su parte del 5% que dejó un independiente al compartir con su tienda.
        // Nunca por el pool: esa venta no es del equipo, solo pasaron el
        // contacto. Se cobra el 5% de lo que le tocó, con la misma regla de
        // siempre (a la venta se le quita el IVA, a la restauración no).
        $esAbono = $c->origen === self::ORIGEN_ABONO;
        // No vendió nada, pero es del equipo: le toca su parte igual, entera.
        // No se prorratea por ventas suyas porque no tiene ninguna — ese
        // prorrateo daría cero, que es justo lo que pasaba antes.
        $esPartePool = $c->origen === self::ORIGEN_PARTE_POOL;

        if ($esPartePool) {
            $montoComision = round($comisionAsesor);
        } elseif ($esAbono) {
            $montoComision = $esRestauracion
                ? round((float) $c->valor_orden * self::PORCENTAJE_DIRECTO)
                : round((float) $c->valor_orden / self::IVA * self::PORCENTAJE_DIRECTO);
        } elseif ($esRestauracion) {
            $montoComision = round((float) $c->valor_orden * self::PORCENTAJE_DIRECTO);
        } elseif (! $tieneMeta) {
            $montoComision = round((float) $c->valor_orden / self::IVA * self::PORCENTAJE_DIRECTO);
        } else {
            // La comisión de esta orden es proporcional a su valor dentro del total del vendedor
            $montoComision = ($totalVendedor > 0 && $comisionAsesor > 0)
                ? round($comisionAsesor * ((float) $c->valor_orden / $totalVendedor))
                : 0;
        }

        $pagado    = $c->orden?->pagos?->sum('monto') ?? 0;
        // Cuánto de esta orden entró por datáfono. Sale de los pagos que ya
        // vienen cargados, no de una consulta nueva: en el resumen serían
        // decenas de consultas para pintar una línea.
        $pagadoTarjeta = (float) ($c->orden?->pagos?->where('metodo', 'tarjeta')->sum('monto') ?? 0);
        // El 50% cobrado es un requisito de la ORDEN: hasta que el cliente no
        // ha abonado la mitad, esa venta no paga comisión. Una parte de pool no
        // tiene orden detrás —sale de lo que vendió el equipo, que ya cumplió
        // ese filtro cada una por su lado—, así que no le aplica.
        $req50     = $esPartePool || $pagado >= ((float) $c->valor_orden * 0.5);
        $reqVencio = $hoy->gte(Carbon::parse($c->fecha_disponible));

        // En tiendas trimestrales el déficit ya quedó neteado en $comisionPool
        // (puede resultar en $0 sin que eso deba dejar la orden pendiente para siempre).
        $estadoCalculado = 'pendiente';
        if ($c->estado === 'pagada') {
            $estadoCalculado = 'pagada';
        } elseif ($req50 && $reqVencio && ($esAbono || $esPartePool || $esRestauracion || ! $tieneMeta || $esTrimestral || $metaCumplida)) {
            $estadoCalculado = 'lista';
        }

        $fechaDisp     = Carbon::parse($c->fecha_disponible);
        $diasRestantes = $hoy->diffInDays($fechaDisp, false);
        $atrasada      = $estadoCalculado === 'lista' && $diasRestantes < 0;

        return array_merge($c->toArray(), [
            'monto_comision'   => $montoComision,
            'total_tienda_mes' => $totalTienda,
            'total_vendedor_mes' => $totalVendedor,
            'meta_tienda'      => $meta,
            'divisor_asesores' => $divisor,
            'comision_pool'    => round($comisionPool),
            'comision_asesor'  => round($comisionAsesor),
            'meta_cumplida'    => $metaCumplida,
            'req_50_pct'       => $req50,
            'req_mes_vencido'  => $reqVencio,
            'periodicidad'     => $esTrimestral ? 'trimestral' : 'mensual',
            'es_restauracion'  => $esRestauracion,
            'forma_pago'       => $esPartePool ? self::ORIGEN_PARTE_POOL
                                  : ($esAbono ? self::ORIGEN_ABONO
                                  : ($esRestauracion ? 'restauracion_5'
                                  : (! $tieneMeta ? 'sin_meta_5' : 'pool'))),
            'abono_de_almacen' => $esAbono,
            'trimestre'        => $esTrimestral ? self::trimestreDeMes($c->mes_venta) : null,
            'avance_trimestre' => $esTrimestral
                ? $this->avanceTrimestre($c->tienda_id, self::trimestreDeMes($c->mes_venta), $metas, $totalesTienda)
                : null,
            'deficit_inicial'  => round($deficitInicial),
            'deficit_final'    => round($deficitFinal),
            'pct_pagado'       => $c->valor_orden > 0 ? round($pagado / (float) $c->valor_orden * 100) : 0,
            // Para poder rehacer la cuenta a mano: lo que entró por datáfono y
            // lo que se llevó la franquicia, que es plata que nunca llegó a la
            // caja y sobre la que nadie comisiona.
            'pagado_tarjeta'   => round($pagadoTarjeta),
            'costo_datafono'   => round($pagadoTarjeta * self::COSTO_TARJETA),
            'atrasada'         => $atrasada,
            'dias_restantes'   => (int) $diasRestantes,
            'estado_calculado' => $estadoCalculado,
            'vendedor_nombre'  => $c->vendedor?->nombre,
            'tienda_nombre'    => $c->tienda?->nombre,
            'orden_numero'     => $c->orden?->numero_orden,
            // Referencia visible: "#4261" o "FV2-3" en las órdenes con descuento
            // especial, que no tienen numero_orden.
            'orden_referencia' => $c->orden?->referencia,
            'es_descuento_especial' => (bool) $c->orden?->es_descuento_especial,
        ]);
    }

    private function notificarComisionLista(Comision $c, array $data): void
    {
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('acceso_comisiones', true)
            ->get();

        $titulo  = 'Comisión lista para pagar';
        $mensaje = "La comisión de {$data['vendedor_nombre']} por la orden {$data['orden_referencia']} está lista.";

        foreach ($supervisores as $sup) {
            NotificacionService::crear('comisiones', $titulo, $mensaje, ['comision_id' => $c->id], $sup->id);
        }
    }

    /**
     * GET /api/comisiones/independientes?mes=YYYY-MM
     *
     * Los independientes no van por meta ni por pool: cobran un porcentaje
     * fijo de todo lo que venden entre ellos. Se calcula aparte por eso.
     */
    public function independientes(Request $request)
    {
        // El mismo permiso que el resto de comisiones. Tenía un portillo para
        // cualquier supervisor, y cinco de ellos veían lo que ganan Flabio y
        // Henry sin tener el acceso: es plata de un compañero.
        if (! $request->user()->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes = $request->query('mes', Carbon::now(StatsController::TZ_NEGOCIO)->format('Y-m'));

        return response()->json(\App\Services\ComisionIndependientes::delMes($mes));
    }
}
