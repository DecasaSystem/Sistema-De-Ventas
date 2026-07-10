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
        $hoy = Carbon::today();

        $result = $comisiones->map(fn($c) => $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $hoy));

        return response()->json($result);
    }

    // GET /api/comisiones/vendedores
    public function vendedores(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $vendedores = Usuario::whereIn('rol', ['vendedor', 'supervisor'])
            ->where('activo', true)
            ->whereHas('comisiones')
            ->select('id', 'nombre', 'tienda_default_id')
            ->with('tiendaDefault:id,nombre')
            ->get()
            ->map(function ($v) {
                $counts = Comision::where('vendedor_id', $v->id)
                    ->selectRaw('estado, COUNT(*) as n')
                    ->groupBy('estado')
                    ->pluck('n', 'estado');
                return [
                    'id'        => $v->id,
                    'nombre'    => $v->nombre,
                    'tienda'    => $v->tiendaDefault?->nombre,
                    'pendiente' => (int) ($counts['pendiente'] ?? 0),
                    'lista'     => (int) ($counts['lista'] ?? 0),
                    'pagada'    => (int) ($counts['pagada'] ?? 0),
                ];
            });

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
        $enriquecida = $this->enriquecer($comision, $metas, $totalesTienda, $totalesVendedor, Carbon::today());

        if ($enriquecida['estado_calculado'] !== 'lista') {
            return response()->json(['error' => 'La comisión no está lista para pagar aún.'], 422);
        }

        $comision->update([
            'estado'          => 'pagada',
            'monto_comision'  => $enriquecida['monto_comision'],
            'fecha_pago'      => now(),
            'pagada_por'      => $usuario->id,
        ]);

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
        $metas   = MetaTienda::where('mes', $mes)->get()->keyBy('tienda_id');

        $asesoresAsignados = TiendaAsesor::with('vendedor:id,nombre')
            ->where('mes', $mes)
            ->get()
            ->groupBy('tienda_id');

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

        $comisiones = Comision::with(['orden.pagos', 'vendedor:id,nombre', 'tienda:id,nombre'])
            ->where('mes_venta', $mes)
            ->get();

        if ($comisiones->isEmpty()) {
            return response()->json([]);
        }

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $hoy = Carbon::today();

        $enriquecidas = $comisiones->map(fn($c) => $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $hoy));

        $grouped = $enriquecidas->groupBy('vendedor_id')->map(function ($items) {
            $first = $items->first();
            return [
                'vendedor_id'     => (int) $first['vendedor_id'],
                'vendedor_nombre' => $first['vendedor_nombre'],
                'tienda_id'       => (int) $first['tienda_id'],
                'tienda_nombre'   => $first['tienda_nombre'],
                'total_ordenes'   => $items->count(),
                'total_ventas'    => $items->sum(fn($i) => (float) $i['valor_orden']),
                'comision_total'  => $items->sum(fn($i) => (float) $i['monto_comision']),
                'comision_asesor' => (float) $first['comision_asesor'],
                'pendientes'      => $items->where('estado_calculado', 'pendiente')->count(),
                'listas'          => $items->where('estado_calculado', 'lista')->count(),
                'pagadas'         => $items->where('estado_calculado', 'pagada')->count(),
                'ordenes'         => $items->map(fn($i) => [
                    'id'             => $i['id'],
                    'orden_id'       => $i['orden_id'],
                    'orden_numero'   => $i['orden_numero'],
                    'valor_orden'    => (float) $i['valor_orden'],
                    'monto_comision' => (float) $i['monto_comision'],
                    'estado'         => $i['estado_calculado'],
                    'fecha_venta'    => $i['fecha_venta'],
                ])->values(),
            ];
        })->sortByDesc('comision_total')->values();

        return response()->json($grouped);
    }

    // GET /api/comisiones/asesores-asignados?mes=YYYY-MM
    public function getAsesoresAsignados(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes = $request->query('mes', Carbon::now()->format('Y-m'));

        $asignados = TiendaAsesor::with(['vendedor:id,nombre', 'tienda:id,nombre'])
            ->where('mes', $mes)
            ->get()
            ->map(fn($a) => [
                'id'              => $a->id,
                'tienda_id'       => $a->tienda_id,
                'tienda_nombre'   => $a->tienda?->nombre,
                'vendedor_id'     => $a->vendedor_id,
                'vendedor_nombre' => $a->vendedor?->nombre,
            ]);

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

        $asesor = TiendaAsesor::firstOrCreate([
            'tienda_id'   => $data['tienda_id'],
            'mes'         => $data['mes'],
            'vendedor_id' => $data['vendedor_id'],
        ]);

        $count = TiendaAsesor::where('tienda_id', $data['tienda_id'])
            ->where('mes', $data['mes'])
            ->count();

        // Only update divisor if a meta record already exists (avoid creating rows without meta)
        MetaTienda::where('tienda_id', $data['tienda_id'])
            ->where('mes', $data['mes'])
            ->update(['divisor_asesores' => $count]);

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
        $mes      = $asesor->mes;
        $asesor->delete();

        $count   = TiendaAsesor::where('tienda_id', $tiendaId)->where('mes', $mes)->count();
        $divisor = max(1, $count);
        // Only update if a meta record exists (don't create rows without meta)
        MetaTienda::where('tienda_id', $tiendaId)->where('mes', $mes)
            ->update(['divisor_asesores' => $divisor]);

        return response()->json(['divisor' => $divisor]);
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

    // POST /api/comisiones/recalcular
    public function recalcular(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        [$metas, $totalesTienda, $totalesVendedor] = $this->cargarTotales();
        $hoy          = Carbon::today();
        $actualizadas = 0;
        $notificadas  = 0;

        Comision::with('orden.pagos')->where('estado', '!=', 'pagada')
            ->chunk(100, function ($chunk) use ($metas, $totalesTienda, $totalesVendedor, $hoy, &$actualizadas, &$notificadas) {
                foreach ($chunk as $c) {
                    $enriquecida = $this->enriquecer($c, $metas, $totalesTienda, $totalesVendedor, $hoy);
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

        return response()->json(['actualizadas' => $actualizadas, 'notificadas' => $notificadas]);
    }

    // Llamar desde OrdenController al confirmar una orden
    public static function crearParaOrden(Orden $orden): void
    {
        if (! $orden->vendedor_id || ! $orden->tienda_id) return;

        $mes          = Carbon::parse($orden->created_at)->format('Y-m');
        $fechaVenta   = Carbon::parse($orden->created_at)->toDateString();
        // Disponible el último día del mes siguiente (ej: ventas julio → 31 agosto)
        $fechaDisp    = Carbon::parse($orden->created_at)->addMonth()->endOfMonth()->toDateString();

        $esCompartida  = (bool) $orden->es_compartida;
        $covendedorId  = $orden->covendedor_id;
        $valorPrincipal = $esCompartida ? round((float) $orden->valor_total / 2) : (float) $orden->valor_total;

        // Registro del vendedor principal
        Comision::firstOrCreate(
            ['orden_id' => $orden->id, 'vendedor_id' => $orden->vendedor_id],
            [
                'tienda_id'        => $orden->tienda_id,
                'mes_venta'        => $mes,
                'valor_orden'      => $valorPrincipal,
                'fecha_venta'      => $fechaVenta,
                'fecha_disponible' => $fechaDisp,
                'estado'           => 'pendiente',
            ]
        );

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
                        'fecha_venta'      => $fechaVenta,
                        'fecha_disponible' => $fechaDisp,
                        'estado'           => 'pendiente',
                    ]
                );
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Carga las tres tablas de lookup necesarias para el cálculo:
     * - metas       : keyed por "tienda_id_mes"
     * - totalesTienda: total de ventas de TODA la tienda por mes (pool de comisión)
     * - totalesVendedor: total de ventas por vendedor+tienda+mes (para distribución proporcional)
     */
    private function cargarTotales(): array
    {
        $metas = MetaTienda::all()->keyBy(fn($m) => $m->tienda_id . '_' . $m->mes);

        $totalesTienda = DB::table('comisiones')
            ->selectRaw('tienda_id, mes_venta, SUM(valor_orden) as total')
            ->groupBy('tienda_id', 'mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->tienda_id . '_' . $r->mes_venta);

        $totalesVendedor = DB::table('comisiones')
            ->selectRaw('vendedor_id, tienda_id, mes_venta, SUM(valor_orden) as total')
            ->groupBy('vendedor_id', 'tienda_id', 'mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->vendedor_id . '_' . $r->tienda_id . '_' . $r->mes_venta);

        return [$metas, $totalesTienda, $totalesVendedor];
    }

    private function enriquecer(Comision $c, $metas, $totalesTienda, $totalesVendedor, Carbon $hoy): array
    {
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

        // La meta se compara contra el total de la tienda (no del vendedor)
        $metaCumplida = $meta > 0 && $totalTienda >= $meta;

        // Pool de comisión de la tienda = (ventas_tienda - meta) / 1.19 × 5%
        $comisionPool = $metaCumplida ? ($totalTienda - $meta) / 1.19 * 0.05 : 0;

        // Comisión de cada asesor = pool / divisor
        $comisionAsesor = $divisor > 0 ? $comisionPool / $divisor : $comisionPool;

        // La comisión de esta orden es proporcional a su valor dentro del total del vendedor
        $montoComision = ($totalVendedor > 0 && $comisionAsesor > 0)
            ? round($comisionAsesor * ((float) $c->valor_orden / $totalVendedor))
            : 0;

        $pagado    = $c->orden?->pagos?->sum('monto') ?? 0;
        $req50     = $pagado >= ((float) $c->valor_orden * 0.5);
        $reqVencio = $hoy->gte(Carbon::parse($c->fecha_disponible));

        $estadoCalculado = 'pendiente';
        if ($c->estado === 'pagada') {
            $estadoCalculado = 'pagada';
        } elseif ($req50 && $metaCumplida && $reqVencio) {
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
            'pct_pagado'       => $c->valor_orden > 0 ? round($pagado / (float) $c->valor_orden * 100) : 0,
            'atrasada'         => $atrasada,
            'dias_restantes'   => (int) $diasRestantes,
            'estado_calculado' => $estadoCalculado,
            'vendedor_nombre'  => $c->vendedor?->nombre,
            'tienda_nombre'    => $c->tienda?->nombre,
            'orden_numero'     => $c->orden?->numero_orden,
        ]);
    }

    private function notificarComisionLista(Comision $c, array $data): void
    {
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('acceso_comisiones', true)
            ->get();

        $titulo  = 'Comisión lista para pagar';
        $mensaje = "La comisión de {$data['vendedor_nombre']} por la orden #{$data['orden_numero']} está lista.";

        foreach ($supervisores as $sup) {
            NotificacionService::crear('comisiones', $titulo, $mensaje, ['comision_id' => $c->id], $sup->id);
        }
    }
}
